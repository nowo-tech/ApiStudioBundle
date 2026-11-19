<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration\Tenancy;

use Doctrine\ORM\EntityManager;
use Nowo\ApiStudioBundle\Command\SecretsStatusCommand;
use Nowo\ApiStudioBundle\Command\TenantMoveCommand;
use Nowo\ApiStudioBundle\Command\TenantUnsuffixSlugsCommand;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Security\SecretValueCipher;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantContext;
use Nowo\ApiStudioBundle\Tests\Support\TenantOrmTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Audit UA2-2: the tenant commands re-check the tenant exactly in PHP, so on a database that still compares tenant ids
 * case-insensitively (MySQL / MariaDB before the 1.17 collation migration; SQLite NOCASE here) "--tenant-id=acme"
 * never touches tenant "ACME".
 */
final class TenantCommandsLookAlikeTest extends TestCase
{
    use TenantOrmTrait;

    public function testUnsuffixSlugsLeavesTheLookAlikeTenantAlone(): void
    {
        $em     = $this->createTenantEntityManager(true);
        $theirs = $this->tenantWorkspace($em, 'ACME', 'payments-2');
        $this->tenantWorkspace($em, 'acme', 'orders-2');
        $tester = new CommandTester(new TenantUnsuffixSlugsCommand($this->tenantRegistry($em), new ApiStudioTenantContext(null, true)));

        self::assertSame(Command::SUCCESS, $tester->execute(['--tenant-type' => 'org', '--tenant-id' => 'acme']));
        $em->clear();
        self::assertSame('payments-2', $this->slug($em, (int) $theirs->getId()));
        self::assertStringNotContainsString('payments', $tester->getDisplay());
    }

    public function testMoveDoesNotCountALookAlikeTenantsSlug(): void
    {
        $em = $this->createTenantEntityManager(true);
        $this->tenantWorkspace($em, 'ACME', 'payments');
        $lost   = $this->tenantWorkspace($em, '', 'payments');
        $tester = new CommandTester(new TenantMoveCommand($this->tenantRegistry($em), new ApiStudioTenantContext(null, true), new EventDispatcher()));

        // "ACME" has "payments", "acme" has not: the check passes (the database's own key still decides the write).
        self::assertSame(Command::SUCCESS, $tester->execute(['workspace' => (string) $lost->getId(), '--to-type' => 'org', '--to-id' => 'acme', '--dry-run' => true]));
        self::assertStringContainsString('Dry run', $tester->getDisplay());
    }

    public function testMoveOntoALookAlikeTenantsSlugFailsFriendlyWhenTheKeyRefuses(): void
    {
        $em = $this->createTenantEntityManager(true);
        $this->tenantWorkspace($em, 'ACME', 'payments');
        $lost   = $this->tenantWorkspace($em, '', 'payments');
        $tester = new CommandTester(new TenantMoveCommand($this->tenantRegistry($em), new ApiStudioTenantContext(null, true), new EventDispatcher()));

        // The exact check passes, then the case-insensitive unique key refuses the UPDATE (audit UA3-2).
        self::assertSame(Command::FAILURE, $tester->execute(['workspace' => (string) $lost->getId(), '--to-type' => 'org', '--to-id' => 'acme']));
        self::assertStringContainsString('rename one of them first', $tester->getDisplay());
    }

    public function testSecretsStatusCountsOnlyTheExactTenant(): void
    {
        $em     = $this->createTenantEntityManager(true);
        $cipher = new SecretValueCipher('placeholder-key-material');
        $this->secret($em, 'acme', 'a', $cipher->encrypt('ok'));
        $this->secret($em, 'ACME', 'b', 'nowo_as_enc_v1:corrupt');
        $tester = new CommandTester(new SecretsStatusCommand($this->tenantRegistry($em), $cipher, new ApiStudioTenantContext(null, true)));

        self::assertSame(Command::SUCCESS, $tester->execute(['--format' => 'json', '--tenant' => 'org:acme']));
        self::assertSame(['plain' => 0, 'encrypted' => 1, 'unreadable' => 0, 'unreadable_ids' => []], json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
    }

    private function secret(EntityManager $em, string $tenant, string $key, string $stored): void
    {
        $workspace   = $this->tenantWorkspace($em, $tenant, 'ws-' . $key);
        $environment = new ApiEnvironment('E', 'e');
        $workspace->addEnvironment($environment);
        $variable = (new ApiEnvironmentVariable($key, $stored))->setSecret(true);
        $environment->addVariable($variable);
        $em->persist($environment);
        $em->persist($variable);
        $em->flush();
    }

    private function slug(EntityManager $em, int $id): string
    {
        $workspace = $em->find(ApiWorkspace::class, $id);
        self::assertInstanceOf(ApiWorkspace::class, $workspace);

        return $workspace->getSlug();
    }
}
