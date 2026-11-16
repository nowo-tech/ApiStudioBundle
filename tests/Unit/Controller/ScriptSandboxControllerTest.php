<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Controller;

use Nowo\ApiStudioBundle\Controller\ScriptSandboxController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function htmlspecialchars;
use function preg_match;

use const ENT_QUOTES;

/**
 * Spec 003 (1.15): the sandboxed script frame document and its own CSP.
 */
final class ScriptSandboxControllerTest extends TestCase
{
    public function testOnlyServedInSandboxMode(): void
    {
        $this->expectException(NotFoundHttpException::class);

        (new ScriptSandboxController('inline'))();
    }

    public function testFrameDocumentHasItsOwnCspWithoutNetwork(): void
    {
        $packages = $this->createMock(Packages::class);
        $packages->method('getUrl')->with('api-script-sandbox.js', 'nowo_api_studio')->willReturn('/bundles/nowoapistudio/api-script-sandbox.js?v=1');

        $response = (new ScriptSandboxController('sandbox', $packages))();
        $csp      = (string) $response->headers->get('Content-Security-Policy');

        self::assertSame(200, $response->getStatusCode());
        self::assertMatchesRegularExpression("/^default-src 'none'; script-src 'nonce-([A-Za-z0-9+\\/=]+)' 'unsafe-eval'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'$/", $csp);
        self::assertSame(1, preg_match("/'nonce-([^']+)'/", $csp, $nonce));
        self::assertStringContainsString('<script nonce="' . $nonce[1] . '" src="/bundles/nowoapistudio/api-script-sandbox.js?v=1"></script>', (string) $response->getContent());
        // The policy is also the first thing in <head>, so stripping or replacing the header does not lift it.
        $meta = "default-src 'none'; script-src 'nonce-" . $nonce[1] . "' 'unsafe-eval'; base-uri 'none'; form-action 'none'";
        self::assertStringStartsWith('<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="' . htmlspecialchars($meta, ENT_QUOTES) . '"><meta http-equiv="x-dns-prefetch-control" content="off"><title>', (string) $response->getContent());
        self::assertSame('off', $response->headers->get('X-DNS-Prefetch-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));

        $again = (new ScriptSandboxController('sandbox', $packages))();
        self::assertNotSame($csp, $again->headers->get('Content-Security-Policy'), 'a fresh nonce per response');
    }

    public function testDefaultAssetPathWithoutPackages(): void
    {
        $response = (new ScriptSandboxController('sandbox'))();

        self::assertStringContainsString('src="/bundles/nowoapistudio/api-script-sandbox.js"', (string) $response->getContent());
    }
}
