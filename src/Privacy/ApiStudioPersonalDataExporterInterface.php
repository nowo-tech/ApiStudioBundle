<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Privacy;

use InvalidArgumentException;

/**
 * GDPR export port (1.24, art. 15 / 20), the counterpart of {@see ApiStudioPersonalDataEraserInterface}: what Api
 * Studio stores about one actor, as metadata only (owner decision 2026-11-26).
 *
 * - History: endpoint id, HTTP method, request URL with secrets masked again (query values with secret-like names,
 *   userinfo passwords), response status and execution time. Never request / response bodies, headers or error
 *   messages: they may hold third-party personal data and secrets.
 * - Folders created by the actor: id, name, service id and creation time.
 *
 * The actor id compares byte-exact; rows are read in keyset pages. Read-only.
 */
interface ApiStudioPersonalDataExporterInterface
{
    /**
     * @throws InvalidArgumentException when the actor id is not 1 to 128 UTF-8 characters without control characters
     */
    public function export(string $actorId): ApiStudioPersonalDataExport;
}
