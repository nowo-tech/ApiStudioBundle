<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Soap;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Nowo\ApiStudioBundle\Security\ExecutionUrlValidator;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function count;
use function file_put_contents;
use function is_file;
use function is_string;
use function parse_url;
use function preg_match;
use function str_starts_with;
use function strrpos;
use function strtolower;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

use const LIBXML_NONET;

/**
 * Downloads a WSDL (and the WSDL/XSD documents it imports) through the guarded HTTP client and stores them as
 * local temporary files, so ext-soap never fetches a remote document itself.
 *
 * Every document URL is checked against {@see ExecutionUrlValidator} (SSRF + allowlist), including the final
 * URL after redirects; import/include locations are rewritten to the local copies. Documents with a DTD are
 * rejected (no entity expansion / external entities).
 *
 * The configured HTTP headers (service auth, default/console headers) are sent only to documents on the same
 * origin (scheme, host, port) as the root WSDL, so credentials never leak to a third-party import location.
 *
 * @internal
 */
final class GuardedWsdlLoader
{
    private const MAX_DOCUMENTS = 20;

    /** @var array<string, string> URL => local temporary file */
    private array $files = [];

    private ?string $rootOrigin = null;

    /**
     * @param array<string, string> $headers HTTP headers sent with same-origin WSDL/XSD requests
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ExecutionUrlValidator $urlValidator,
        private readonly int $timeoutSeconds,
        private readonly int $maxRedirects,
        private readonly array $headers = [],
    ) {
    }

    /**
     * @return string path of the local copy of the root WSDL
     */
    public function load(string $url): string
    {
        // @igor-ignore per-call object created with `new` in RequestExecutor for one SOAP call (not a container service); state is cleaned up after the call
        $this->rootOrigin = self::origin($url);

        return $this->fetch($url);
    }

    /**
     * Deletes the temporary files created by {@see load()}.
     */
    public function cleanup(): void
    {
        foreach ($this->files as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        // @igor-ignore per-call object created with `new` in RequestExecutor for one SOAP call (not a container service); state is cleaned up after the call
        $this->files = [];
    }

    private function fetch(string $url): string
    {
        if (isset($this->files[$url])) {
            return $this->files[$url];
        }

        if (count($this->files) >= self::MAX_DOCUMENTS) {
            throw new RuntimeException('WSDL references too many documents.');
        }

        if ($this->urlValidator->isBlocked($url)) {
            throw new RuntimeException('WSDL document URL is not allowed (SSRF or allowlist).');
        }

        $path = tempnam(sys_get_temp_dir(), 'api_studio_wsdl_');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the WSDL.');
        }
        // Registered before recursing so that import cycles resolve to the same file.
        // @igor-ignore per-call object created with `new` in RequestExecutor for one SOAP call (not a container service); state is cleaned up after the call
        $this->files[$url] = $path;

        $options = [
            'timeout'       => $this->timeoutSeconds,
            'max_duration'  => $this->timeoutSeconds,
            'max_redirects' => $this->maxRedirects,
        ];
        if ($this->headers !== [] && $this->rootOrigin !== null && self::origin($url) === $this->rootOrigin) {
            $options['headers'] = $this->headers;
        }

        $response = $this->httpClient->request('GET', $url, $options);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Could not load WSDL document (HTTP ' . $status . ').');
        }

        $finalUrl = $response->getInfo('url');
        $finalUrl = is_string($finalUrl) && $finalUrl !== '' ? $finalUrl : $url;
        if ($finalUrl !== $url && $this->urlValidator->isBlocked($finalUrl)) {
            throw new RuntimeException('WSDL document URL is not allowed (SSRF or allowlist).');
        }

        $document = new DOMDocument();
        if ($document->loadXML($response->getContent(), LIBXML_NONET) === false) {
            throw new RuntimeException('WSDL document is not valid XML.');
        }

        if ($document->doctype !== null) {
            throw new RuntimeException('WSDL documents with a DTD are not supported.');
        }

        $imports = (new DOMXPath($document))->query(
            '//*[local-name()="import" or local-name()="include" or local-name()="redefine"][@location or @schemaLocation]',
        );
        // The XPath selects elements only; the instanceof check narrows the DOMNode type.
        foreach ($imports === false ? [] : $imports as $element) {
            if ($element instanceof DOMElement) {
                foreach (['location', 'schemaLocation'] as $attribute) {
                    $reference = trim($element->getAttribute($attribute));
                    if ($reference !== '') {
                        $element->setAttribute($attribute, $this->fetch(self::resolveUrl($finalUrl, $reference)));
                    }
                }
            }
        }

        if (file_put_contents($path, (string) $document->saveXML()) === false) {
            throw new RuntimeException('Could not write the WSDL to a temporary file.');
        }

        return $path;
    }

    /**
     * Lower-cased `scheme://host:port` (default port filled in), or null for URLs without scheme/host.
     */
    private static function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (!isset($parts['host'], $parts['scheme'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $port   = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
    }

    /**
     * Resolves an import location against the URL of the importing document. Non-HTTP schemes are returned
     * unchanged and then rejected by the URL validator.
     */
    private static function resolveUrl(string $base, string $reference): string
    {
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $reference) === 1) {
            return $reference;
        }

        $parts = parse_url($base);
        if (!isset($parts['host'], $parts['scheme'])) {
            return $reference;
        }

        // parse_url() keeps the brackets of IPv6 hosts.
        $scheme    = $parts['scheme'];
        $authority = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($reference, '//')) {
            return $scheme . ':' . $reference;
        }

        if (str_starts_with($reference, '/')) {
            return $scheme . '://' . $authority . $reference;
        }

        $path = $parts['path'] ?? '/';
        $dir  = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $scheme . '://' . $authority . ($dir === '' ? '/' : $dir) . $reference;
    }
}
