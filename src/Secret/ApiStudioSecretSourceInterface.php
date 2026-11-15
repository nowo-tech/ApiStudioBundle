<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Secret;

/**
 * Host contract (`nowo_api_studio.secrets.source`, 1.13): resolves a host secret that a secret environment variable
 * references (`{{secret:<source>/<id>}}`, e.g. `{{secret:vault/payments-stripe}}`) when a request is executed.
 *
 * Called on the server, once per referenced variable and per execution (never memoised by the bundle), after the
 * bundle granted `workspace.run` on the workspace. The host decides whether the actor of {@see ApiStudioSecretRef}
 * may use that secret in that workspace (and tenant). The value is sent to the request target only: the bundle masks it
 * in the response it returns to the browser, in the stored history, in its log records, in error messages and in the
 * executed event, and never stores it.
 *
 * Return null when the secret is unknown or not allowed: the request is then not sent if it needs that variable (fail
 * closed), and the console shows which variable could not be resolved. A thrown exception is treated as null (its
 * message is never logged or shown). Values shorter than 4 bytes are refused (too short to mask safely).
 *
 * Framework-free: no Doctrine, Symfony or HTTP types (ARCH-001 rule 3).
 */
interface ApiStudioSecretSourceInterface
{
    public function resolve(ApiStudioSecretRef $ref): ?ApiStudioSecretValue;
}
