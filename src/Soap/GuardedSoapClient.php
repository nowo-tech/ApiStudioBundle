<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Soap;

use Nowo\ApiStudioBundle\Security\ExecutionUrlValidator;
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
 * @internal
 */
final class GuardedSoapClient extends SoapClient
{
    /**
     * @param string|null $wsdl local WSDL file prepared by {@see GuardedWsdlLoader} (null: non-WSDL mode)
     * @param array<string, mixed> $options SoapClient options
     */
    public function __construct(
        ?string $wsdl,
        array $options,
        private readonly HttpClientInterface $httpClient,
        private readonly ExecutionUrlValidator $urlValidator,
        private readonly int $timeoutSeconds,
        private readonly int $maxRedirects,
    ) {
        parent::__construct($wsdl, $options);
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

        $headers = $version === SOAP_1_2
            ? ['Content-Type' => 'application/soap+xml; charset=utf-8; action="' . $action . '"']
            : ['Content-Type' => 'text/xml; charset=utf-8', 'SOAPAction' => '"' . $action . '"'];

        $response = $this->httpClient->request('POST', $location, [
            'headers'       => $headers,
            'body'          => $request,
            'timeout'       => $this->timeoutSeconds,
            'max_duration'  => $this->timeoutSeconds,
            'max_redirects' => $this->maxRedirects,
        ]);

        // SOAP faults come back with HTTP 500 and an envelope: hand the body to ext-soap whatever the status.
        $content = $response->getContent(false);

        return $oneWay ? null : $content;
    }
}
