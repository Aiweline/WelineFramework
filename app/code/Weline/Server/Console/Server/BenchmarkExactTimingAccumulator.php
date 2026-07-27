<?php
declare(strict_types=1);

namespace Weline\Server\Console\Server;

/**
 * Exact percentile accumulator with bounded PHP heap usage.
 *
 * Sorted chunks spill to php://temp and are merged only when the benchmark
 * finishes. Reports therefore keep exact percentile semantics without
 * retaining one zval for every request.
 */
final class BenchmarkExactTimingAccumulator
{
    private const VALUE_BYTES = 8;
    private const READ_VALUES = 1024;

    /** @var list<float> */
    private array $chunk = [];

    /** @var list<array{offset:int,count:int}> */
    private array $runs = [];

    /** @var resource|null */
    private $stream = null;

    /** @var array{count:int,avg:float,min:float,max:float,median:float,p95:float,p99:float}|null */
    private ?array $summary = null;

    private int $count = 0;
    private float $sum = 0.0;
    private float $min = INF;
    private float $max = -INF;

    public function __construct(private readonly int $chunkSize = 16384)
    {
        if ($this->chunkSize < 2) {
            throw new \InvalidArgumentException('Benchmark timing chunk size must be at least 2.');
        }
    }

    public function add(float $value): void
    {
        if ($this->summary !== null) {
            throw new \LogicException('Cannot add benchmark timing samples after finalization.');
        }
        if (!\is_finite($value)) {
            throw new \InvalidArgumentException('Benchmark timing samples must be finite.');
        }

        $this->count++;
        $this->sum += $value;
        $this->min = \min($this->min, $value);
        $this->max = \max($this->max, $value);
        $this->chunk[] = $value;
        if (\count($this->chunk) >= $this->chunkSize) {
            $this->flushChunk();
        }
    }

    /**
     * @return array{count:int,avg:float,min:float,max:float,p95:float,p99:float}|array{count:int,avg:float,min:float,max:float,median:float,p95:float,p99:float}
     */
    public function summarize(bool $includeMedian = false): array
    {
        if ($this->summary === null) {
            $this->summary = $this->buildSummary();
            $this->releaseStorage();
        }

        if ($includeMedian) {
            return $this->summary;
        }

        $summary = $this->summary;
        unset($summary['median']);
        return $summary;
    }

    public function __destruct()
    {
        $this->releaseStorage();
    }

    /**
     * @return array{count:int,avg:float,min:float,max:float,median:float,p95:float,p99:float}
     */
    private function buildSummary(): array
    {
        if ($this->count === 0) {
            return [
                'count' => 0,
                'avg' => 0.0,
                'min' => 0.0,
                'max' => 0.0,
                'median' => 0.0,
                'p95' => 0.0,
                'p99' => 0.0,
            ];
        }

        if ($this->runs === []) {
            \sort($this->chunk, SORT_NUMERIC);
            $median = (float)$this->chunk[$this->indexFor(0.5)];
            $p95 = (float)$this->chunk[$this->indexFor(0.95)];
            $p99 = (float)$this->chunk[$this->indexFor(0.99)];
        } else {
            $this->flushChunk();
            [$median, $p95, $p99] = $this->mergeQuantiles();
        }

        return [
            'count' => $this->count,
            'avg' => \round($this->sum / $this->count, 3),
            'min' => \round($this->min, 3),
            'max' => \round($this->max, 3),
            'median' => \round($median, 3),
            'p95' => \round($p95, 3),
            'p99' => \round($p99, 3),
        ];
    }

    private function indexFor(float $quantile): int
    {
        return \min((int)\floor($this->count * $quantile), $this->count - 1);
    }

    private function flushChunk(): void
    {
        if ($this->chunk === []) {
            return;
        }

        \sort($this->chunk, SORT_NUMERIC);
        if (!\is_resource($this->stream)) {
            $stream = \fopen('php://temp/maxmemory:1048576', 'w+b');
            if (!\is_resource($stream)) {
                throw new \RuntimeException('Unable to create benchmark timing spill stream.');
            }
            $this->stream = $stream;
        }
        if (\fseek($this->stream, 0, SEEK_END) !== 0) {
            throw new \RuntimeException('Unable to seek benchmark timing spill stream.');
        }
        $offset = \ftell($this->stream);
        if (!\is_int($offset)) {
            throw new \RuntimeException('Unable to determine benchmark timing spill offset.');
        }

        $binary = '';
        foreach ($this->chunk as $value) {
            $binary .= \pack('d', $value);
        }
        $this->writeAll($binary);
        $this->runs[] = ['offset' => $offset, 'count' => \count($this->chunk)];
        $this->chunk = [];
    }

