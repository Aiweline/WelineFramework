<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Benchmark\Database;

$projectRoot = dirname(__DIR__, 7);
if (!defined('BP')) {
    define('BP', $projectRoot . DIRECTORY_SEPARATOR);
}
require_once BP . 'app/autoload.php';

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Model;

final class OrmReadPathBenchmarkModel extends Model
{
    public const schema_table = 'codex_orm_read_path_benchmark';
    public const schema_primary_key = 'id';
}

final class OrmReadPathBenchmarkLegacyModel extends Model
{
    public const schema_table = 'codex_orm_read_path_benchmark';
    public const schema_primary_key = 'id';

    public function setItems(array $items): self
    {
        $this->items = [];
        foreach ($items as $item) {
            if ($item instanceof AbstractModel) {
                $model = clone $this;
                $this->addItem($model->addData($item->getData()));
            } elseif (is_array($item)) {
                $model = clone $this;
                $this->addItem($model->addData($item));
            }
        }
        return $this;
    }
}

final class OrmReadPathBenchmark
{
    /** @param list<string> $arguments */
    public static function main(array $arguments): int
    {
        $options = self::parseArguments($arguments);
        $rows = $options['rows'];
        $iterations = $options['iterations'];
        if ($options['implementation'] === 'compare') {
            $report = self::runPairedComparison($rows, $iterations, $options['label']);
            return self::emitReport($report, $options['output']);
        }
        $modelClass = $options['implementation'] === 'legacy'
            ? OrmReadPathBenchmarkLegacyModel::class
            : OrmReadPathBenchmarkModel::class;
        $sources = self::makeSources($rows, $modelClass);
        $samples = [];
        $peakBytes = [];
        $nestedReferences = null;

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $startMemory = memory_get_usage(false);
            $startedAt = hrtime(true);

            $collection = new $modelClass();
            $collection->setFetchData($sources);

            $samples[] = hrtime(true) - $startedAt;
            $peakBytes[] = max(0, memory_get_peak_usage(false) - $startMemory);
            $nestedReferences = 0;
            foreach ($collection->getItems() as $item) {
                $nestedReferences += count($item->getItems());
            }
            unset($collection);
        }

