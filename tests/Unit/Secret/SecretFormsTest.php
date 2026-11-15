<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Secret;

use Nowo\ApiStudioBundle\Secret\RequestOrigin;
use Nowo\ApiStudioBundle\Secret\SecretForms;
use Nowo\ApiStudioBundle\Service\HistorySanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function htmlspecialchars;
use function json_encode;
use function rtrim;
use function strtr;
use function substr;

use const ENT_QUOTES;
use const JSON_HEX_AMP;
use const JSON_HEX_APOS;
use const JSON_HEX_QUOT;
use const JSON_HEX_TAG;

final class SecretFormsTest extends TestCase
{
    public function testEchoedEncodingsAreMasked(): void
    {
        // QE-B-L1: a target may echo the value base64-encoded (Basic auth too), as HTML entities or with \u escapes.
        $value = "hv'<&>" . 'REDACTED-TEST/é?';
        $forms = SecretForms::expand([$value], 'svc-user');
        $echo  = implode("\n", [
            base64_encode($value),
            rtrim(strtr(base64_encode($value), '+/', '-_'), '='),
            'Basic ' . base64_encode('svc-user:' . $value),
            htmlspecialchars($value, ENT_QUOTES),
            substr((string) json_encode($value), 1, -1),
            substr((string) json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), 1, -1),
        ]);

        $masked = (string) (new HistorySanitizer())->maskValues($echo, $forms);

        foreach (explode("\n", $echo) as $line) {
            self::assertStringNotContainsString($line === 'Basic ' . base64_encode('svc-user:' . $value) ? base64_encode('svc-user:' . $value) : $line, $masked);
        }
        self::assertContains($value, $forms);
    }

    public function testWithoutBasicAuthNoUserForm(): void
    {
        $forms = SecretForms::expand(['hv-REDACTED-TEST']);

        self::assertNotContains(base64_encode(':hv-REDACTED-TEST'), $forms);
        self::assertContains(base64_encode('hv-REDACTED-TEST'), $forms);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function origins(): iterable
    {
        yield 'https default port' => ['HTTPS://API.Example.test:443/v1?x=1', 'https://api.example.test'];
        yield 'http default port' => ['http://api.example.test:80', 'http://api.example.test'];
        yield 'explicit port' => ['https://api.example.test:8443/x', 'https://api.example.test:8443'];
        yield 'IDN to punycode, trailing dot' => ['https://Exämple.test./x', 'https://xn--exmple-cua.test'];
        yield 'only a dot as host' => ['https://./x', null];
        yield 'IDN the client would refuse' => ['https://ä_b.test/x', null];
        yield 'no scheme' => ['api.example.test/x', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('origins')]
    public function testOrigin(string $url, ?string $origin): void
    {
        self::assertSame($origin, RequestOrigin::of($url));
    }
}
