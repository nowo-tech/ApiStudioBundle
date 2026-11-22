<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\IdOnlyHost;

use Doctrine\DBAL\Connection;

use function is_scalar;

/**
 * 1.19 (B10): the host's own grant table, on the host's connection (never the bundle's), keyed by ids only:
 * tenant key, opaque actor id, workspace id. Stateless (every call reads the database).
 */
final readonly class HostGrantStore
{
    public const TABLE = 'host_api_studio_grant';

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function createTable(): void
    {
        $this->connection->executeStatement('CREATE TABLE ' . self::TABLE . ' (tenant_key VARCHAR(100) NOT NULL, actor_id VARCHAR(64) NOT NULL, workspace_id INTEGER NOT NULL, PRIMARY KEY (tenant_key, actor_id, workspace_id))');
    }

    public function grant(string $tenantKey, string $actorId, int $workspaceId): void
    {
        $this->connection->insert(self::TABLE, ['tenant_key' => $tenantKey, 'actor_id' => $actorId, 'workspace_id' => $workspaceId]);
    }

    public function has(string $tenantKey, string $actorId, int $workspaceId): bool
    {
        $row = $this->connection->fetchAssociative('SELECT tenant_key, actor_id, workspace_id FROM ' . self::TABLE . ' WHERE tenant_key = ? AND actor_id = ? AND workspace_id = ?', [$tenantKey, $actorId, $workspaceId]);

        // Exact keys: compared again in PHP.
        return $row !== false && $row['tenant_key'] === $tenantKey && $row['actor_id'] === $actorId && (int) $row['workspace_id'] === $workspaceId;
    }

    /**
     * @return list<int>
     */
    public function workspaceIds(string $tenantKey, string $actorId): array
    {
        $ids = [];
        foreach ($this->connection->fetchAllAssociative('SELECT tenant_key, actor_id, workspace_id FROM ' . self::TABLE . ' WHERE tenant_key = ? AND actor_id = ?', [$tenantKey, $actorId]) as $row) {
            if ($row['tenant_key'] === $tenantKey && $row['actor_id'] === $actorId && is_scalar($row['workspace_id'])) {
                $ids[] = (int) $row['workspace_id'];
            }
        }

        return $ids;
    }
}
