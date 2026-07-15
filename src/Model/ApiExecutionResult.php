<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Model;

/**
 * Result of executing an API request from the tester.
 */
final class ApiExecutionResult
{
    /**
     * @param array<string, string> $requestHeaders
     * @param array<string, string> $responseHeaders
     */
    public function __construct(
        public readonly string $requestUrl,
        public readonly string $requestMethod,
        public readonly array $requestHeaders,
        public readonly ?string $requestBody,
        public readonly ?int $responseStatus,
        public readonly array $responseHeaders,
        public readonly ?string $responseBody,
        public readonly int $durationMs,
        public readonly bool $success,
        public readonly ?string $errorMessage = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'request_url'      => $this->requestUrl,
            'request_method'   => $this->requestMethod,
            'request_headers'  => $this->requestHeaders,
            'request_body'     => $this->requestBody,
            'response_status'  => $this->responseStatus,
            'response_headers' => $this->responseHeaders,
            'response_body'    => $this->responseBody,
            'duration_ms'      => $this->durationMs,
            'success'          => $this->success,
            'error_message'    => $this->errorMessage,
        ];
    }
}
