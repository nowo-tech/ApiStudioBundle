<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration\Tenancy;

use Doctrine\ORM\EntityManager;
use Nowo\ApiStudioBundle\Command\SecretsStatusCommand;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Security\SecretValueCipher;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantContext;
use Nowo\ApiStudioBundle\Tests\Support\TenantOrmTrait;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * EQ-I7: `secrets:status --tenant=type:id` reports one tenant's secrets only.
 */
final class TenantSecretsStatusTest extends TestCase
{
    use TenantOrmTrait;

    public function testTenantOption(): void
    {
        $em     = $this->createTenantEntityManager();
        $cipher = new SecretValueCipher('placeholder-key-material');
        $this->secret($em, 'acme', 'a', $cipher->encrypt('ok'));
        $this->secret($em, 'beta', 'b', 'nowo_as_enc_v1:corrupt');
        $this->secret($em, '', 'c', 'legacy-plain');
        $command = new SecretsStatusCommand($this->tenantRegistry($em), $cipher, new ApiStudioTenantContext(null, true));

        $tester = new CommandTester($command);
        self::assertSame(Command::FAILURE, $tester->execute(['--format' => 'json']), 'all tenants: beta is unreadable');
        self::assertSame(Command::SUCCESS, $tester->execute(['--format' => 'json', '--tenant' => 'org:acme']));
        self::assertSame(['plain' => 0, 'encrypted' => 1, 'unreadable' => 0, 'unreadable_ids' => []], json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
        $instance = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $instance->execute(['--format' => 'json', '--tenant' => '']));
        $data = json_decode($instance->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame(1, $data['plain'], 'the instance tenant');

        $this->expectException(RuntimeException::class);
        (new CommandTester(new SecretsStatusCommand($this->tenantRegistry($em), $cipher)))->execute(['--tenant' => 'org:acme']);
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
}
