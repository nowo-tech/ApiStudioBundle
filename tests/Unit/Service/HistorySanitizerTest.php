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

    public function testSanitizeHeadersRedactsExtraNames(): void
    {
        $out = (new HistorySanitizer())->sanitizeHeaders(['X-Partner-Credential' => 'abc', 'Accept' => '*/*'], ['x-partner-credential']);

        self::assertSame('[REDACTED]', $out['X-Partner-Credential']);
        self::assertSame('*/*', $out['Accept']);
    }

    public function testSanitizeUrlRedactsSensitiveQueryValues(): void
    {
        $out = (new HistorySanitizer())->sanitizeUrl(
            'https://user:pa55@api.test/v1/items?api_key=abc&access_token=xyz&Signature=s1&filter[secret]=x&page=2&author=bob&flag#frag',
        );

        self::assertSame(
            'https://user:[REDACTED]@api.test/v1/items?api_key=[REDACTED]&access_token=[REDACTED]&Signature=[REDACTED]&filter[secret]=x&page=2&author=bob&flag#frag',
            $out,
        );
    }

    public function testSanitizeUrlRedactsExtraNamesAndSecretValues(): void
    {
        $out = (new HistorySanitizer())->sanitizeUrl(
            'https://api.test/v1/x/s3cr3t-value?X-Partner-Credential=k1&q=hello%20s3cr3t-value&q2=a+b',
            ['x-partner-credential'],
            ['s3cr3t-value', 'abc'],
        );

        self::assertSame('https://api.test/v1/x/[REDACTED]?X-Partner-Credential=[REDACTED]&q=hello%20[REDACTED]&q2=a+b', $out);
    }

    public function testSanitizeHeadersMasksSecretValuesInOtherHeaders(): void
    {
        $out = (new HistorySanitizer())->sanitizeHeaders(
            ['X-Custom' => 'prefix s3cr3t/value', 'X-Encoded' => 's3cr3t%2Fvalue', 'Accept' => 'abc', 'Authorization' => 'Bearer x'],
            [],
            ['s3cr3t/value', 'abc'],
        );

        self::assertSame('prefix [REDACTED]', $out['X-Custom']);
        self::assertSame('[REDACTED]', $out['X-Encoded']);
        self::assertSame('abc', $out['Accept'], 'Values shorter than 4 characters are not masked.');
        self::assertSame('[REDACTED]', $out['Authorization']);
    }

    public function testSanitizeBodyMasksSecretValuesInAnyForm(): void
    {
        $sanitizer = new HistorySanitizer();

        self::assertSame(
            '{"client":"[REDACTED]","note":"x"}',
            $sanitizer->sanitizeBody('{"client":"p\\/w\\"d","note":"x"}', ['p/w"d']),
        );
        self::assertSame('a=[REDACTED]&b=[REDACTED]', $sanitizer->sanitizeBody('a=p%2Fw%22d&b=p/w"d', ['p/w"d']));
        self::assertSame('<ns:key>[REDACTED]</ns:key>', $sanitizer->sanitizeBody('<ns:key>tok-987654</ns:key>', ['tok-987654']));
    }

    public function testSanitizeUrlWithoutQueryIsUnchanged(): void
    {
        self::assertSame('https://api.test/v1', (new HistorySanitizer())->sanitizeUrl('https://api.test/v1'));
    }
}
