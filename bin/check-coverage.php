#!/usr/bin/env php
<?php

$cloverPath = $argv[1] ?? 'var/coverage/clover.xml';
$minimum = (float) ($argv[2] ?? 80);

if (!is_file($cloverPath)) {
    fwrite(STDERR, sprintf("Coverage report not found at %s\n", $cloverPath));
    exit(1);
}

$report = simplexml_load_file($cloverPath);
if ($report === false) {
    fwrite(STDERR, sprintf("Could not parse %s\n", $cloverPath));
    exit(1);
}

$metrics = $report->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percentage = $statements > 0 ? $covered / $statements * 100 : 100.0;

printf("Line coverage: %.2f%% (%d/%d), minimum %.2f%%\n", $percentage, $covered, $statements, $minimum);

if ($percentage + 0.005 < $minimum) {
    fwrite(STDERR, "Coverage is below the required minimum.\n");
    exit(1);
}
