<?php

declare(strict_types=1);

/*
 * Fails when the total line coverage of a Clover report is below the minimum.
 * Usage: php scripts/check-coverage.php coverage.xml [minimum]
 */
$file = $argv[1] ?? null;
$minimum = (float) ($argv[2] ?? 80);
if ($file === null || !is_file($file)) {
    fwrite(STDERR, "usage: check-coverage.php coverage.xml [minimum]\n");
    exit(2);
}

$xml = simplexml_load_file($file);
if ($xml === false) {
    fwrite(STDERR, "cannot read {$file}\n");
    exit(2);
}
$metrics = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percent = $statements === 0 ? 0.0 : 100 * $covered / $statements;

printf("total coverage: %.1f%% (minimum %.1f%%)\n", $percent, $minimum);
if ($percent < $minimum) {
    fwrite(STDERR, "coverage below the minimum\n");
    exit(1);
}
