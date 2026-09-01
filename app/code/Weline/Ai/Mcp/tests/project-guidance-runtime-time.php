<?php

declare(strict_types=1);

require dirname(__DIR__) . '/scripts/project-guidance-runtime-time.php';

$cases = [
    'minutes and seconds' => ['value' => '01:05', 'expected' => 65],
    'hours minutes and seconds' => ['value' => '03:04:05', 'expected' => 11_045],
    'days hours minutes and seconds' => ['value' => '2-03:04:05', 'expected' => 183_845],
    'surrounding ps whitespace' => ['value' => "  00:09 \n", 'expected' => 9],
    'invalid minute range' => ['value' => '02:61', 'expected' => null],
    'invalid day format' => ['value' => '2-04:05', 'expected' => null],
];

$failed = false;
foreach ($cases as $label => $case) {
    $actual = welineGuidanceElapsedSeconds($case['value']);
    $passed = $actual === $case['expected'];
    fwrite($passed ? STDOUT : STDERR, sprintf("[%s] %s\n", $passed ? 'PASS' : 'FAIL', $label));
    $failed = $failed || !$passed;
}

$startedAt = welineGuidanceStartedEpochFromElapsed('04:00:00', 100_000);
$startedAtPassed = $startedAt === 85_600;
fwrite(
    $startedAtPassed ? STDOUT : STDERR,
    sprintf("[%s] host start derives from elapsed time without timezone parsing\n", $startedAtPassed ? 'PASS' : 'FAIL'),
);
$failed = $failed || !$startedAtPassed;

exit($failed ? 1 : 0);
