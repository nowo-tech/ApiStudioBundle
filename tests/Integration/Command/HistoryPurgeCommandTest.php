<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration\Command;

use DateTimeImmutable;
use Nowo\ApiStudioBundle\Command\HistoryPurgeCommand;
use Nowo\ApiStudioBundle\Privacy\ApiStudioHistoryPurgeResult;
use Nowo\ApiStudioBundle\Privacy\ApiStudioHistoryRetentionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * 1.24 `nowo:api-studio:history:purge`: disabled retention, dry run, bounded run, input validation.
 */
final class HistoryPurgeCommandTest extends TestCase
{
    private const CUTOFF = '2026-08-28T12:00:00+00:00';

    public function testItPurgesAndReportsTheCounts(): void
    {
        $retention = $this->retention();
        $retention->expects(self::once())->method('purge')->with(null)->willReturn(new ApiStudioHistoryPurgeResult(1200, 3, true, new DateTimeImmutable(self::CUTOFF)));
        $tester = new CommandTester(new HistoryPurgeCommand($retention));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Deleted 1200 history row(s) executed before ' . self::CUTOFF . ' in 3 batch(es).', $this->flat($tester));
        self::assertStringNotContainsString('run the command again', $this->flat($tester));
    }

    public function testABoundedRunSaysWhatIsLeft(): void
    {
        $retention = $this->retention();
        $retention->expects(self::once())->method('purge')->with(2)->willReturn(new ApiStudioHistoryPurgeResult(1000, 2, false, new DateTimeImmutable(self::CUTOFF)));
        $tester = new CommandTester(new HistoryPurgeCommand($retention));

        self::assertSame(Command::SUCCESS, $tester->execute(['--max-batches' => '2']));
        self::assertStringContainsString('run the command again', $this->flat($tester));
    }

    public function testADryRunOnlyCounts(): void
    {
        $retention = $this->retention();
        $retention->expects(self::never())->method('purge');
        $retention->method('expiredRows')->willReturn(42);
        $tester = new CommandTester(new HistoryPurgeCommand($retention));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('42 history row(s) executed before ' . self::CUTOFF . ' would be deleted.', $this->flat($tester));
    }

    public function testADisabledRetentionDoesNothing(): void
    {
        $retention = $this->createMock(ApiStudioHistoryRetentionInterface::class);
        $retention->method('cutoff')->willReturn(null);
        $retention->expects(self::never())->method('purge');
        $tester = new CommandTester(new HistoryPurgeCommand($retention));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('retention is disabled', $this->flat($tester));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBounds(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'text' => ['all'];
        yield 'huge' => ['10000000'];
    }

    #[DataProvider('invalidBounds')]
    public function testAnInvalidBoundIsRefused(string $bound): void
    {
        $retention = $this->retention();
        $retention->expects(self::never())->method('purge');
        $tester = new CommandTester(new HistoryPurgeCommand($retention));

        self::assertSame(Command::INVALID, $tester->execute(['--max-batches' => $bound]));
        self::assertStringContainsString('positive integer', $this->flat($tester));
    }

    /**
     * @return ApiStudioHistoryRetentionInterface&MockObject
     */
    private function retention(): ApiStudioHistoryRetentionInterface
    {
        $retention = $this->createMock(ApiStudioHistoryRetentionInterface::class);
        $retention->method('cutoff')->willReturn(new DateTimeImmutable(self::CUTOFF));

        return $retention;
    }

    private function flat(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
