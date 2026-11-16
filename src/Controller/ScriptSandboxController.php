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
 *
 * The same policy (minus `frame-ancestors`, which a meta tag cannot carry) is also the first thing in the document's
 * `<head>`: host middleware may strip or replace the header (the dev web profiler removes CSP headers, a host CSP
 * listener may overwrite them), and a meta policy applies to everything parsed after it, whatever the headers say.
 * DNS prefetching is off as well. The frame runtime removes the network APIs from the frame on top of that.
 *
 * The scripts themselves do not run in this document: CSP has no navigation directive and `allow-scripts` lets a
 * document navigate itself (a way out with data in the URL, audit RW-M1). The frame runtime starts a dedicated Worker
 * from a `blob:` URL (`worker-src blob:`, the only addition to the policy); the worker inherits this policy, has no
 * network and nothing to navigate, and runs the scripts. 'unsafe-eval' is there for the worker (`new Function`).
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

        $policy = sprintf("default-src 'none'; script-src 'nonce-%s' 'unsafe-eval'; worker-src blob:; base-uri 'none'; form-action 'none'", $nonce);
        $html   = sprintf(
            '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="%s"><meta http-equiv="x-dns-prefetch-control" content="off"><title>Api Studio script sandbox</title></head><body><script nonce="%s" src="%s"></script></body></html>',
            htmlspecialchars($policy, ENT_QUOTES),
            htmlspecialchars($nonce, ENT_QUOTES),
            htmlspecialchars($script, ENT_QUOTES),
        );

        return new Response($html, Response::HTTP_OK, [
            'Content-Type'            => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => $policy . "; frame-ancestors 'self'",
            'X-DNS-Prefetch-Control'  => 'off',
            'X-Content-Type-Options'  => 'nosniff',
            'Referrer-Policy'         => 'no-referrer',
            'Cache-Control'           => 'no-store',
        ]);
    }
}
