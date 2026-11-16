<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Nowo\ApiStudioBundle\Twig\ApiStudioExtension;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

use function base64_encode;
use function htmlspecialchars;
use function random_bytes;
use function sprintf;

use const ENT_QUOTES;

/**
 * The document of the sandboxed script frame (spec 003, `scripts.mode: sandbox`, 1.15; 404 in the other modes).
 *
 * The console loads it in `<iframe sandbox="allow-scripts">` (no `allow-same-origin`: an opaque origin without the
 * page's DOM, cookies or storage). Its own CSP allows only the frame runtime (by nonce) and `'unsafe-eval'` for the
 * scripts, and no network at all (`default-src 'none'`): `fetch`, XHR, WebSocket, images and forms are refused, and
 * only the studio may embed it (`frame-ancestors 'self'`). Stateless (worker-safe).
 */
final class ScriptSandboxController
{
    public function __construct(
        private readonly string $scriptsMode = ApiStudioExtension::SCRIPTS_INLINE,
        private readonly ?Packages $packages = null,
    ) {
    }

    #[Route(path: '/scripts/sandbox', name: 'nowo_api_studio_script_sandbox', methods: ['GET'])]
    public function __invoke(): Response
    {
        if ($this->scriptsMode !== ApiStudioExtension::SCRIPTS_SANDBOX) {
            throw new NotFoundHttpException('The script sandbox is not enabled.');
        }

        $nonce  = base64_encode(random_bytes(18));
        $script = $this->packages instanceof Packages
            ? $this->packages->getUrl('api-script-sandbox.js', 'nowo_api_studio')
            : '/bundles/nowoapistudio/api-script-sandbox.js';

        $html = sprintf(
            '<!doctype html><html><head><meta charset="utf-8"><title>Api Studio script sandbox</title></head><body><script nonce="%s" src="%s"></script></body></html>',
            htmlspecialchars($nonce, ENT_QUOTES),
            htmlspecialchars($script, ENT_QUOTES),
        );

        return new Response($html, Response::HTTP_OK, [
            'Content-Type'            => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => sprintf("default-src 'none'; script-src 'nonce-%s' 'unsafe-eval'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'", $nonce),
            'X-Content-Type-Options'  => 'nosniff',
            'Referrer-Policy'         => 'no-referrer',
            'Cache-Control'           => 'no-store',
        ]);
    }
}
