<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Secret;

use Nowo\ApiStudioBundle\Secret\SecretRedactingLogger;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryLogger;
use PHPUnit\Framework\TestCase;

use function rawurlencode;

final class SecretRedactingLoggerTest extends TestCase
{
    public function testMasksMessageAndNestedContextInEveryForm(): void
    {
        $value  = 'hv/REDACTED TEST';
        $inner  = new InMemoryLogger();
        $logger = new SecretRedactingLogger($inner, [$value, 'ab']);

        $logger->error('failed for https://x.test/?k=' . rawurlencode($value), [
            'error'  => 'quoted "' . $value . '"',
            'nested' => ['deep' => $value, 'n' => 3],
            'short'  => 'ab stays',
        ]);

        self::assertCount(1, $inner->records);
        $text = $inner->text();
        self::assertStringNotContainsString($value, $text);
        self::assertStringNotContainsString(rawurlencode($value), $text);
        self::assertStringContainsString('[REDACTED]', $inner->records[0]['message']);
        self::assertSame(3, $inner->records[0]['context']['nested']['n']);
        self::assertSame('ab stays', $inner->records[0]['context']['short'], 'values under 4 bytes are never masked (refused upstream)');
        self::assertSame('error', $inner->records[0]['level']);
    }
}
