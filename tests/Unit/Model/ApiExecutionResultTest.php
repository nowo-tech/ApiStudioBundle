<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Model;

use Nowo\ApiStudioBundle\Model\ApiExecutionResult;
use PHPUnit\Framework\TestCase;

final class ApiExecutionResultTest extends TestCase
{
    public function testToArrayContainsExecutionDetails(): void
    {
        $result = new ApiExecutionResult(
            requestUrl: 'https://api.example.com/users',
            requestMethod: 'GET',
            requestHeaders: ['Accept' => 'application/json'],
            requestBody: null,
            responseStatus: 200,
            responseHeaders: ['Content-Type' => 'application/json'],
            responseBody: '{"ok":true}',
            durationMs: 42,
            success: true,
        );

        self::assertSame([
            'request_url'      => 'https://api.example.com/users',
            'request_method'   => 'GET',
            'request_headers'  => ['Accept' => 'application/json'],
            'request_body'     => null,
            'response_status'  => 200,
            'response_headers' => ['Content-Type' => 'application/json'],
            'response_body'    => '{"ok":true}',
            'duration_ms'      => 42,
            'success'          => true,
            'error_message'    => null,
        ], $result->toArray());
    }
}