    /** @return array{0:float,1:float,2:float} */
    private function mergeQuantiles(): array
    {
        if (!\is_resource($this->stream)) {
            throw new \RuntimeException('Benchmark timing spill stream is unavailable.');
        }

        $targets = [];
        foreach ([
            'median' => $this->indexFor(0.5),
            'p95' => $this->indexFor(0.95),
            'p99' => $this->indexFor(0.99),
        ] as $name => $index) {
            $targets[$index][] = $name;
        }
        $lastTarget = (int)\max(\array_keys($targets));
        $values = ['median' => null, 'p95' => null, 'p99' => null];
        $states = [];
        $heap = new \SplPriorityQueue();
        $heap->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);

        foreach ($this->runs as $runId => $run) {
            $states[$runId] = $run + ['loaded' => 0, 'buffer' => [], 'buffer_index' => 0];
            $value = $this->readNextRunValue($states[$runId]);
            if ($value !== null) {
                $heap->insert(['run_id' => $runId, 'value' => $value], -$value);
            }
        }

        for ($rank = 0; $rank <= $lastTarget && !$heap->isEmpty(); $rank++) {
            $entry = $heap->extract();
            $data = (array)$entry['data'];
            $value = (float)$data['value'];
            foreach ($targets[$rank] ?? [] as $name) {
                $values[$name] = $value;
            }
            $runId = (int)$data['run_id'];
            $next = $this->readNextRunValue($states[$runId]);
            if ($next !== null) {
                $heap->insert(['run_id' => $runId, 'value' => $next], -$next);
            }
        }

        if ($values['median'] === null || $values['p95'] === null || $values['p99'] === null) {
            throw new \RuntimeException('Benchmark timing spill merge ended before all quantiles were found.');
        }

        return [(float)$values['median'], (float)$values['p95'], (float)$values['p99']];
    }

    /**
     * @param array{offset:int,count:int,loaded:int,buffer:list<float>,buffer_index:int} $state
     */
    private function readNextRunValue(array &$state): ?float
    {
        if ($state['buffer_index'] >= \count($state['buffer'])) {
            if ($state['loaded'] >= $state['count']) {
                return null;
            }
            $readCount = \min(self::READ_VALUES, $state['count'] - $state['loaded']);
            $offset = $state['offset'] + ($state['loaded'] * self::VALUE_BYTES);
            if (\fseek($this->stream, $offset, SEEK_SET) !== 0) {
                throw new \RuntimeException('Unable to seek benchmark timing run.');
            }
            $binary = $this->readExact($readCount * self::VALUE_BYTES);
            $values = \unpack('d*', $binary);
            if (!\is_array($values) || \count($values) !== $readCount) {
                throw new \RuntimeException('Unable to decode benchmark timing run.');
            }
            $state['buffer'] = \array_values($values);
            $state['buffer_index'] = 0;
            $state['loaded'] += $readCount;
        }

        return (float)$state['buffer'][$state['buffer_index']++];
    }

    private function writeAll(string $binary): void
    {
        $length = \strlen($binary);
        $written = 0;
        while ($written < $length) {
            $bytes = \fwrite($this->stream, \substr($binary, $written));
            if (!\is_int($bytes) || $bytes <= 0) {
                throw new \RuntimeException('Unable to write benchmark timing spill data.');
            }
            $written += $bytes;
        }
    }

    private function readExact(int $length): string
    {
        $binary = '';
        while (\strlen($binary) < $length) {
            $part = \fread($this->stream, $length - \strlen($binary));
            if (!\is_string($part) || $part === '') {
                throw new \RuntimeException('Unable to read benchmark timing spill data.');
            }
            $binary .= $part;
        }
        return $binary;
    }

    private function releaseStorage(): void
    {
        if (\is_resource($this->stream)) {
            \fclose($this->stream);
        }
        $this->stream = null;
        $this->chunk = [];
        $this->runs = [];
    }
}
