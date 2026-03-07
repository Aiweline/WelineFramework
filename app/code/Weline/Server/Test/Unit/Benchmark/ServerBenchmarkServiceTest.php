<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Benchmark;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\AbstractModel;
use Weline\Server\Model\ServerBenchmarkLog;
use Weline\Server\Service\Benchmark\ServerBenchmarkService;

class FakeServerBenchmarkLog extends ServerBenchmarkLog
{
    /** @var array<int,array<string,mixed>> */
    public array $listRows = [];
    /** @var array<string,mixed>|null */
    public ?array $detailRow = null;
    /** @var array<string,mixed> */
    public array $savedData = [];

    public function __construct()
    {
    }

    public function clearQuery(): static
    {
        return $this;
    }

    public function order(string $field = '', string $sort = 'DESC'): static
    {
        return $this;
    }

    public function pagination(int $page = 0, int $pageSize = 0, array $params = [], int $max_limit = 1000, int $total = 0): static
    {
        return $this;
    }

    public function select(string $fields = ''): static
    {
        return $this;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function fetchArray(): array
    {
        return $this->listRows;
    }

    public function where(array|string $field, mixed $value = null, string $condition = '=', string $where_logic = 'AND', string $array_where_logic_type = 'AND'): static
    {
        return $this;
    }

    public function find(string $find_fields = ''): static
    {
        return $this;
    }

    public function fetch(string $model_class = ''): mixed
    {
        return $this->detailRow;
    }

    public function setData($key, $value = null, bool $is_unique = false): static
    {
        if (\is_array($key)) {
            foreach ($key as $k => $v) {
                $this->savedData[(string)$k] = $v;
            }
            return $this;
        }
        $this->savedData[(string)$key] = $value;
        return $this;
    }

    public function save(string|array|bool|AbstractModel $data = [], string|array $sequence = ''): bool|int
    {
        return true;
    }
}

class ServerBenchmarkServiceTest extends TestCase
{
    public function testListReturnsPagedRows(): void
    {
        $rows = [
            ['benchmark_id' => 2, 'instance' => 'verify_http'],
            ['benchmark_id' => 1, 'instance' => 'default'],
        ];
        $model = new FakeServerBenchmarkLog();
        $model->listRows = $rows;
        $service = new ServerBenchmarkService($model);

        $result = $service->list(1, 20);
        $this->assertSame($rows, $result);
    }

    public function testDetailParsesResultJson(): void
    {
        $row = [
            ServerBenchmarkLog::schema_fields_ID => 88,
            ServerBenchmarkLog::schema_fields_RESULT_JSON => \json_encode([
                'qps' => 123.45,
                'latency' => ['p95' => 9.8],
            ], JSON_UNESCAPED_UNICODE),
        ];
        $model = new FakeServerBenchmarkLog();
        $model->detailRow = $row;
        $service = new ServerBenchmarkService($model);

        $detail = $service->detail(88);
        $this->assertNotNull($detail);
        $this->assertSame(123.45, $detail['parsed_result']['qps'] ?? null);
        $this->assertSame(9.8, $detail['parsed_result']['latency']['p95'] ?? null);
    }

    public function testRunAndStoreWritesBenchmarkData(): void
    {
        if (!@\function_exists('curl_multi_init')) {
            $this->markTestSkipped('curl extension is required.');
        }

        $socket = @\fsockopen('127.0.0.1', 10001, $errno, $errstr, 1);
        if (!$socket) {
            $this->markTestSkipped('Worker endpoint 127.0.0.1:10001 is not reachable.');
        }
        \fclose($socket);

        $model = new FakeServerBenchmarkLog();
        $service = new ServerBenchmarkService($model);

        $result = $service->runAndStore(
            'verify_http',
            'http://127.0.0.1:10001/_wls/health',
            2,
            10
        );

        $this->assertTrue((bool)($result['success'] ?? false));
        $this->assertArrayHasKey(ServerBenchmarkLog::schema_fields_INSTANCE, $model->savedData);
        $this->assertSame('verify_http', $model->savedData[ServerBenchmarkLog::schema_fields_INSTANCE]);
        $this->assertArrayHasKey(ServerBenchmarkLog::schema_fields_QPS, $model->savedData);
        $this->assertArrayHasKey(ServerBenchmarkLog::schema_fields_RESULT_JSON, $model->savedData);
    }
}

