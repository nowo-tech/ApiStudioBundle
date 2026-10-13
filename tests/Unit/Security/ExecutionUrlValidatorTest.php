<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Security;

use Closure;
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
        yield 'private 172.16.x' => ['http://172.16.5.5/'];
        yield 'private 192.168.x' => ['http://192.168.0.1/'];
        yield 'link-local' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'empty host' => ['http:///path'];
        yield 'empty bracket host' => ['http://[]/'];
        yield 'metadata hostname' => ['http://metadata.google.internal/'];
        yield 'ipv6 loopback' => ['http://[::1]/'];
        yield 'ipv6 link-local' => ['http://[fe80::1]/'];
        yield 'this-network 0.0.0.0/8' => ['http://0.0.0.0:6379/'];
        yield 'CGNAT 100.64/10' => ['http://100.64.0.1/'];
        yield 'ipv6 ULA fc00::/7' => ['http://[fd00::1]/'];
        yield 'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/'];
        yield 'ipv4-mapped hex loopback' => ['http://[::ffff:7f00:1]/'];
        yield 'ipv6 unspecified' => ['http://[::]/'];
        yield 'multicast' => ['http://224.0.0.1/'];
        yield 'broadcast' => ['http://255.255.255.255/'];
        yield 'localhost trailing dot' => ['http://localhost./'];
        yield 'localhost subdomain' => ['http://admin.localhost/'];
        yield 'metadata uppercase' => ['http://METADATA.google.internal/'];
    }

    public function testBlocksHostResolvingToPrivateAddress(): void
    {
        $validator = new ExecutionUrlValidator([], null, static fn (string $host): array => ['93.184.215.14', '10.0.0.7']);

        self::assertTrue($validator->isBlockedForSsrf('https://rebind.example.net/'));
    }

    public function testBlocksAaaaOnlyHostResolvingToLoopback(): void
    {
        $validator = new ExecutionUrlValidator([], null, static fn (string $host): array => ['::1']);

        self::assertTrue($validator->isBlockedForSsrf('https://v6only.example.net/'));
    }

    public function testFailsClosedWhenHostDoesNotResolve(): void
    {
        $validator = new ExecutionUrlValidator([], null, static fn (string $host): array => []);

        self::assertTrue($validator->isBlockedForSsrf('https://unresolvable.example.net/'));
    }

    public function testBlocksWhenResolverReturnsGarbage(): void
    {
        $validator = new ExecutionUrlValidator([], null, static fn (string $host): array => ['not-an-ip']);

        self::assertTrue($validator->isBlockedForSsrf('https://weird.example.net/'));
    }

    public function testDefaultResolverFailsClosedForUnresolvableHost(): void
    {
        $validator = new ExecutionUrlValidator([]);

        self::assertTrue($validator->isBlockedForSsrf('https://nonexistent.invalid/'));
    }

    public function testAllowlistRequiresMatchWhenConfigured(): void
    {
        $validator = new ExecutionUrlValidator(['api.example.com'], null, self::publicResolver());

        self::assertFalse($validator->isBlocked('https://api.example.com/v1/users'));
        self::assertTrue($validator->isBlocked('https://other.example.com/v1/users'));
    }

    #[DataProvider('allowlistBypassProvider')]
    public function testPlainAllowlistEntryDoesNotMatchSubstrings(string $url): void
    {
        $validator = new ExecutionUrlValidator(['api.example.com'], null, self::publicResolver());

        self::assertFalse($validator->isAllowedByAllowlist($url));
        self::assertTrue($validator->isBlocked($url));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function allowlistBypassProvider(): iterable
    {
        yield 'suffix host' => ['https://api.example.com.evil.net/'];
        yield 'query string' => ['https://evil.net/?x=api.example.com'];
        yield 'path' => ['https://evil.net/api.example.com/'];
        yield 'userinfo' => ['https://api.example.com@evil.net/'];
        yield 'prefix host' => ['https://evilapi.example.com/'];
        yield 'no host' => ['api.example.com'];
    }

    public function testAllowlistHostMatchIsCaseInsensitiveAndIgnoresTrailingDot(): void
    {
        $validator = new ExecutionUrlValidator(['API.Example.com'], null, self::publicResolver());

        self::assertTrue($validator->isAllowedByAllowlist('https://api.EXAMPLE.com./v1'));
    }

    public function testDotSuffixAllowlistMatchesApexAndSubdomains(): void
    {
        $validator = new ExecutionUrlValidator(['.example.com', '*.example.org'], null, self::publicResolver());

        self::assertTrue($validator->isAllowedByAllowlist('https://example.com/'));
        self::assertTrue($validator->isAllowedByAllowlist('https://a.b.example.com/'));
        self::assertTrue($validator->isAllowedByAllowlist('https://api.example.org/'));
        self::assertFalse($validator->isAllowedByAllowlist('https://example.com.evil.net/'));
        self::assertFalse($validator->isAllowedByAllowlist('https://evilexample.com/'));
    }

    public function testUrlAllowlistEntryPinsSchemePortAndPathPrefix(): void
    {
        $validator = new ExecutionUrlValidator(['https://api.example.com:8443/v1/'], null, self::publicResolver());

        self::assertTrue($validator->isAllowedByAllowlist('https://api.example.com:8443/v1'));
        self::assertTrue($validator->isAllowedByAllowlist('https://api.example.com:8443/v1/users'));
        self::assertFalse($validator->isAllowedByAllowlist('http://api.example.com:8443/v1/users'));
        self::assertFalse($validator->isAllowedByAllowlist('https://api.example.com/v1/users'));
        self::assertFalse($validator->isAllowedByAllowlist('https://api.example.com:8443/v10'));
        self::assertFalse($validator->isAllowedByAllowlist('https://api.example.com:8443/admin'));
    }

    public function testUrlAllowlistEntryWithoutPathAllowsAnyPath(): void
    {
        $validator = new ExecutionUrlValidator(['https://allowed.example.com'], null, self::publicResolver());

        self::assertTrue($validator->isAllowedByAllowlist('https://allowed.example.com/anything'));
        self::assertFalse($validator->isAllowedByAllowlist('https://allowed.example.com.evil.net/'));
    }

    public function testMalformedAllowlistEntryNeverMatches(): void
    {
        $validator = new ExecutionUrlValidator(['https://'], null, self::publicResolver());

        self::assertFalse($validator->isAllowedByAllowlist('https://api.example.com/'));
    }

    /**
     * @return Closure(string): list<string>
     */
    private static function publicResolver(): Closure
    {
        return static fn (string $host): array => ['93.184.215.14'];
    }

    public function testRegexAllowlist(): void
    {
        $validator = new ExecutionUrlValidator(['#^https://staging\\.example\\.com/#'], null, self::publicResolver());

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
        $validator      = new ExecutionUrlValidator([$invalidPattern, 'api.example.com'], $logger, self::publicResolver());

        self::assertFalse($validator->isBlocked('https://api.example.com/v1'));
    }

    public function testPublicHostnameIsAllowedForSsrfCheck(): void
    {
        $validator = new ExecutionUrlValidator([], null, self::publicResolver());

        self::assertFalse($validator->isBlockedForSsrf('https://example.com/'));
    }

    public function testPublicIpv6IsAllowedForSsrfCheck(): void
    {
        $validator = new ExecutionUrlValidator([]);

        self::assertFalse($validator->isBlockedForSsrf('https://[2606:4700:4700::1111]/'));
    }

    public function testSkipsEmptyAllowlistPattern(): void
    {
        $validator = new ExecutionUrlValidator(['', 'api.example.com'], null, self::publicResolver());

        self::assertFalse($validator->isBlocked('https://api.example.com/v1'));
    }
}
