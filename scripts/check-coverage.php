<?php

declare(strict_types=1);

/**
 * REQ-TEST-003 coverage gate: fails (exit 1) when the line (statement) coverage of coverage.xml (Clover, every src/
 * class: phpunit.xml.dist excludes only src/Resources) is below the threshold (default 99%).
 *
 * Usage: php scripts/check-coverage.php [coverage.xml] [--min-percent=N]
 */
$coverageFile = $argv[1] ?? 'coverage.xml';
$minPercent   = 99.0;
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--min-percent=')) {
        $minPercent = (float) substr($arg, strlen('--min-percent='));
    }
}

if (!is_file($coverageFile)) {
    fwrite(\STDERR, "Coverage file not found: {$coverageFile}\n");
    exit(2);
}

$xml = @simplexml_load_file($coverageFile);
if ($xml === false || !isset($xml->project->metrics)) {
    fwrite(\STDERR, "No project metrics in {$coverageFile}\n");
    exit(2);
}

$statements = (int) (string) $xml->project->metrics['statements'];
$covered    = (int) (string) $xml->project->metrics['coveredstatements'];
$percent    = $statements > 0 ? floor($covered / $statements * 10000) / 100 : 100.0;

printf('PHP line coverage: %d/%d (%.2f%%), threshold %.2f%%' . \PHP_EOL, $covered, $statements, $percent, $minPercent);

if ($percent < $minPercent) {
    fwrite(\STDERR, "Coverage below the {$minPercent}% threshold (REQ-TEST-003).\n");
    exit(1);
}

exit(0);
