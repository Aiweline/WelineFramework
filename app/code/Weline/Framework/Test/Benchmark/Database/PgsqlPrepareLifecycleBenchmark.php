<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Benchmark\Database;

$projectRoot = dirname(__DIR__, 7);
if (!defined('BP')) {
    define('BP', $projectRoot . DIRECTORY_SEPARATOR);
}
if (!defined('SANDBOX')) {
    define('SANDBOX', false);
}
if (!defined('DEBUG')) {
    define('DEBUG', false);
}
if (!defined('DEV')) {
    define('DEV', false);
}
require_once BP . 'app/autoload.php';
require_once BP . 'app/code/Weline/Framework/Common/functions.php';

use PDO;
use PDOStatement;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Query;

final class PgsqlPrepareLifecycleBenchmarkQuery extends Query
{
    public int $prepareCalls = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function getLink(): PDO
    {
        return $this->pdo;
    }

    protected function preparePgsql(string $sql, array $options = []): PDOStatement|false
    {
        $this->prepareCalls++;
        return parent::preparePgsql($sql, $options);
    }
}

final class PgsqlPrepareLifecycleBenchmark
{
    /** @param list<string> $arguments */
    public static function main(array $arguments): int
    {
        $options = self::parseArguments($arguments);
        $pdo = self::connectPrimaryPostgreSql();
        $sql = 'SELECT CAST(:probe AS INTEGER) AS probe';

        for ($warmup = 0; $warmup < 20; $warmup++) {
            self::measure($pdo, $sql, false);
            self::measure($pdo, $sql, true);
        }

        $baseline = [];
        $candidate = [];
        for ($iteration = 0; $iteration < $options['iterations']; $iteration++) {
            if (($iteration % 2) === 0) {
                $candidate[] = self::measure($pdo, $sql, false);
                $baseline[] = self::measure($pdo, $sql, true);
            } else {
                $baseline[] = self::measure($pdo, $sql, true);
                $candidate[] = self::measure($pdo, $sql, false);
            }
        }
        sort($baseline, SORT_NUMERIC);
        sort($candidate, SORT_NUMERIC);

        $report = [
            'schema' => 'weline.pgsql-prepare-lifecycle-benchmark.v1',
            'php' => PHP_VERSION,
            'server' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
            'iterations' => $options['iterations'],
            'baseline_prepare_count' => 3,
            'candidate_prepare_count' => 1,
            'baseline_ns' => self::statistics($baseline),
            'candidate_ns' => self::statistics($candidate),
            'p99_change_percent' => self::changePercent(
                self::percentile($baseline, 99),
                self::percentile($candidate, 99)
            ),
            'baseline_samples_ns' => $baseline,
            'candidate_samples_ns' => $candidate,
        ];
        $json = json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if ($options['output'] !== '') {
            if (file_put_contents($options['output'], $json) === false) {
                fwrite(STDERR, "Unable to write benchmark output: {$options['output']}\n");
                return 2;
            }
        }
        fwrite(STDOUT, $json);
        return 0;
    }

    private static function measure(PDO $pdo, string $sql, bool $emulateBaseline): int
    {
        $startedAt = hrtime(true);
        if ($emulateBaseline) {
            $unusedFirst = $pdo->prepare($sql);
            $unusedSecond = $pdo->prepare($sql);
            unset($unusedFirst, $unusedSecond);
        }
        $query = new PgsqlPrepareLifecycleBenchmarkQuery($pdo);
        $query->query($sql);
        $query->bound_values = [':probe' => 7];
        $result = $query->fetch();
        if ((int) ($result[0]['probe'] ?? -1) !== 7 || $query->prepareCalls !== 1) {
            throw new \RuntimeException('PostgreSQL lifecycle benchmark produced an invalid result.');
        }
        return hrtime(true) - $startedAt;
    }

    private static function connectPrimaryPostgreSql(): PDO
    {
        $config = include BP . 'app/etc/env.php';
        $master = $config['db']['master'] ?? [];
        if (($master['type'] ?? null) !== 'pgsql') {
            throw new \RuntimeException('Primary database is not PostgreSQL.');
        }
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $master['hostname'],
            $master['hostport'],
            $master['database']
        );
        return new PDO(
            $dsn,
            $master['username'],
            $master['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    /** @return array{iterations:int, output:string} */
    private static function parseArguments(array $arguments): array
    {
        $result = ['iterations' => 500, 'output' => ''];
        foreach (array_slice($arguments, 1) as $argument) {
            if (str_starts_with($argument, '--iterations=')) {
                $result['iterations'] = (int) substr($argument, 13);
            } elseif (str_starts_with($argument, '--output=')) {
                $result['output'] = substr($argument, 9);
            }
        }
        if ($result['iterations'] < 100) {
            throw new \InvalidArgumentException('iterations must be at least 100 for a p99 sample.');
        }
        return $result;
    }

    /** @param list<int> $samples */
    private static function statistics(array $samples): array
    {
        return [
            'p50' => self::percentile($samples, 50),
            'p95' => self::percentile($samples, 95),
            'p99' => self::percentile($samples, 99),
            'max' => $samples[array_key_last($samples)] ?? 0,
        ];
    }

    /** @param list<int> $samples */
    private static function percentile(array $samples, int $percentile): int
    {
        $index = (int) ceil((count($samples) * $percentile) / 100) - 1;
        return $samples[max(0, min($index, count($samples) - 1))] ?? 0;
    }

    private static function changePercent(int $baseline, int $candidate): float
    {
        if ($baseline === 0) {
            return 0.0;
        }
        return round((($candidate - $baseline) / $baseline) * 100, 3);
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(PgsqlPrepareLifecycleBenchmark::main($_SERVER['argv'] ?? []));
}
