<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\ApiStudioBundle\Doctrine\TablePrefixSubscriber;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TablePrefixSubscriberTest extends TestCase
{
    public function testAppliesPrefixToTableAndUniqueConstraints(): void
    {
        $metadata = new ClassMetadata(ApiWorkspace::class);
        $metadata->setPrimaryTable([
            'name'              => 'workspace',
            'uniqueConstraints' => [
                'uniq_workspace_slug' => ['columns' => ['slug']],
            ],
        ]);

        $subscriber = new TablePrefixSubscriber('myapp_api_');
        $subscriber->loadClassMetadata(new LoadClassMetadataEventArgs(
            $metadata,
            $this->createMock(EntityManagerInterface::class),
        ));

        self::assertSame('myapp_api_workspace', $metadata->getTableName());
        /** @var array<string, mixed> $table */
        $table = $metadata->table;
        self::assertArrayHasKey('uniqueConstraints', $table);
        self::assertIsArray($table['uniqueConstraints']);
        self::assertArrayHasKey('myapp_api_uniq_workspace_slug', $table['uniqueConstraints']);
    }

    public function testIgnoresEntitiesOutsideBundleNamespace(): void
    {
        $metadata = new ClassMetadata(stdClass::class);
        $metadata->setPrimaryTable(['name' => 'other_table']);

        $subscriber = new TablePrefixSubscriber('prefix_');
        $subscriber->loadClassMetadata(new LoadClassMetadataEventArgs(
            $metadata,
            $this->createMock(EntityManagerInterface::class),
        ));

        self::assertSame('other_table', $metadata->getTableName());
    }
}
