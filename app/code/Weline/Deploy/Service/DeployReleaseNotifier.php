<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

use Weline\Deploy\Extends\NotificationTopicProvider;

/**
 * Emit per-phase Deploy progress via w_msg (WLS / Backend notification channels).
 * Fail-open: never throw into release/drain paths.
 */
class DeployReleaseNotifier
{
    public const TOPIC = NotificationTopicProvider::TOPIC_DEPLOY_RELEASE;

    public const PHASE_QUEUED = 'queued';
    public const PHASE_DRAIN_START = 'drain_start';
    public const PHASE_STARTED = 'started';
    public const PHASE_BACKUP = 'backup';
    public const PHASE_GIT_DONE = 'git_done';
    public const PHASE_POST_DEPLOY_START = 'post_deploy_start';
    public const PHASE_POST_DEPLOY_DONE = 'post_deploy_done';
    public const PHASE_STAMP_WRITTEN = 'stamp_written';
    public const PHASE_RELOAD = 'reload';
    public const PHASE_SUCCESS = 'success';
    public const PHASE_FAILED = 'failed';
    public const PHASE_DRAIN_SKIPPED = 'drain_skipped';
    public const PHASE_WEBHOOK_MISS = 'webhook_miss';
    public const PHASE_WORKDIR_BLOCKED = 'workdir_blocked';
    public const PHASE_ROLLBACK_STARTED = 'rollback_started';
    public const PHASE_ROLLBACK_SUCCESS = 'rollback_success';
    public const PHASE_ROLLBACK_FAILED = 'rollback_failed';

    /** @var list<string> */
    public const TERMINAL_PHASES = [
        self::PHASE_SUCCESS,
        self::PHASE_FAILED,
        self::PHASE_ROLLBACK_SUCCESS,
        self::PHASE_ROLLBACK_FAILED,
        self::PHASE_WEBHOOK_MISS,
        // workdir_blocked is NOT terminal — catch still emits failed/rollback_failed.
    ];

    /** @var array<string, string> */
    private const PHASE_TYPES = [
        self::PHASE_QUEUED => 'info',
        self::PHASE_DRAIN_START => 'info',
        self::PHASE_STARTED => 'info',
        self::PHASE_BACKUP => 'info',
        self::PHASE_GIT_DONE => 'info',
        self::PHASE_POST_DEPLOY_START => 'info',
        self::PHASE_POST_DEPLOY_DONE => 'info',
        self::PHASE_STAMP_WRITTEN => 'info',
        self::PHASE_RELOAD => 'info',
        self::PHASE_SUCCESS => 'success',
        self::PHASE_FAILED => 'error',
        self::PHASE_DRAIN_SKIPPED => 'warning',
        self::PHASE_WEBHOOK_MISS => 'warning',
        self::PHASE_WORKDIR_BLOCKED => 'error',
        self::PHASE_ROLLBACK_STARTED => 'info',
        self::PHASE_ROLLBACK_SUCCESS => 'success',
        self::PHASE_ROLLBACK_FAILED => 'error',
    ];

