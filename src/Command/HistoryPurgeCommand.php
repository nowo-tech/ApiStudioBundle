<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Command;

use Nowo\ApiStudioBundle\Privacy\ApiStudioHistoryRetentionInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;
use function preg_match;
use function sprintf;

use const DATE_ATOM;

/**
 * 1.24: deletes the request history older than `nowo_api_studio.history.retention_days` (default 90; 0 = keep
 * forever), in batches of `history.purge_batch_size`, each its own short transaction. Safe in cron, repeated or run
 * twice at once. `--dry-run` only counts. `--max-batches` bounds one run (the rest goes on the next one).
 */
#[AsCommand(
    name: 'nowo:api-studio:history:purge',
    description: 'Delete the request history older than the retention (history.retention_days)',
)]
final class HistoryPurgeCommand extends Command
{
    public function __construct(
        private readonly ApiStudioHistoryRetentionInterface $retention,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count the rows that would be deleted; delete nothing')
            ->addOption('max-batches', null, InputOption::VALUE_REQUIRED, 'Stop after this many batches (default: until done)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $cutoff = $this->retention->cutoff();
        if ($cutoff === null) {
            $io->note('History retention is disabled (nowo_api_studio.history.retention_days: 0): nothing to purge.');

            return Command::SUCCESS;
        }

        $max = $input->getOption('max-batches');
        if ($max !== null && (!is_string($max) || preg_match('/^[1-9]\d{0,5}$/', $max) !== 1)) {
            $io->error('--max-batches must be a positive integer.');

            return Command::INVALID;
        }

        if ($input->getOption('dry-run') === true) {
            $io->success(sprintf('%d history row(s) executed before %s would be deleted.', $this->retention->expiredRows(), $cutoff->format(DATE_ATOM)));

            return Command::SUCCESS;
        }

        $result = $this->retention->purge($max === null ? null : (int) $max);
        $io->success(sprintf('Deleted %d history row(s) executed before %s in %d batch(es).', $result->deleted, ($result->cutoff ?? $cutoff)->format(DATE_ATOM), $result->batches));
        if (!$result->complete) {
            $io->note('Older rows remain (--max-batches reached): run the command again.');
        }

        return Command::SUCCESS;
    }
}
