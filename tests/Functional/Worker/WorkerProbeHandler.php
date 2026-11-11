<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\Worker;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use RuntimeException;

/**
 * 1.12.1 (REQ-RUNTIME-002): the handler of {@see WorkerProbeMessage}. It reads the workspace through the Api Studio
 * manager and records the name it sees; a successful message then flushes that manager (a write unit), a failing one
 * renames the workspace in memory and throws before its flush. What it read is kept in {@see self::$seen} for the test.
 */
final class WorkerProbeHandler
{
    /** @var list<string> the names read since the test last emptied it */
    public array $seen = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(WorkerProbeMessage $message): void
    {
        $workspace = $this->entityManager->find(ApiWorkspace::class, $message->workspaceId);
        if (!$workspace instanceof ApiWorkspace) {
            throw new RuntimeException('Unknown probe workspace.');
        }
        $this->seen[] = $workspace->getName();

        if ($message->fail) {
            $workspace->setName($message->dirtyName);

            throw new RuntimeException('Probe failure.');
        }

        $this->entityManager->flush();
    }
}
