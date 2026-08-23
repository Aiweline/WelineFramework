<?php

declare(strict_types=1);

use LearningMcp\PhpSymbolParser;

require dirname(__DIR__) . '/src/bootstrap.php';

$repeatedArithmetic = str_repeat("        \$value += 1;\n", 35_000);
$paddingComment = '/*' . str_repeat('parser-memory-padding-', 40_000) . '*/';
$source = <<<PHP
<?php

namespace Stress;

final class TokenHeavy
{
    public function run(int \$value): int
    {
        \$value = helper_call(\$value);
{$repeatedArithmetic}        {$paddingComment}

        return \$value;
    }
}

function helper_call(int \$value): int
{
    return \$value;
}
PHP;

$parsed = (new PhpSymbolParser())->parse($source, 'src/TokenHeavy.php');
$symbols = array_column($parsed['symbols'], null, 'fq_name');

foreach (['Stress\\TokenHeavy', 'Stress\\TokenHeavy::run', 'Stress\\helper_call'] as $fqName) {
    if (!isset($symbols[$fqName])) {
        throw new RuntimeException('Missing expected symbol: ' . $fqName);
    }
}

$runUid = (string) $symbols['Stress\\TokenHeavy::run']['symbol_uid'];
$helperRelations = array_values(array_filter(
    $parsed['relations'],
    static fn (array $relation): bool => ($relation['source_symbol_uid'] ?? null) === $runUid
        && ($relation['target_name'] ?? null) === 'Stress\\helper_call'
        && ($relation['relation_kind'] ?? null) === 'function_call',
));
if (count($helperRelations) !== 1) {
    throw new RuntimeException('Expected one helper_call relation from TokenHeavy::run');
}

$peakBytes = memory_get_peak_usage(true);
$maximumPeakBytes = 96 * 1_024 * 1_024;
if ($peakBytes >= $maximumPeakBytes) {
    throw new RuntimeException(sprintf(
        'Parser peak memory %d bytes exceeds the %d-byte regression budget',
        $peakBytes,
        $maximumPeakBytes,
    ));
}

fwrite(STDOUT, json_encode([
    'source_bytes' => strlen($source),
    'symbols' => count($parsed['symbols']),
    'relations' => count($parsed['relations']),
    'peak_bytes' => $peakBytes,
], JSON_THROW_ON_ERROR) . "\n");
