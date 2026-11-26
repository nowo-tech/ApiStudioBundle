<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Support;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Privacy\ApiStudioErasureResult;
use Nowo\ApiStudioBundle\Privacy\ApiStudioPersonalDataEraserInterface;
use Nowo\ApiStudioBundle\Privacy\ApiStudioPersonalDataExport;
use Nowo\ApiStudioBundle\Privacy\ApiStudioPersonalDataExporterInterface;

/**
 * 1.24 GDPR port double: records the actor ids it is called with.
 */
final class RecordingPersonalDataPorts implements ApiStudioPersonalDataEraserInterface, ApiStudioPersonalDataExporterInterface
{
    /** @var list<string> */
    public array $erased = [];

    /** @var list<string> */
    public array $exported = [];

    /** An actor id both ports refuse with InvalidArgumentException (after recording it). */
    public ?string $refuse = null;

    public function erase(string $actorId): ApiStudioErasureResult
    {
        $this->erased[] = $actorId;
        if ($actorId === $this->refuse) {
            throw new InvalidArgumentException('refused');
        }

        return new ApiStudioErasureResult(1);
    }

    public function export(string $actorId): ApiStudioPersonalDataExport
    {
        $this->exported[] = $actorId;
        if ($actorId === $this->refuse) {
            throw new InvalidArgumentException('refused');
        }

        return new ApiStudioPersonalDataExport([self::row(1)]);
    }

    /**
     * @return array{id: int, endpoint_id: int, method: string, url: string, status: int|null, executed_at: string}
     */
    public static function row(int $id): array
    {
        return ['id' => $id, 'endpoint_id' => 2, 'method' => 'GET', 'url' => 'https://api.example.test/', 'status' => 200, 'executed_at' => '2026-11-26T10:00:00+00:00'];
    }
}
