<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Security;

use Nowo\ApiStudioBundle\Security\ExecutionUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ExecutionUrlValidatorTest extends TestCase
{
    #[DataProvider('blockedUrlProvider')]
    public function testBlocksSsrfTargets(string $url): void
    {
        $validator = new ExecutionUrlValidator([]);

        self::assertTrue($validator->isBlockedForSsrf($url));
        self::assertTrue($validator->isBlocked($url));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function blockedUrlProvider(): iterable
    {
        yield 'localhost' => ['http://localhost/admin'];
        yield '127.0.0.1' => ['http://127.0.0.1/'];
        yield 'private 10.x' => ['http://10.0.0.1/'];
        yield 'private 192.168.x' => ['http://192.168.0.1/'];
        yield 'link-local' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'empty host' => ['http:///path'];
        yield 'empty bracket host' => ['http://[]/'];
        yield 'metadata hostname' => ['http://metadata.google.internal/'];
        yield 'ipv6 loopback' => ['http://[::1]/'];
        yield 'ipv6 link-local' => ['http://[fe80::1]/'];
    }

    public function testAllowlistRequiresMatchWhenConfigured(): void
    {
        $validator = new ExecutionUrlValidator(['api.example.com']);

        self::assertFalse($validator->isBlocked('https://api.example.com/v1/users'));
        self::assertTrue($validator->isBlocked('https://other.example.com/v1/users'));
    }

    public function testRegexAllowlist(): void
    {
        $validator = new ExecutionUrlValidator(['#^https://staging\\.example\\.com/#']);

        self::assertFalse($validator->isBlocked('https://staging.example.com/test'));
        self::assertTrue($validator->isBlocked('https://prod.example.com/test'));
    }

    public function testEmptyAllowlistAllowsPublicUrl(): void
    {
        $validator = new ExecutionUrlValidator([]);

        self::assertTrue($validator->isAllowedByAllowlist('https://api.example.com/v1'));
    }

    public function testLogsInvalidRegexPattern(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $invalidPattern = '#[unclosed';
        $validator      = new ExecutionUrlValidator([$invalidPattern, 'api.example.com'], $logger);

        self::assertFalse($validator->isBlocked('https://api.example.com/v1'));
    }

    public function testPublicHostnameIsAllowedForSsrfCheck(): void
    {
        $validator = new ExecutionUrlValidator([]);

        self::assertFalse($validator->isBlockedForSsrf('https://example.com/'));
    }

    public function testPublicIpv6IsAllowedForSsrfCheck(): void
    {
        $validator = new ExecutionUrlValidator([]);

        self::assertFalse($validator->isBlockedForSsrf('https://[2001:db8::1]/'));
    }

    public function testSkipsEmptyAllowlistPattern(): void
    {
        $validator = new ExecutionUrlValidator(['', 'api.example.com']);

        self::assertFalse($validator->isBlocked('https://api.example.com/v1'));
    }
}
