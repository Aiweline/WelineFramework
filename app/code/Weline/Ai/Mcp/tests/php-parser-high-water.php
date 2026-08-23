<?php

declare(strict_types=1);

use LearningMcp\PhpParserResultDecoder;

require dirname(__DIR__) . '/src/bootstrap.php';

$payload = '{"symbols":[' . str_repeat('{},', 24_995) . '{}],"relations":[]}';
$pressure = [];
$targetUsage = 120 * 1_024 * 1_024;
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

fwrite(STDOUT, json_encode([
    'payload_bytes' => strlen($payload),
    'usage_before' => $usageBefore,
    'rejected_before_decode' => true,
    'peak_bytes' => memory_get_peak_usage(true),
], JSON_THROW_ON_ERROR) . "\n");
