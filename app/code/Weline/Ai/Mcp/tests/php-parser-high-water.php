<?php

declare(strict_types=1);

use LearningMcp\PhpParserResultDecoder;

require dirname(__DIR__) . '/src/bootstrap.php';

$payload = '{"symbols":[' . str_repeat('{},', 24_995) . '{}],"relations":[]}';
$pressure = [];
// Leave less than the adaptive 4 MiB decode floor under a 128 MiB limit.
$targetUsage = 125 * 1_024 * 1_024;
while (memory_get_usage(true) < $targetUsage) {
    $pressure[] = str_repeat('p', 256 * 1_024);
}
$usageBefore = memory_get_usage(true);

$rejected = false;
try {
    (new PhpParserResultDecoder())->decode($payload);
} catch (RuntimeException $exception) {
    $rejected = str_contains($exception->getMessage(), 'decode memory reserve');
}
if (!$rejected) {
    throw new RuntimeException('High-water parser payload was not rejected before JSON decoding');
}

// Tiny payloads must not inherit the old fixed 64 MiB floor.
$tinyRejected = false;
try {
    (new PhpParserResultDecoder())->decode('{"symbols":[],"relations":[]}');
} catch (RuntimeException $exception) {
    $tinyRejected = str_contains($exception->getMessage(), 'decode memory reserve');
}
if (!$tinyRejected) {
    throw new RuntimeException('Expected tiny payload decode to still fail under high-water pressure');
}

fwrite(STDOUT, json_encode([
    'payload_bytes' => strlen($payload),
    'usage_before' => $usageBefore,
    'rejected_before_decode' => true,
    'tiny_also_rejected' => true,
    'peak_bytes' => memory_get_peak_usage(true),
], JSON_THROW_ON_ERROR) . "\n");
