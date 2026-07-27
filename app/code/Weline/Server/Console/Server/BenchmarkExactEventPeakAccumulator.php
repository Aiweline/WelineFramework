<?php
declare(strict_types=1);

namespace Weline\Server\Console\Server;

/**
 * Exact overlap peak accumulator for HTTP/2 and HTTP/3 transfer intervals.
 */
final class BenchmarkExactEventPeakAccumulator
{
    private const VALUE_BYTES = 8;
    private const READ_VALUES = 1024;

    /** @var list<int> */
    private array $chunk = [];

    /** @var list<array{offset:int,count:int}> */
    private array $runs = [];

    /** @var resource|null */
    private $stream = null;

    /** @var array{sample_count:int,peak_concurrent_streams:int,peak_observed_at_us:?int}|null */
    private ?array $summary = null;

    private int $sampleCount = 0;

    public function __construct(private readonly int $chunkSize = 16384)
    {
        if ($this->chunkSize < 4) {
            throw new \InvalidArgumentException('Benchmark event chunk size must be at least 4.');
        }
    }

    public function addInterval(int $startUs, int $endUs): void
    {
        if ($this->summary !== null) {
            throw new \LogicException('Cannot add benchmark multiplex events after finalization.');
        }
        if ($startUs <= 0 || $endUs <= $startUs) {
            return;
        }

        // Even keys are end events and odd keys are start events. Numeric sort
        // therefore preserves the existing same-timestamp end-before-start rule.
        $this->chunk[] = ($startUs * 2) + 1;
        $this->chunk[] = $endUs * 2;
        $this->sampleCount++;
        if (\count($this->chunk) >= $this->chunkSize) {
            $this->flushChunk();
        }
    }

    /** @return array{sample_count:int,peak_concurrent_streams:int,peak_observed_at_us:?int} */
    public function summarize(): array
    {
        if ($this->summary === null) {
            $this->summary = $this->buildSummary();
            $this->releaseStorage();
        }
        return $this->summary;
    }

    public function __destruct()
    {
        $this->releaseStorage();
    }

    /** @return array{sample_count:int,peak_concurrent_streams:int,peak_observed_at_us:?int} */
    private function buildSummary(): array
    {
        if ($this->sampleCount === 0) {
            return ['sample_count' => 0, 'peak_concurrent_streams' => 0, 'peak_observed_at_us' => null];
        }
        if ($this->runs === []) {
            \sort($this->chunk, SORT_NUMERIC);
            return $this->scanSortedEvents($this->chunk);
        }

        $this->flushChunk();
        if (!\is_resource($this->stream)) {
            throw new \RuntimeException('Benchmark multiplex spill stream is unavailable.');
        }

        $states = [];
        $heap = new \SplPriorityQueue();
        $heap->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
        foreach ($this->runs as $runId => $run) {
            $states[$runId] = $run + ['loaded' => 0, 'buffer' => [], 'buffer_index' => 0];
            $key = $this->readNextRunValue($states[$runId]);
            if ($key !== null) {
                $heap->insert(['run_id' => $runId, 'key' => $key], -$key);
            }
        }

        $concurrent = 0;
        $peak = 0;
        $peakAtUs = null;
        while (!$heap->isEmpty()) {
            $entry = $heap->extract();
            $data = (array)$entry['data'];
            $key = (int)$data['key'];
            $concurrent += (($key & 1) === 0) ? -1 : 1;
            if ($concurrent > $peak) {
                $peak = $concurrent;
                $peakAtUs = \intdiv($key, 2);
            }
            $runId = (int)$data['run_id'];
            $next = $this->readNextRunValue($states[$runId]);
            if ($next !== null) {
                $heap->insert(['run_id' => $runId, 'key' => $next], -$next);
            }
        }

        return [
            'sample_count' => $this->sampleCount,
            'peak_concurrent_streams' => $peak,
            'peak_observed_at_us' => $peakAtUs,
        ];
    }

    /**
     * @param list<int> $events
     * @return array{sample_count:int,peak_concurrent_streams:int,peak_observed_at_us:?int}
     */
    private function scanSortedEvents(array $events): array
    {
        $concurrent = 0;
        $peak = 0;
        $peakAtUs = null;
        foreach ($events as $key) {
            $concurrent += (($key & 1) === 0) ? -1 : 1;
            if ($concurrent > $peak) {
                $peak = $concurrent;
                $peakAtUs = \intdiv($key, 2);
            }
        }
        return [
            'sample_count' => $this->sampleCount,
            'peak_concurrent_streams' => $peak,
            'peak_observed_at_us' => $peakAtUs,
        ];
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
                throw new \RuntimeException('Unable to create benchmark multiplex spill stream.');
            }
            $this->stream = $stream;
        }
        if (\fseek($this->stream, 0, SEEK_END) !== 0) {
            throw new \RuntimeException('Unable to seek benchmark multiplex spill stream.');
        }
        $offset = \ftell($this->stream);
        if (!\is_int($offset)) {
            throw new \RuntimeException('Unable to determine benchmark multiplex spill offset.');
        }

        $binary = '';
        foreach ($this->chunk as $key) {
            $binary .= \pack('q', $key);
        }
        $this->writeAll($binary);
        $this->runs[] = ['offset' => $offset, 'count' => \count($this->chunk)];
        $this->chunk = [];
    }

    /**
     * @param array{offset:int,count:int,loaded:int,buffer:list<int>,buffer_index:int} $state
     */
    private function readNextRunValue(array &$state): ?int
    {
        if ($state['buffer_index'] >= \count($state['buffer'])) {
            if ($state['loaded'] >= $state['count']) {
                return null;
            }
            $readCount = \min(self::READ_VALUES, $state['count'] - $state['loaded']);
            $offset = $state['offset'] + ($state['loaded'] * self::VALUE_BYTES);
            if (\fseek($this->stream, $offset, SEEK_SET) !== 0) {
                throw new \RuntimeException('Unable to seek benchmark multiplex run.');
            }
            $binary = $this->readExact($readCount * self::VALUE_BYTES);
            $values = \unpack('q*', $binary);
            if (!\is_array($values) || \count($values) !== $readCount) {
                throw new \RuntimeException('Unable to decode benchmark multiplex run.');
            }
            $state['buffer'] = \array_values($values);
            $state['buffer_index'] = 0;
            $state['loaded'] += $readCount;
        }

        return (int)$state['buffer'][$state['buffer_index']++];
    }

    private function writeAll(string $binary): void
    {
        $length = \strlen($binary);
        $written = 0;
        while ($written < $length) {
            $bytes = \fwrite($this->stream, \substr($binary, $written));
            if (!\is_int($bytes) || $bytes <= 0) {
                throw new \RuntimeException('Unable to write benchmark multiplex spill data.');
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
                throw new \RuntimeException('Unable to read benchmark multiplex spill data.');
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
