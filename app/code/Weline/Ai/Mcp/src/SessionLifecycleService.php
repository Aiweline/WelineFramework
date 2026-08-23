<?php

declare(strict_types=1);

namespace LearningMcp;

use Closure;
use Throwable;

final class SessionLifecycleService
{
    private readonly Closure $finalLearner;

    public function __construct(
        private readonly Store $store,
        private readonly Analyzer $analyzer,
        private readonly Config $config,
        ?callable $finalLearner = null,
    ) {
        $this->finalLearner = $finalLearner === null
            ? fn(string $sessionId): array => $this->analyzer->analyzeSession($sessionId)
            : Closure::fromCallable($finalLearner);
    }

    /** @return array<string, mixed> */
    public function archive(string $sessionId, string $archiveKind = 'explicit'): array
    {
        $frozen = $this->store->freezeSessionForArchive($sessionId, $archiveKind . '_archive');
        $learningStatus = 'skipped';
        $learning = [];
        $learningError = '';
        try {
            $learning = $this->runFinalLearning($sessionId);
            $learningStatus = 'succeeded';
        } catch (ToolException $exception) {
            $learningStatus = $exception->errorCode === 'FINAL_LEARNING_TIMEOUT' ? 'timed_out' : 'failed';
            $learningError = Text::truncate(Redactor::string($exception->getMessage())[0], 800);
        } catch (Throwable $exception) {
            $learningStatus = 'failed';
            $learningError = Text::truncate(Redactor::string($exception->getMessage())[0], 800);
        }
        $counts = $this->store->compactAndPurgeSession(
            $sessionId,
            (int) $frozen['lifecycle_generation'],
            $archiveKind,
            $learningStatus,
        );

        return [
            'archived' => true,
            'archive_kind' => $archiveKind,
            'final_learning_status' => $learningStatus,
            'final_learning' => $learning,
            'final_learning_error' => $learningError,
            'cleared_counts' => $counts,
        ];
    }

    /** @return array<string, mixed> */
    public function sweep(?string $owner = null, bool $force = true): array
    {
        $owner = trim((string) $owner);
        if ($owner === '') {
            $owner = 'lifecycle-' . getmypid() . '-' . substr(bin2hex(random_bytes(6)), 0, 12);
        }
        $interval = $this->config->duration('privacy.sweep_interval');
        if (!$force && !$this->store->maintenanceDue('session_lifecycle', $interval)) {
            return [
                'lease_acquired' => false,
                'maintenance_due' => false,
                'sessions_purged' => 0,
                'owner' => $owner,
            ];
        }
        $leaseSeconds = max(60, $interval * 2);
        if (!$this->store->acquireMaintenanceLease('session_lifecycle', $owner, $leaseSeconds)) {
            return ['lease_acquired' => false, 'sessions_purged' => 0, 'owner' => $owner];
        }
        $metrics = [
            'lease_acquired' => true,
            'owner' => $owner,
            'sessions_scanned' => 0,
            'sessions_purged' => 0,
            'final_learning_succeeded' => 0,
            'final_learning_failed' => 0,
            'final_learning_timed_out' => 0,
            'errors' => [],
        ];
        try {
            $sessions = $this->store->sessionsReadyForArchive(
                (int) $this->config->get('privacy.sweep_batch_size', 50),
            );
            $metrics['sessions_scanned'] = count($sessions);
            foreach ($sessions as $session) {
                try {
                    $result = $this->archive((string) $session['id'], 'ttl');
                    ++$metrics['sessions_purged'];
                    $status = (string) $result['final_learning_status'];
                    if ($status === 'succeeded') {
                        ++$metrics['final_learning_succeeded'];
                    } elseif ($status === 'timed_out') {
                        ++$metrics['final_learning_timed_out'];
                    } else {
                        ++$metrics['final_learning_failed'];
                    }
                } catch (Throwable $exception) {
                    $metrics['errors'][] = Redactor::string($exception->getMessage())[0];
                }
            }
            $metrics['retention'] = $this->store->cleanupLifecycleRetention();
            $metrics['sqlite'] = $this->store->maintainSqlite();
            $metrics['completed_at'] = Clock::now();
            $this->store->releaseMaintenanceLease('session_lifecycle', $owner, $metrics);

            return $metrics;
        } catch (Throwable $exception) {
            $metrics['errors'][] = Redactor::string($exception->getMessage())[0];
            $this->store->releaseMaintenanceLease(
                'session_lifecycle',
                $owner,
                $metrics,
                $exception instanceof ToolException ? $exception->errorCode : 'MAINTENANCE_FAILED',
            );
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function runFinalLearning(string $sessionId): array
    {
        $timeout = max(1, $this->config->duration('privacy.final_learning_timeout'));
        if (!function_exists('pcntl_alarm')
            || !function_exists('pcntl_signal')
            || !function_exists('pcntl_async_signals')) {
            throw new ToolException(
                'FINAL_LEARNING_TIMEOUT',
                'Final learning deadline enforcement is unavailable; raw-data purge must continue',
                true,
            );
        }
        $previous = function_exists('pcntl_signal_get_handler')
            ? pcntl_signal_get_handler(SIGALRM)
            : SIG_DFL;
        $wasAsync = pcntl_async_signals();
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): void {
            throw new ToolException('FINAL_LEARNING_TIMEOUT', 'Final learning exceeded its archive deadline', true);
        });
        pcntl_alarm($timeout);
        try {
            return ($this->finalLearner)($sessionId);
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previous);
            pcntl_async_signals($wasAsync);
        }
    }
}
