<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function is_numeric;
use function sprintf;
use function strlen;

/**
 * Reads a response body with a byte budget (`execution_max_response_bytes`): a remote server chooses the size of
 * what it sends, so the console, the SOAP transport and the WSDL loader never buffer more than the budget (the
 * transfer is cancelled as soon as it is exceeded). Stateless.
 */
final class ResponseBodyReader
{
    /** Default budget: 10 MiB. */
    public const DEFAULT_MAX_BYTES = 10_485_760;

    /**
     * @throws RuntimeException when the body (announced or received) exceeds $maxBytes
     */
    public static function read(HttpClientInterface $client, ResponseInterface $response, int $maxBytes): string
    {
        $length = $response->getHeaders(false)['content-length'][0] ?? null;
        if (is_numeric($length) && (int) $length > $maxBytes) {
            $response->cancel();

            throw self::tooLarge($maxBytes);
        }

        $body = '';
        foreach ($client->stream($response) as $chunk) {
            $body .= $chunk->getContent();
            if (strlen($body) > $maxBytes) {
                $response->cancel();

                throw self::tooLarge($maxBytes);
            }
        }

        return $body;
    }

    private static function tooLarge(int $maxBytes): RuntimeException
    {
        return new RuntimeException(sprintf('The response is larger than %d bytes (nowo_api_studio.execution_max_response_bytes); the transfer was stopped.', $maxBytes));
    }
}
