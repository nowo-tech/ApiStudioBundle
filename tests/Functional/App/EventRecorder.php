<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\App;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Records the Api Studio events with whether the bundle's connection was inside a transaction when they fired.
 */
final class EventRecorder
{
    /** @var list<array{event: object, inTransaction: bool}> */
    public array $records = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(object $event): void
    {
        $this->records[] = ['event' => $event, 'inTransaction' => $this->entityManager->getConnection()->isTransactionActive()];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<array{event: T, inTransaction: bool}>
     */
    public function of(string $class): array
    {
        $records = [];
        foreach ($this->records as $record) {
            if ($record['event'] instanceof $class) {
                $records[] = ['event' => $record['event'], 'inTransaction' => $record['inTransaction']];
            }
        }

        return $records;
    }
}
