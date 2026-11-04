<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration\Command;

use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Command\SecretsStatusCommand;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Security\SecretValueCipher;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryOrmTrait;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function in_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Read-only report of the stored secrets: plain, encrypted and unreadable (ids only, never values); exit 1 when any
 * secret cannot be decrypted.
 */
final class SecretsStatusCommandTest extends TestCase
{
    use InMemoryOrmTrait;

    private const PLACEHOLDER_KEY = 'placeholder-key-material';

    public function testEverySecretReadable(): void
    {
        $em     = $this->createEntityManager();
        $cipher = new SecretValueCipher(self::PLACEHOLDER_KEY);
        $this->store($em, ['a' => $cipher->encrypt('one'), 'b' => 'legacy-plain', 'c' => 'not secret'], ['c']);

        $tester = new CommandTester(new SecretsStatusCommand($this->createRegistry($em), $cipher));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Every secret variable can be decrypted.', $tester->getDisplay());
    }

    public function testUnreadableSecretsFailWithTheirIdsOnly(): void
    {
        $em     = $this->createEntityManager();
        $cipher = new SecretValueCipher(self::PLACEHOLDER_KEY);
        $ids    = $this->store($em, [
            'a' => $cipher->encrypt('one'),
            'b' => (new SecretValueCipher('another-placeholder-key'))->encrypt('two'),
            'c' => 'plain',
            // Wrapped a second time by a release before 1.10.1: decrypts to a payload no key opens.
            'd' => $cipher->encrypt((new SecretValueCipher('another-placeholder-key'))->encrypt('three')),
        ]);
        $command = new SecretsStatusCommand($this->createRegistry($em), $cipher);

        $tester = new CommandTester($command);
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString((string) $ids['b'], $tester->getDisplay());
        self::assertStringNotContainsString('nowo_as_enc_', $tester->getDisplay());

        $tester = new CommandTester($command);
        self::assertSame(Command::FAILURE, $tester->execute(['--format' => 'json']));
        self::assertSame(
            ['plain' => 1, 'encrypted' => 1, 'unreadable' => 2, 'unreadable_ids' => [$ids['b'], $ids['d']]],
            json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testUnknownFormatAndMissingManagerAreErrors(): void
    {
        $em      = $this->createEntityManager();
        $command = new SecretsStatusCommand($this->createRegistry($em), new SecretValueCipher(self::PLACEHOLDER_KEY));
        try {
            (new CommandTester($command))->execute(['--format' => 'xml']);
            self::fail('Unknown format.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('"xml"', $e->getMessage());
        }

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);
        $this->expectException(RuntimeException::class);
        (new CommandTester(new SecretsStatusCommand($registry, new SecretValueCipher(self::PLACEHOLDER_KEY))))->execute([]);
    }

    /**
     * Writes the raw values (no encryption listener on this entity manager).
     *
     * @param array<string, string> $values
     * @param list<string> $notSecret
     *
     * @return array<string, int>
     */
    private function store(EntityManager $em, array $values, array $notSecret = []): array
    {
        $workspace   = new ApiWorkspace('W', 'w');
        $environment = new ApiEnvironment('E', 'e');
        $workspace->addEnvironment($environment);
        $em->persist($workspace);
        $em->persist($environment);
        $variables = [];
        foreach ($values as $key => $value) {
            $variable = (new ApiEnvironmentVariable($key, $value))->setSecret(!in_array($key, $notSecret, true));
            $environment->addVariable($variable);
            $em->persist($variable);
            $variables[$key] = $variable;
        }
        $em->flush();

        $ids = [];
        foreach ($variables as $key => $variable) {
            $ids[$key] = (int) $variable->getId();
        }

        return $ids;
    }
}
