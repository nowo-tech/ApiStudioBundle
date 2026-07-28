<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service;

use Nowo\ApiStudioBundle\Service\HistorySanitizer;
use PHPUnit\Framework\TestCase;

final class HistorySanitizerTest extends TestCase
{
    public function testSanitizeHeadersRedactsSensitiveNames(): void
    {
        $sanitizer = new HistorySanitizer();

        $out = $sanitizer->sanitizeHeaders([
            'Authorization' => 'Bearer secret-token',
            'X-Api-Key'     => 'abc123',
            'Content-Type'  => 'application/json',
        ]);

        self::assertSame('[REDACTED]', $out['Authorization']);
        self::assertSame('[REDACTED]', $out['X-Api-Key']);
        self::assertSame('application/json', $out['Content-Type']);
    }

    public function testSanitizeBodyRedactsTokensAndPasswords(): void
    {
        $sanitizer = new HistorySanitizer();

        $body = '{"password":"hunter2","token":"xyz","note":"ok"} Authorization: Bearer abc.def';
        $out  = $sanitizer->sanitizeBody($body);

        self::assertNotNull($out);
        self::assertStringContainsString('"password":"[REDACTED]"', $out);
        self::assertStringContainsString('"token":"[REDACTED]"', $out);
        self::assertStringContainsString('Bearer [REDACTED]', $out);
        self::assertStringContainsString('"note":"ok"', $out);
    }

    public function testSanitizeBodyLeavesEmptyUnchanged(): void
    {
        $sanitizer = new HistorySanitizer();

        self::assertNull($sanitizer->sanitizeBody(null));
        self::assertSame('', $sanitizer->sanitizeBody(''));
    }
}
