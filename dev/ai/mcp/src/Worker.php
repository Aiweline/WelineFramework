<?php

declare(strict_types=1);

namespace LearningMcp;

use RuntimeException;
use Throwable;

final class Worker
{
    private bool $running = true;

    public function __construct(
        private readonly Store $store,
        private readonly Analyzer $analyzer,
        private readonly Config $config,
    ) {
    }

    /** @return array<string, mixed> */
    public function runOnce(bool $scanIdleSessions = true): array
    {
        $enqueued = $scanIdleSessions
            ? $this->store->enqueueIdleSessions($this->config->duration('scheduler.session_idle_after'))
            : [];
        $maximumAttempts = (int) $this->config->get('scheduler.max_attempts', 5);
        $job = $this->store->claimJob($this->config->duration('scheduler.lease'), $maximumAttempts);
        if ($job === null) {
            return ['processed' => false, 'idle_jobs_enqueued' => $enqueued];
        }
        try {
            $result = $this->analyzer->processJob($job);
            $this->store->completeJob((string) $job['id'], $result);

            return [
                'processed' => true,
                'job_id' => $job['id'],
                'job_type' => $job['job_type'],
                'attempt' => $job['attempt'],
                'result' => $result,
                'idle_jobs_enqueued' => $enqueued,
            ];
        } catch (Throwable $exception) {
            $retryable = $this->retryable($exception);
            $this->store->failJob($job, $exception, $retryable, $maximumAttempts);
            throw new RuntimeException(
                sprintf('Process job %s: %s', $job['id'], $exception->getMessage()),
                0,
                $exception,
            );
        }
    }

    /** @return array<string, mixed> */
    public function drain(int $maximumJobs = 100): array
    {
        $maximumJobs = max(1, min(10_000, $maximumJobs));
        $processed = [];
        $errors = [];
        for ($index = 0; $index < $maximumJobs; ++$index) {
            try {
                $result = $this->runOnce($index === 0);
            } catch (Throwable $exception) {
                $errors[] = Redactor::string($exception->getMessage())[0];
                continue;
            }
            if (!$result['processed']) {
                break;
            }
            $processed[] = $result;
        }

        return [
            'processed_count' => count($processed),
            'processed' => $processed,
            'errors' => $errors,
            'checked_at' => Clock::now(),
        ];
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        $this->installSignalHandlers();
        $cycles = 0;
        $processed = 0;
        while ($this->running) {
            ++$cycles;
            try {
                $result = $this->runOnce(true);
                if ($result['processed']) {
                    ++$processed;
                    continue;
                }
            } catch (Throwable $exception) {
                self::appendLog($this->config, 'worker cycle: ' . $exception->getMessage());
            }
            $this->wait($this->config->duration('scheduler.poll_interval'));
        }

        return ['cycles' => $cycles, 'processed_count' => $processed, 'stopped_at' => Clock::now()];
    }

    /**
     * Fork a one-shot worker after a Stop Hook has returned its response.
     * The caller must close inherited database handles before invoking this method.
     */
    public static function spawnDrain(?string $configPath, ?string $dataDir, int $maximumJobs = 100): ?int
    {
        if (!function_exists('pcntl_fork')) {
            return null;
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            return null;
        }
        if ($pid > 0) {
            return $pid;
        }
        if (function_exists('posix_setsid')) {
            posix_setsid();
        }
        foreach ([STDIN, STDOUT, STDERR] as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        try {
            $config = Config::load($configPath, $dataDir);
            $store = new Store($config);
            try {
                (new self($store, new Analyzer($store, $config), $config))->drain($maximumJobs);
            } finally {
                $store->close();
            }
        } catch (Throwable $exception) {
            try {
                $fallback = Config::load($configPath, $dataDir);
                self::appendLog($fallback, 'Stop worker: ' . $exception->getMessage());
            } catch (Throwable) {
            }
        }

        return 0;
    }

    private function retryable(Throwable $exception): bool
    {
        if ($exception instanceof ToolException) {
            return $exception->retryable;
        }
        $message = strtolower($exception->getMessage());
        foreach (['timeout', 'temporar', 'database is locked', 'network', 'http 429', 'http 5', 'responses api'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        $stop = function (): void {
            $this->running = false;
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }

    private function wait(int $seconds): void
    {
        if ($seconds <= 0 || !$this->running) {
            return;
        }
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            usleep(min($seconds, 60) * 1_000_000);
            return;
        }
        $read = [$pair[0]];
        $write = null;
        $except = null;
        @stream_select($read, $write, $except, min($seconds, 60));
        fclose($pair[0]);
        fclose($pair[1]);
    }

    private static function appendLog(Config $config, string $message): void
    {
        [$message] = Redactor::string($message);
        $line = sprintf("%s %s\n", Clock::now(), Text::truncate($message, 2_000));
        @file_put_contents($config->dataDir() . '/learningd.log', $line, FILE_APPEND | LOCK_EX);
    }
}
