<?php

declare(strict_types=1);

use LearningMcp\PhpParserResultDecoder;
use LearningMcp\PhpSymbolParser;

require dirname(__DIR__) . '/src/bootstrap.php';

$decoder = new PhpParserResultDecoder();
$source = '<?php namespace Payload; final class Valid { public function run(): void { helper(); } } function helper(): void {}';
$expected = (new PhpSymbolParser())->parse($source, 'src/Valid.php');
$payload = json_encode($expected, JSON_THROW_ON_ERROR);
if ($decoder->decode($payload) !== $expected) {
    throw new RuntimeException('Valid parser payload did not round-trip exactly');
}

$semanticSource = <<<'PHP'
<?php
namespace Semantics;
use Vendor\Base as BaseAlias;
use Vendor\Contract;
trait SharedTrait { public function shared(): void {} }
final class Child extends BaseAlias implements Contract
{
    use SharedTrait;
    public function run(): void
    {
        $value = new \Vendor\Thing();
        BaseAlias::boot();
        $value->go();
        helper_function();
    }
}
function helper_function(): void {}
PHP;
$semanticResult = (new PhpSymbolParser())->parse($semanticSource, 'src/Semantics.php');
$semanticHash = hash('sha256', json_encode(
    $semanticResult,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
));
if ($semanticHash !== '3f5ae973d7e310462f69ad9f43efd11981c48f031ec1a8cf1ce2c2b864e7eaaf') {
    throw new RuntimeException('Parser semantic golden output changed: ' . $semanticHash);
}

$invalidRecordsRejected = false;
try {
    $decoder->decode('{"symbols":[[]],"relations":[]}');
} catch (RuntimeException $exception) {
    $invalidRecordsRejected = str_contains($exception->getMessage(), 'invalid symbol record');
}
if (!$invalidRecordsRejected) {
    throw new RuntimeException('Incomplete symbol record was not rejected');
}

$adversarial = '{"symbols":[' . str_repeat('{},', 1_100_000) . '{}],"relations":[]}';
$adversarialRejected = false;
try {
    $decoder->decode($adversarial);
} catch (RuntimeException $exception) {
    $adversarialRejected = str_contains($exception->getMessage(), 'bounded structural limit');
}
if (!$adversarialRejected) {
    throw new RuntimeException('Structurally amplified JSON was not rejected before decoding');
}

$peakBytes = memory_get_peak_usage(true);
if ($peakBytes >= 64 * 1_024 * 1_024) {
    throw new RuntimeException('Adversarial payload preflight exceeded the 64 MiB test budget');
}

fwrite(STDOUT, json_encode([
    'valid_round_trip' => true,
    'semantic_hash' => $semanticHash,
    'invalid_records_rejected' => true,
    'adversarial_bytes' => strlen($adversarial),
    'adversarial_rejected' => true,
    'peak_bytes' => $peakBytes,
], JSON_THROW_ON_ERROR) . "\n");
