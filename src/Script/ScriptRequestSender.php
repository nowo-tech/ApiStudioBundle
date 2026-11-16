<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Script;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Security\ExecutionUrlValidator;
use Nowo\ApiStudioBundle\Service\ResponseBodyReader;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function count;
use function hrtime;
use function implode;
use function in_array;
use function is_array;
use function is_scalar;
use function is_string;
use function preg_match;
use function strlen;
use function strtoupper;

/**
 * `pm.sendRequest` of sandboxed scripts (spec 003, 1.15): sends exactly the request a script built, through the same
 * guards as the console (SSRF guard and `execution_url_allowlist` before the call, the connected IP and every redirect
 * hop re-checked by {@see NoPrivateNetworkHttpClient}, no redirects followed, timeout and response size limit).
 *
 * Nothing is resolved server-side: no environment variable, no stored secret, no host secret is substituted into the
 * request (a stored script run by another user must not be able to ship that user's secrets to a host of its choice),
 * and no cookie or session of the studio is sent. Stateless (worker-safe).
 */
final class ScriptRequestSender
{
    public const MAX_URL_LENGTH  = 2048;
    public const MAX_HEADERS     = 50;
    public const MAX_BODY_LENGTH = 1_048_576;

    private readonly HttpClientInterface $httpClient;

    public function __construct(
        HttpClientInterface $httpClient,
        private readonly ExecutionUrlValidator $urlValidator,
        private readonly int $timeoutSeconds = 30,
        private readonly int $maxResponseBytes = ResponseBodyReader::DEFAULT_MAX_BYTES,
    ) {
        $this->httpClient = $httpClient instanceof NoPrivateNetworkHttpClient
            ? $httpClient
            : new NoPrivateNetworkHttpClient($httpClient, ExecutionUrlValidator::BLOCKED_SUBNETS);
    }

    /**
     * @param array<string, mixed> $request `{url, method?, header?: {name: value}, body?}`
     *
     * @throws InvalidArgumentException when the request is malformed or its URL is not allowed
     *
     * @return array{code: int, headers: array<string, string>, body: string, responseTime: int}
     */
    public function send(array $request): array
    {
        $url    = $request['url'] ?? null;
        $method = strtoupper(is_string($request['method'] ?? null) ? $request['method'] : 'GET');
        if (!is_string($url) || $url === '' || strlen($url) > self::MAX_URL_LENGTH || preg_match('#^https?://#i', $url) !== 1) {
            throw new InvalidArgumentException('pm.sendRequest: an absolute http(s) URL is required.');
        }
        if (!in_array($method, HttpMethod::values(), true)) {
            throw new InvalidArgumentException('pm.sendRequest: unsupported method.');
        }

        $headers = [];
        $raw     = $request['header'] ?? [];
        if (!is_array($raw) || count($raw) > self::MAX_HEADERS) {
            throw new InvalidArgumentException('pm.sendRequest: too many headers.');
        }
        foreach ($raw as $name => $value) {
            if (!is_string($name) || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1 || !is_scalar($value)) {
                throw new InvalidArgumentException('pm.sendRequest: invalid header.');
            }
            $headers[$name] = (string) $value;
        }

        $body = $request['body'] ?? null;
        if ($body !== null && (!is_string($body) || strlen($body) > self::MAX_BODY_LENGTH)) {
            throw new InvalidArgumentException('pm.sendRequest: the body must be a string of at most 1 MiB.');
        }

        if ($this->urlValidator->isBlocked($url)) {
            throw new InvalidArgumentException('pm.sendRequest: request URL is not allowed (SSRF or allowlist).');
        }

        $options = ['headers' => $headers, 'timeout' => $this->timeoutSeconds, 'max_duration' => $this->timeoutSeconds, 'max_redirects' => 0];
        if ($body !== null && $body !== '' && !in_array($method, ['GET', 'HEAD'], true)) {
            $options['body'] = $body;
        }

        $start = hrtime(true);
        try {
            $response = $this->httpClient->request($method, $url, $options);
            $content  = ResponseBodyReader::read($this->httpClient, $response, $this->maxResponseBytes);
            $flat     = [];
            foreach ($response->getHeaders(false) as $name => $values) {
                $flat[$name] = implode(', ', $values);
            }

            return [
                'code'         => $response->getStatusCode(),
                'headers'      => $flat,
                'body'         => $content,
                'responseTime' => (int) ((hrtime(true) - $start) / 1_000_000),
            ];
        } catch (Throwable $e) {
            // The exception message may quote the URL: only its class reaches the script.
            throw new InvalidArgumentException('pm.sendRequest: the request failed (' . $e::class . ').', 0, $e);
        }
    }
}
