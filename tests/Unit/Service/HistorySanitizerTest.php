<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service;

use Nowo\ApiStudioBundle\Service\HistorySanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

use const ENT_HTML401;
use const ENT_HTML5;
use const ENT_QUOTES;

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

    /**
     * 1.24 (audit ZCL-L1).
     *
     * @return iterable<string, array{string, string}>
     */
    public static function userinfoAndFragments(): iterable
    {
        yield 'token as user name' => ['https://ghp_tokenvalue@github.example/org/repo', 'https://[REDACTED]@github.example/org/repo'];
        yield 'password with @' => ['https://u:p@ss@host.example/', 'https://u:[REDACTED]@host.example/'];
        yield 'user and empty password' => ['https://u:@host.example/', 'https://u:[REDACTED]@host.example/'];
        yield '@ in the path is not userinfo' => ['https://host.example/users/@me?x=1', 'https://host.example/users/@me?x=1'];
        yield 'fragment token' => ['https://app.example/cb#access_token=tok123&state=s1&token_type=bearer', 'https://app.example/cb#access_token=[REDACTED]&state=s1&token_type=[REDACTED]'];
        yield 'fragment and query' => ['https://app.example/cb?code=c1&sig=s#id_token=x&page=2', 'https://app.example/cb?code=c1&sig=[REDACTED]#id_token=[REDACTED]&page=2'];
        yield 'plain fragment' => ['https://app.example/docs#section-2', 'https://app.example/docs#section-2'];
    }

    #[DataProvider('userinfoAndFragments')]
    public function testSanitizeUrlMasksUserinfoAndFragmentTokens(string $url, string $expected): void
    {
        self::assertSame($expected, (new HistorySanitizer())->sanitizeUrl($url));
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

    public function testHtmlEscapedAndBase64FormsAreMasked(): void
    {
        $sanitizer = new HistorySanitizer();
        $secret    = 'REDACTED-TEST<&"\'>?';

        self::assertSame('<p>[REDACTED]</p>', $sanitizer->maskValues('<p>' . htmlspecialchars($secret, ENT_QUOTES | ENT_HTML5) . '</p>', [$secret]));
        self::assertSame('<p>[REDACTED]</p>', $sanitizer->maskValues('<p>' . htmlspecialchars($secret, ENT_QUOTES | ENT_HTML401) . '</p>', [$secret]));
        self::assertSame('{"t":"[REDACTED]"}', $sanitizer->maskValues('{"t":"' . base64_encode($secret) . '"}', [$secret]));
        self::assertSame('t=[REDACTED]', $sanitizer->maskValues('t=' . rtrim(strtr(base64_encode($secret), '+/', '-_'), '='), [$secret]));
    }

    public function testSanitizeUrlWithoutQueryIsUnchanged(): void
    {
        self::assertSame('https://api.test/v1', (new HistorySanitizer())->sanitizeUrl('https://api.test/v1'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathSecrets(): iterable
    {
        yield 'space, plus and slash' => ['ab c+d/12'];
        yield 'non-ASCII, plus and slash' => ['abé+cd/12'];
        yield 'percent-encoded unreserved' => ['ab%41cd12'];
        yield 'quote and angle brackets' => ['ab"c<d>12'];
        yield 'plain' => ['abcd1234'];
    }

    #[DataProvider('pathSecrets')]
    public function testSecretInAPathIsMaskedAsTheHttpClientEncodesIt(string $secret): void
    {
        // QE-D-L3: in a path the client percent-encodes some characters (space, non-ASCII) and keeps others (+, /).
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'boom']));
        $url    = '';
        try {
            $response = $client->request('GET', 'https://93.184.215.14/p/' . $secret . '/x');
            $url      = (string) $response->getInfo('url');
            $response->getContent();
        } catch (TransportExceptionInterface) {
        }
        $message = 'Failed for "' . $url . '"';

        $masked = (string) (new HistorySanitizer())->maskValues($message, [$secret]);

        self::assertStringContainsString('[REDACTED]', $masked, $url);
        self::assertStringEndsWith('/x"', $masked);
        self::assertSame('Failed for "https://93.184.215.14/p/[REDACTED]/x"', $masked);
    }
}
