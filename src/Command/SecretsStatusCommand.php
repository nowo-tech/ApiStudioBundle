<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Security\SecretValueCipher;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function implode;
use function in_array;
use function is_string;
use function json_encode;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * Read-only report of the secret environment variables: how many are stored in plain text or encrypted, and which ones
 * the configured key cannot decrypt (ids only, never values). Those need re-entry: they are never sent or exported.
 * Exit code 1 when any secret is unreadable, so a deploy check catches a wrong key early.
 */
#[AsCommand(
    name: 'nowo:api-studio:secrets:status',
    description: 'Report how secret environment variables are stored and which ones cannot be decrypted (read-only)',
)]
final class SecretsStatusCommand extends Command
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly SecretValueCipher $cipher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        if (!in_array($format, ['text', 'json'], true)) {
            throw new RuntimeException(sprintf('Unknown format "%s": use text or json.', is_string($format) ? $format : ''));
        }

        $manager = $this->registry->getManagerForClass(ApiEnvironmentVariable::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new RuntimeException('No ORM entity manager handles the Api Studio environment variables.');
        }

        // Scalar rows: the stored values as they are in the database (no entity, no decryption listener).
        $rows = $manager->createQueryBuilder()
            ->select('v.id AS id', 'v.value AS value')
            ->from(ApiEnvironmentVariable::class, 'v')
            ->where('v.secret = :secret')
            ->setParameter('secret', true)
            ->orderBy('v.id', 'ASC')
            ->getQuery()
            ->toIterable();

        $plain      = 0;
        $encrypted  = 0;
        $unreadable = [];
        foreach ($rows as $row) {
            $value = (string) $row['value'];
            if (!$this->cipher->isEncrypted($value)) {
                ++$plain;

                continue;
            }

            $decrypted = $this->cipher->tryDecrypt($value);
            // A payload wrapped a second time by a release before 1.10.1 decrypts to another payload.
            if ($decrypted !== null && $this->cipher->isWrappedPayload($decrypted)) {
                $decrypted = $this->cipher->tryDecrypt($decrypted);
            }
            if ($decrypted === null) {
                $unreadable[] = (int) $row['id'];

                continue;
            }

            ++$encrypted;
        }

        $status = $unreadable === [] ? Command::SUCCESS : Command::FAILURE;

        if ($format === 'json') {
            $output->writeln(json_encode([
                'plain'          => $plain,
                'encrypted'      => $encrypted,
                'unreadable'     => count($unreadable),
                'unreadable_ids' => $unreadable,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $status;
        }

        $io = new SymfonyStyle($input, $output);
        $io->table(['Stored as', 'Secret variables'], [
            ['plain text (encrypted on next save)', $plain],
            ['encrypted, readable', $encrypted],
            ['encrypted, cannot be decrypted', count($unreadable)],
        ]);

        if ($unreadable !== []) {
            $io->error(sprintf(
                'Secret variables that the configured key cannot decrypt (ids): %s. They need re-entry; requests that use them are not sent.',
                implode(', ', $unreadable),
            ));

            return $status;
        }

        $io->success('Every secret variable can be decrypted.');

        return $status;
    }
}