    /**
     * @param array<string, mixed> $ctx
     */
    public function notifyPhase(string $phase, array $ctx = []): void
    {
        try {
            if (!\function_exists('w_msg')) {
                return;
            }
            $phase = trim($phase);
            if ($phase === '' || !isset(self::PHASE_TYPES[$phase])) {
                return;
            }

            $releaseId = trim((string)($ctx['release_id'] ?? ''));
            $jobId = trim((string)($ctx['job_id'] ?? ''));
            $identity = $releaseId !== '' ? $releaseId : ($jobId !== '' ? $jobId : 'anon');
            $isTerminal = \in_array($phase, self::TERMINAL_PHASES, true);
            $dedupeKey = 'deploy_release:' . $identity . ':' . $phase;
            if ($isTerminal) {
                $dedupeKey .= ':' . (string)($ctx['dedupe_salt'] ?? (string)time());
            } elseif ($this->alreadyEmitted($dedupeKey)) {
                return;
            }

            $type = self::PHASE_TYPES[$phase];
            $title = $this->buildTitle($phase, $ctx);
            $content = $this->buildContent($phase, $ctx);
            w_msg(
                self::TOPIC,
                $type,
                $title,
                $content,
                [
                    'icon' => $type === 'error' ? 'warning' : ($type === 'success' ? 'check' : 'cloud-upload'),
                    'source_module' => 'Weline_Deploy',
                    'dedupe_key' => $dedupeKey,
                    'metadata' => [
                        'phase' => $phase,
                        'release_id' => $releaseId,
                        'job_id' => $jobId,
                        'ref' => (string)($ctx['ref'] ?? ''),
                        'deploy_version' => (string)($ctx['deploy_version'] ?? ''),
                    ],
                ]
            );
            if (!$isTerminal) {
                $this->markEmitted($dedupeKey);
            }
        } catch (\Throwable) {
            // fail-open
        }
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function buildTitle(string $phase, array $ctx): string
    {
        $ver = trim((string)($ctx['deploy_version'] ?? ''));
        $ref = trim((string)($ctx['ref'] ?? ''));
        $hint = $ver !== '' ? $ver : ($ref !== '' ? $ref : '');

        return match ($phase) {
            self::PHASE_QUEUED => (string)__('Deploy：Webhook 已入队%{1}', [$hint !== '' ? ' · ' . $hint : '']),
            self::PHASE_DRAIN_START => (string)__('Deploy：开始消费队列%{1}', [$hint !== '' ? ' · ' . $hint : '']),
            self::PHASE_STARTED => (string)__('Deploy：开始发布%{1}', [$hint !== '' ? ' · ' . $hint : '']),
            self::PHASE_BACKUP => (string)__('Deploy：备份中'),
            self::PHASE_GIT_DONE => (string)__('Deploy：Git 更新完成%{1}', [$hint !== '' ? ' · ' . $hint : '']),
            self::PHASE_POST_DEPLOY_START => (string)__('Deploy：后置命令开始'),
            self::PHASE_POST_DEPLOY_DONE => (string)__('Deploy：后置命令完成'),
            self::PHASE_STAMP_WRITTEN => (string)__('Deploy：版本戳已写入%{1}', [$hint !== '' ? ' · ' . $hint : '']),
            self::PHASE_RELOAD => (string)__('Deploy：服务重载'),
            self::PHASE_SUCCESS => (string)__('Deploy：发布成功%{1}', [$hint !== '' ? ' · ' . $hint : '']),
            self::PHASE_FAILED => (string)__('Deploy：发布失败%{1}', [$hint !== '' ? ' · ' . $hint : '']),
            self::PHASE_DRAIN_SKIPPED => (string)__('Deploy：队列任务跳过'),
            self::PHASE_WEBHOOK_MISS => (string)__('Deploy：远程 ref 超时未见 Webhook%{1}', [$hint !== '' ? ' · ' . $hint : '']),
            self::PHASE_WORKDIR_BLOCKED => (string)__('Deploy：工作树含 skip-worktree，已中止'),
            self::PHASE_ROLLBACK_STARTED => (string)__('Deploy：回滚开始'),
            self::PHASE_ROLLBACK_SUCCESS => (string)__('Deploy：回滚成功'),
            self::PHASE_ROLLBACK_FAILED => (string)__('Deploy：回滚失败'),
            default => (string)__('Deploy：%{1}', [$phase]),
        };
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function buildContent(string $phase, array $ctx): string
    {
        $lines = [];
        $map = [
            'phase' => $phase,
            'trigger' => (string)($ctx['trigger'] ?? ''),
            'ref' => (string)($ctx['ref'] ?? ''),
            'release_id' => (string)($ctx['release_id'] ?? ''),
            'job_id' => (string)($ctx['job_id'] ?? ''),
            'deploy_version' => (string)($ctx['deploy_version'] ?? ''),
            'git_commit' => (string)($ctx['git_commit'] ?? ''),
            'deploy_root' => (string)($ctx['deploy_root'] ?? ''),
            'command' => (string)($ctx['command'] ?? ''),
            'log_path' => (string)($ctx['log_path'] ?? ''),
            'waited_s' => (string)($ctx['waited_s'] ?? ''),
            'reason' => (string)($ctx['reason'] ?? ''),
        ];
        foreach ($map as $key => $value) {
            $value = trim($value);
            if ($value !== '') {
                $lines[] = $key . '=' . $value;
            }
        }
        $elapsed = (int)($ctx['elapsed_s'] ?? 0);
        if ($elapsed > 0) {
            $lines[] = 'elapsed_s=' . $elapsed;
        }
        $error = trim((string)($ctx['error_message'] ?? ''));
        if ($error !== '') {
            $lines[] = 'error=' . $this->clip($error, DeployReleaseHistoryService::ERROR_MESSAGE_MAX_CHARS);
        }
        $tail = trim((string)($ctx['output_tail'] ?? ''));
        if ($tail !== '') {
            $lines[] = 'output_tail=' . $this->clip($tail, DeployReleaseHistoryService::OUTPUT_TAIL_MAX_CHARS);
        }
        if (!empty($ctx['auto_rollback'])) {
            $lines[] = 'auto_rollback=1';
        }

        return implode("\n", $lines);
    }

    private function clip(string $text, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        if (mb_strlen($text, 'UTF-8') <= $max) {
            return $text;
        }

        return mb_substr($text, 0, max(1, $max - 1), 'UTF-8') . '…';
    }

    private function dedupeDir(): string
    {
        $dir = rtrim(BP, "/\\") . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'deploy'
            . DIRECTORY_SEPARATOR . 'notify-dedupe';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }

    private function alreadyEmitted(string $dedupeKey): bool
    {
        $path = $this->dedupeDir() . DIRECTORY_SEPARATOR . sha1($dedupeKey) . '.flag';

        return is_file($path);
    }

    private function markEmitted(string $dedupeKey): void
    {
        $path = $this->dedupeDir() . DIRECTORY_SEPARATOR . sha1($dedupeKey) . '.flag';
        @file_put_contents($path, $dedupeKey . "\n" . (string)time() . "\n");
    }
}
