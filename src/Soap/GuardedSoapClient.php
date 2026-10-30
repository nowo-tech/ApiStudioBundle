<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Soap;

use Nowo\ApiStudioBundle\Security\ExecutionUrlValidator;
use Nowo\ApiStudioBundle\Service\ResponseBodyReader;
use SoapClient;
use SoapFault;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use const SOAP_1_2;

/**
 * SoapClient whose transport is the guarded Symfony HTTP client instead of ext-soap's own HTTP stack.
 *
 * The endpoint (`<soap:address location>` from the WSDL, or the `location` option) is checked against
 * {@see ExecutionUrlValidator} (SSRF + allowlist) before every call, and the request goes through the
 * executor's {@see NoPrivateNetworkHttpClient} with the configured timeout and
 * redirect policy, so DNS rebinding and redirect hops are checked as for REST calls.
 *
 * Extra HTTP headers (service auth, service/endpoint default headers, console headers) are sent with every call.
 * The SOAP framing headers (`Content-Type`, `SOAPAction`) are always generated from the envelope version and
 * operation: an extra header with one of those names is dropped, never sent twice or allowed to override them.
 *
 * @internal
 */
final class GuardedSoapClient extends SoapClient
{
    /** @var array<string, string> */
    private readonly array $extraHeaders;

    /**
     * @param string|null $wsdl local WSDL file prepared by {@see GuardedWsdlLoader} (null: non-WSDL mode)
     * @param array<string, mixed> $options SoapClient options
     * @param array<string, string> $extraHeaders HTTP headers (auth, service defaults) sent with the SOAP calls whose
     *                                            location has the origin $headersOrigin (framing headers are dropped)
     * @param string|null $headersOrigin origin of the service (WSDL) URL: a `soap:address` on another origin gets no
     *                                   credential (same rule as {@see GuardedWsdlLoader}); null = never send them
     */
    public function __construct(
        ?string $wsdl,
        array $options,
        private readonly HttpClientInterface $httpClient,
        private readonly ExecutionUrlValidator $urlValidator,
        private readonly int $timeoutSeconds,
        private readonly int $maxRedirects,
        array $extraHeaders = [],
        private readonly int $maxResponseBytes = ResponseBodyReader::DEFAULT_MAX_BYTES,
        private readonly ?string $headersOrigin = null,
    ) {
        $this->extraHeaders = self::withoutFramingHeaders($extraHeaders);
        parent::__construct($wsdl, $options);
    }

    /**
     * Removes `Content-Type` and `SOAPAction` (case-insensitive) and empty header names.
     *
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    public static function withoutFramingHeaders(array $headers): array
    {
        return SoapEnvelope::withoutFramingHeaders($headers);
    }

    /**
     * The optional $uriParserClass argument mirrors the PHP 8.5 signature and is unused.
     */
    public function __doRequest(
        string $request,
        string $location,
        string $action,
        int $version,
        bool $oneWay = false,
        ?string $uriParserClass = null,
    ): ?string {
        if ($this->urlValidator->isBlocked($location)) {
            throw new SoapFault('Client', 'SOAP endpoint location is not allowed (SSRF or allowlist).');
        }

        // Credentials only to the service origin: a WSDL may name any public host as soap:address.
        $extra   = $this->headersOrigin !== null && GuardedWsdlLoader::origin($location) === $this->headersOrigin ? $this->extraHeaders : [];
        $headers = $extra + SoapEnvelope::framingHeaders(
            $action,
            $version === SOAP_1_2 ? SoapEnvelope::VERSION_1_2 : SoapEnvelope::VERSION_1_1,
        );

        $response = $this->httpClient->request('POST', $location, [
            'headers'       => $headers,
            'body'          => $request,
            'timeout'       => $this->timeoutSeconds,
            'max_duration'  => $this->timeoutSeconds,
            'max_redirects' => $this->maxRedirects,
        ]);

        // A 3xx left here was not followed (execution_max_redirects exhausted or 0): fail instead of handing an
        // empty body to ext-soap, which would report a successful call with a null result.
        $status = $response->getStatusCode();
        if ($status >= 300 && $status < 400) {
            throw new SoapFault('HTTP', 'SOAP endpoint answered with a redirect (HTTP ' . $status . ') that was not followed.');
        }

        // SOAP faults come back with HTTP 500 and an envelope: hand the body to ext-soap whatever the status.
        $content = ResponseBodyReader::read($this->httpClient, $response, $this->maxResponseBytes);

        return $oneWay ? null : $content;
    }
}
