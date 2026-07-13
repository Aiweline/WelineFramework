<?php

declare(strict_types=1);

namespace Weline\Server\Service\Telemetry;

use Weline\Framework\App\Env;
use Weline\Server\IPC\ChildControl\ChildControlClientInterface;
use Weline\Server\IPC\ControlMessage;

/** Worker-side facade that keeps ordinary telemetry off the per-request IPC path. */
final class WorkerTelemetryReporter
{
    private const IMMEDIATE_MIN_INTERVAL_SECONDS = 0.05;

    private static ?self $instance = null;

    /** @var array<string, int|string>|null */
    private ?array $pendingImmediate = null;

    private float $lastImmediateSentAt = 0.0;

    private function __construct(
        private readonly string $instanceName,
        private readonly WorkerTelemetryBuffer $buffer,
    ) {
    }

    public static function boot(string $instanceName): self
    {
        $batchSize = \max(8, \min(1024, (int)(Env::get('wls.telemetry.batch_size', 64) ?: 64)));
        $flushMs = \max(50, \min(5000, (int)(Env::get('wls.telemetry.flush_interval_ms', 250) ?: 250)));
        $immediateMs = \max(100, \min(600_000, (int)(
            Env::get('wls.telemetry.immediate_latency_ms', Env::get('wls.slow_request_threshold_ms', 1000))
            ?: 1000
        )));

        return self::$instance = new self(
            $instanceName,
            new WorkerTelemetryBuffer($instanceName, $batchSize, $flushMs / 1000, $immediateMs),
        );
    }

    public static function instance(string $instanceName = 'default'): self
    {
        return self::$instance ?? self::boot($instanceName);
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function record(
        ?ChildControlClientInterface $client,
        string $host,
        int $status,
        int $latencyMs,
        int $bytesOut,
    ): void {
        $result = $this->buffer->record($host, $status, $latencyMs, $bytesOut);
        if (!$client instanceof ChildControlClientInterface || !$client->isConnected()) {
            return;
        }

        $immediate = $result['immediate'];
        if (\is_array($immediate)) {
            // Keep only the latest unsent anomaly. The first event is immediate;
            // bursts are coalesced into one bounded slot instead of one IPC write
            // per failed request.
            $this->pendingImmediate = $immediate;
        }
        $this->flushImmediateIfDue($client);
        $this->sendBatch($client, $result['batch']);
    }

    public function tick(?ChildControlClientInterface $client): void
    {
        if (!$client instanceof ChildControlClientInterface || !$client->isConnected()) {
            return;
        }
        $this->flushImmediateIfDue($client);
        $this->sendBatch($client, $this->buffer->flushIfDue());
    }

    public function flush(?ChildControlClientInterface $client): void
    {
        if (!$client instanceof ChildControlClientInterface || !$client->isConnected()) {
            $this->pendingImmediate = null;
            $this->buffer->drain();
            return;
        }
        $this->flushImmediateIfDue($client, true);
        $this->sendBatch($client, $this->buffer->drain());
    }

    private function flushImmediateIfDue(ChildControlClientInterface $client, bool $force = false): void
    {
        if ($this->pendingImmediate === null) {
            return;
        }

        $now = \microtime(true);
        if (!$force
            && $this->lastImmediateSentAt > 0.0
            && ($now - $this->lastImmediateSentAt) < self::IMMEDIATE_MIN_INTERVAL_SECONDS) {
            return;
        }

        $sample = $this->pendingImmediate;
        if (!$client->send(ControlMessage::telemetry(
            $this->instanceName,
            (string)$sample['host'],
            (int)$sample['status'],
            (int)$sample['latency_ms'],
            (int)$sample['bytes_out'],
            (int)$sample['ts'],
        ), false)) {
            return;
        }

        $this->pendingImmediate = null;
        $this->lastImmediateSentAt = $now;
    }

    /** @param list<array<string, int|string>> $samples */
    private function sendBatch(ChildControlClientInterface $client, array $samples): void
    {
        if ($samples === []) {
            return;
        }
        foreach (\array_chunk($samples, 256) as $chunk) {
            // Telemetry is observational: write-queue pressure drops a batch but
            // must never tear down the authoritative child control connection.
            $client->send(ControlMessage::telemetryBatch($this->instanceName, $chunk), false);
        }
    }
}