        sort($samples, SORT_NUMERIC);
        sort($peakBytes, SORT_NUMERIC);
        $percentilesValid = $iterations >= 100;
        $report = [
            'schema' => 'weline.orm-read-path-benchmark.v1',
            'label' => $options['label'],
            'implementation' => $options['implementation'],
            'php' => PHP_VERSION,
            'rows' => $rows,
            'iterations' => $iterations,
            'sample_policy' => [
                'percentile_min_iterations' => 100,
                'percentiles_valid' => $percentilesValid,
            ],
            'duration_ns' => [
                'p50' => self::percentile($samples, 50),
                'p95' => $percentilesValid ? self::percentile($samples, 95) : null,
                'p99' => $percentilesValid ? self::percentile($samples, 99) : null,
                'max' => $samples[array_key_last($samples)] ?? 0,
            ],
            'peak_bytes' => [
                'p50' => self::percentile($peakBytes, 50),
                'p95' => $percentilesValid ? self::percentile($peakBytes, 95) : null,
                'p99' => $percentilesValid ? self::percentile($peakBytes, 99) : null,
                'max' => $peakBytes[array_key_last($peakBytes)] ?? 0,
            ],
            'nested_item_references' => $nestedReferences ?? 0,
            'samples_ns' => $samples,
            'peak_samples_bytes' => $peakBytes,
        ];
        if ($options['compare_to'] !== '') {
            $report['comparison'] = self::compareWithBaseline($report, $options['compare_to']);
        }
        return self::emitReport($report, $options['output']);
    }

    /** @param array<string, mixed> $report */
    private static function emitReport(array $report, string $output): int
    {
        $json = json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if ($output !== '') {
            $directory = dirname($output);
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                fwrite(STDERR, "Unable to create benchmark output directory: {$directory}\n");
                return 2;
            }
            if (file_put_contents($output, $json) === false) {
                fwrite(STDERR, "Unable to write benchmark output: {$output}\n");
                return 2;
            }
        }
        fwrite(STDOUT, $json);
        return 0;
    }

    /**
     * @return array{
     *     rows:int,
     *     iterations:int,
     *     label:string,
     *     output:string,
     *     implementation:string,
     *     compare_to:string
     * }
     */
    private static function parseArguments(array $arguments): array
    {
        $result = [
            'rows' => 1000,
            'iterations' => 100,
            'label' => 'candidate',
            'output' => '',
            'implementation' => 'current',
            'compare_to' => '',
        ];
        foreach (array_slice($arguments, 1) as $argument) {
            if (str_starts_with($argument, '--rows=')) {
                $result['rows'] = (int) substr($argument, 7);
            } elseif (str_starts_with($argument, '--iterations=')) {
                $result['iterations'] = (int) substr($argument, 13);
            } elseif (str_starts_with($argument, '--label=')) {
                $result['label'] = substr($argument, 8);
            } elseif (str_starts_with($argument, '--output=')) {
                $result['output'] = substr($argument, 9);
            } elseif (str_starts_with($argument, '--implementation=')) {
                $result['implementation'] = substr($argument, 17);
            } elseif (str_starts_with($argument, '--compare-to=')) {
                $result['compare_to'] = substr($argument, 13);
            }
        }
        if ($result['rows'] < 0 || $result['iterations'] < 1) {
            throw new \InvalidArgumentException('rows must be >= 0 and iterations must be >= 1');
        }
        if (!in_array($result['implementation'], ['legacy', 'current', 'compare'], true)) {
            throw new \InvalidArgumentException('implementation must be legacy, current, or compare');
        }
        return $result;
    }

    /**
     * @param class-string<AbstractModel> $modelClass
     * @return list<AbstractModel>
     */
    private static function makeSources(int $rows, string $modelClass): array
    {
        $sources = [];
        for ($index = 0; $index < $rows; $index++) {
            $sources[] = new $modelClass([
                'id' => $index,
                'code' => 'row-' . $index,
                'payload' => str_repeat('x', 32),
            ]);
        }
        return $sources;
    }

    /** @return array<string, mixed> */
    private static function runPairedComparison(int $rows, int $iterations, string $label): array
    {
        $legacySources = self::makeSources($rows, OrmReadPathBenchmarkLegacyModel::class);
        $currentSources = self::makeSources($rows, OrmReadPathBenchmarkModel::class);
        $legacySamples = [];
        $legacyPeakBytes = [];
        $currentSamples = [];
        $currentPeakBytes = [];
        $legacyNestedReferences = 0;
        $currentNestedReferences = 0;

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $order = ($iteration % 2) === 0
                ? ['current', 'legacy']
                : ['legacy', 'current'];
            foreach ($order as $implementation) {
                if ($implementation === 'legacy') {
                    [$duration, $peak, $nested] = self::measureCollection(
                        $legacySources,
                        OrmReadPathBenchmarkLegacyModel::class
                    );
                    $legacySamples[] = $duration;
                    $legacyPeakBytes[] = $peak;
                    $legacyNestedReferences = $nested;
                } else {
                    [$duration, $peak, $nested] = self::measureCollection(
                        $currentSources,
                        OrmReadPathBenchmarkModel::class
                    );
                    $currentSamples[] = $duration;
                    $currentPeakBytes[] = $peak;
                    $currentNestedReferences = $nested;
                }
            }
        }

        $percentilesValid = $iterations >= 100;
        $legacy = self::buildSeriesReport(
            $legacySamples,
            $legacyPeakBytes,
            $legacyNestedReferences,
            $percentilesValid
        );
        $current = self::buildSeriesReport(
            $currentSamples,
            $currentPeakBytes,
            $currentNestedReferences,
            $percentilesValid
        );
        $legacyP99 = $legacy['duration_ns']['p99'];
        $currentP99 = $current['duration_ns']['p99'];
        $p99Change = is_int($legacyP99) && is_int($currentP99)
            ? self::changePercent($legacyP99, $currentP99)
            : null;
        $peakReduction = self::reductionPercent(
            (int) $legacy['peak_bytes']['p50'],
            (int) $current['peak_bytes']['p50']
        );

        return [
            'schema' => 'weline.orm-read-path-paired-benchmark.v1',
            'label' => $label,
            'implementation' => 'compare',
            'php' => PHP_VERSION,
            'rows' => $rows,
            'iterations_per_implementation' => $iterations,
            'order' => 'alternating',
            'sample_policy' => [
                'percentile_min_iterations' => 100,
                'percentiles_valid' => $percentilesValid,
            ],
            'legacy' => $legacy,
            'current' => $current,
            'comparison' => [
                'duration_p99_change_percent' => $p99Change,
                'peak_p50_reduction_percent' => $peakReduction,
                'gates' => [
                    'one_row_p99_not_worse_than_3_percent' => $rows === 1
                        ? ($p99Change !== null && $p99Change <= 3.0)
                        : null,
                    'ten_thousand_row_peak_reduction_at_least_50_percent' => $rows === 10000
                        ? $peakReduction >= 50.0
                        : null,
                ],
            ],
        ];
    }

    /**
     * @param list<AbstractModel> $sources
     * @param class-string<AbstractModel> $modelClass
     * @return array{int, int, int}
     */
    private static function measureCollection(array $sources, string $modelClass): array
    {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $startMemory = memory_get_usage(false);
        $startedAt = hrtime(true);

        $collection = new $modelClass();
        $collection->setFetchData($sources);

        $duration = hrtime(true) - $startedAt;
        $peak = max(0, memory_get_peak_usage(false) - $startMemory);
        $nestedReferences = 0;
        foreach ($collection->getItems() as $item) {
            $nestedReferences += count($item->getItems());
        }
        unset($collection);

        return [$duration, $peak, $nestedReferences];
    }

    /**
     * @param list<int> $samples
     * @param list<int> $peakBytes
     * @return array<string, mixed>
     */
    private static function buildSeriesReport(
        array $samples,
        array $peakBytes,
        int $nestedReferences,
        bool $percentilesValid
    ): array {
        sort($samples, SORT_NUMERIC);
        sort($peakBytes, SORT_NUMERIC);

        return [
            'duration_ns' => [
                'p50' => self::percentile($samples, 50),
                'p95' => $percentilesValid ? self::percentile($samples, 95) : null,
                'p99' => $percentilesValid ? self::percentile($samples, 99) : null,
                'max' => $samples[array_key_last($samples)] ?? 0,
            ],
            'peak_bytes' => [
                'p50' => self::percentile($peakBytes, 50),
                'p95' => $percentilesValid ? self::percentile($peakBytes, 95) : null,
                'p99' => $percentilesValid ? self::percentile($peakBytes, 99) : null,
                'max' => $peakBytes[array_key_last($peakBytes)] ?? 0,
            ],
            'nested_item_references' => $nestedReferences,
            'samples_ns' => $samples,
            'peak_samples_bytes' => $peakBytes,
        ];
    }

    /** @param array<string, mixed> $candidate */
    private static function compareWithBaseline(array $candidate, string $baselinePath): array
    {
        $contents = file_get_contents($baselinePath);
        if ($contents === false) {
            throw new \RuntimeException("Unable to read baseline report: {$baselinePath}");
        }
        $baseline = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($baseline)
            || ($baseline['schema'] ?? null) !== $candidate['schema']
            || ($baseline['rows'] ?? null) !== $candidate['rows']
        ) {
            throw new \RuntimeException('Baseline report schema or row count does not match candidate.');
        }

        $baselineP99 = $baseline['duration_ns']['p99'] ?? null;
        $candidateP99 = $candidate['duration_ns']['p99'] ?? null;
        $p99Change = is_int($baselineP99) && is_int($candidateP99)
            ? self::changePercent($baselineP99, $candidateP99)
            : null;
        $baselinePeak = (int) ($baseline['peak_bytes']['p50'] ?? 0);
        $candidatePeak = (int) ($candidate['peak_bytes']['p50'] ?? 0);
        $peakReduction = self::reductionPercent($baselinePeak, $candidatePeak);

        return [
            'baseline_file' => $baselinePath,
            'duration_p99_change_percent' => $p99Change,
            'peak_p50_reduction_percent' => $peakReduction,
            'gates' => [
                'one_row_p99_not_worse_than_3_percent' => $candidate['rows'] === 1
                    ? ($p99Change !== null && $p99Change <= 3.0)
                    : null,
                'ten_thousand_row_peak_reduction_at_least_50_percent' => $candidate['rows'] === 10000
                    ? $peakReduction >= 50.0
                    : null,
            ],
        ];
    }

    /** @param list<int> $samples */
    private static function percentile(array $samples, int $percentile): int
    {
        if ($samples === []) {
            return 0;
        }
        $index = (int) ceil((count($samples) * $percentile) / 100) - 1;
        return $samples[max(0, min($index, count($samples) - 1))];
    }

    private static function changePercent(int $baseline, int $candidate): float
    {
        if ($baseline === 0) {
            return 0.0;
        }
        return round((($candidate - $baseline) / $baseline) * 100, 3);
    }

    private static function reductionPercent(int $baseline, int $candidate): float
    {
        if ($baseline === 0) {
            return 0.0;
        }
        return round((($baseline - $candidate) / $baseline) * 100, 3);
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(OrmReadPathBenchmark::main($_SERVER['argv'] ?? []));
}
