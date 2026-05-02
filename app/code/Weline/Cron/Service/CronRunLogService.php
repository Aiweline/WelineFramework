<?php

declare(strict_types=1);

namespace Weline\Cron\Service;

use Weline\Cron\Helper\CronStatus;
use Weline\Cron\Helper\Process;
use Weline\Cron\Model\CronTask;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Manager\ObjectManager;

/**
 * 定时调度写入的 var/cron/{execute_name}.log 及 history 归档列表/读取。
 */
final class CronRunLogService
{
    private const CONTENT_MAX_BYTES = 2097152;

    public function isValidExecuteName(string $executeName): bool
    {
        $executeName = \trim($executeName);
        if ($executeName === '') {
            return false;
        }
        if (\str_contains($executeName, '/') || \str_contains($executeName, '\\') || \str_contains($executeName, '..')) {
            return false;
        }

        return !\preg_match('/[\x00-\x1F\x7F]/u', $executeName);
    }

    private function resolveTaskByIdentifier(string $identifier): ?CronTask
    {
        /** @var CronTask $task */
        $task = ObjectManager::make(CronTask::class)->reset()
            ->where(CronTask::schema_fields_EXECUTE_NAME, $identifier)
            ->find()
            ->fetch();
        if ($task->getId()) {
            return $task;
        }

        /** @var CronTask $byName */
        $byName = ObjectManager::make(CronTask::class)->reset()
            ->where(CronTask::schema_fields_NAME, $identifier)
            ->find()
            ->fetch();

        return $byName->getId() ? $byName : null;
    }

    public function liveLogPath(string $executeName): string
    {
        $base = Process::logBasenameForExecuteName($executeName);

        return Env::VAR_DIR . \DIRECTORY_SEPARATOR . 'log' . \DIRECTORY_SEPARATOR . 'cron' . \DIRECTORY_SEPARATOR . $base . '.log';
    }

    public function historyDir(string $executeName): string
    {
        $base = Process::logBasenameForExecuteName($executeName);

        return Env::VAR_DIR . \DIRECTORY_SEPARATOR . 'log' . \DIRECTORY_SEPARATOR . 'cron' . \DIRECTORY_SEPARATOR . 'history' . \DIRECTORY_SEPARATOR . $base;
    }

    /**
     * @return array{success: bool, message?: string, task_running?: bool, live_exists?: bool, live_size?: int, items?: list<array{file: string, mtime: int, size: int, label: string}>}
     */
    public function listForExecuteName(string $executeName): array
    {
        if (!$this->isValidExecuteName($executeName)) {
            return ['success' => false, 'message' => (string) \__('参数 execute_name 无效')];
        }
        $task = $this->resolveTaskByIdentifier($executeName);
        if (!$task) {
            return ['success' => false, 'message' => (string) \__('任务不存在')];
        }
        $executeName = \trim((string) ($task->getData(CronTask::schema_fields_EXECUTE_NAME) ?? $executeName));
        $live = $this->liveLogPath($executeName);
        $liveExists = \is_file($live);
        $liveSize = $liveExists ? (int) \filesize($live) : 0;
        $status = (string) ($task->getData(CronTask::schema_fields_STATUS) ?? '');
        $pid = (int) ($task->getData(CronTask::schema_fields_PID) ?? 0);
        $taskRunning = $status === CronStatus::RUNNING->value && $pid > 0 && Process::isProcessRunning($pid);

        $dir = $this->historyDir($executeName);
        $items = [];
        if (\is_dir($dir)) {
            $files = \glob($dir . \DIRECTORY_SEPARATOR . '*.log') ?: [];
            foreach ($files as $f) {
                if (!\is_file($f)) {
                    continue;
                }
                $bn = \basename($f);
                $items[] = [
                    'file' => $bn,
                    'mtime' => (int) \filemtime($f),
                    'size' => (int) \filesize($f),
                    'label' => \date('Y-m-d H:i:s', (int) \filemtime($f)) . ' · ' . $this->formatBytes((int) \filesize($f)),
                ];
            }
            \usort($items, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
            $items = \array_slice($items, 0, 100);
        }

        return [
            'success' => true,
            'task_running' => $taskRunning,
            'live_exists' => $liveExists && $liveSize > 0,
            'live_size' => $liveSize,
            'items' => $items,
        ];
    }

    /**
     * @return array{success: bool, message?: string, content?: string, truncated?: bool}
     */
    public function readHistoryFile(string $executeName, string $basename): array
    {
        if (!$this->isValidExecuteName($executeName)) {
            return ['success' => false, 'message' => (string) \__('参数 execute_name 无效')];
        }
        if ($basename === '' || \str_contains($basename, '/') || \str_contains($basename, '\\') || \str_contains($basename, '..')) {
            return ['success' => false, 'message' => (string) \__('参数 file 无效')];
        }
        if (!\str_ends_with($basename, '.log')) {
            return ['success' => false, 'message' => (string) \__('参数 file 无效')];
        }
        $full = $this->historyDir($executeName) . \DIRECTORY_SEPARATOR . $basename;
        $real = \realpath($full);
        $dirReal = \realpath($this->historyDir($executeName));
        if ($real === false || $dirReal === false || !\str_starts_with($real, $dirReal . \DIRECTORY_SEPARATOR)) {
            return ['success' => false, 'message' => (string) \__('日志文件不存在')];
        }
        $size = (int) \filesize($real);
        $truncated = $size > self::CONTENT_MAX_BYTES;
        $readLen = $truncated ? self::CONTENT_MAX_BYTES : $size;
        $content = (string) \file_get_contents($real, false, null, 0, $readLen);

        return ['success' => true, 'content' => $content, 'truncated' => $truncated];
    }

    /**
     * @return array{success: bool, message?: string, path?: string}
     */
    public function resolveLiveLogForStream(string $executeName): array
    {
        if (!$this->isValidExecuteName($executeName)) {
            return ['success' => false, 'message' => (string) \__('参数 execute_name 无效')];
        }
        $task = $this->resolveTaskByIdentifier($executeName);
        if (!$task) {
            return ['success' => false, 'message' => (string) \__('任务不存在')];
        }
        $executeName = \trim((string) ($task->getData(CronTask::schema_fields_EXECUTE_NAME) ?? $executeName));
        $path = $this->liveLogPath($executeName);
        if (!\is_file($path)) {
            @\touch($path);
        }

        return ['success' => true, 'path' => $path];
    }

    public function isTaskProcessRunning(string $executeName): bool
    {
        $task = $this->resolveTaskByIdentifier($executeName);
        if (!$task) {
            return false;
        }
        $status = (string) ($task->getData(CronTask::schema_fields_STATUS) ?? '');
        $pid = (int) ($task->getData(CronTask::schema_fields_PID) ?? 0);

        return $status === CronStatus::RUNNING->value && $pid > 0 && Process::isProcessRunning($pid);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return \round($bytes / 1024, 1) . ' KB';
        }

        return \round($bytes / 1048576, 2) . ' MB';
    }

    private const TAIL_CHUNK = 98304;

    /** 当前 var/cron/{name}.log：先推已有内容，任务仍运行时继续 tail，否则短空闲后结束 */
    public function streamLiveLogTail(string $executeName, SseWriter $sse): void
    {
        $r = $this->resolveLiveLogForStream($executeName);
        if (!$r['success']) {
            $sse->start();
            $sse->sendError((string) ($r['message'] ?? \__('未知错误')));
            $sse->complete(['exit_code' => -1]);

            return;
        }
        $path = (string) $r['path'];
        $sse->start();
        $sse->sendEvent('start', [
            'message' => (string) \__('调度输出日志（实时）'),
            'execute_name' => $executeName,
        ]);
        $offset = 0;
        $idleWhenNotRunning = 0;
        while ($sse->isAlive()) {
            \clearstatcache(true, $path);
            $size = \is_file($path) ? (int) \filesize($path) : 0;
            if ($size > $offset) {
                $fh = @\fopen($path, 'rb');
                if (\is_resource($fh)) {
                    \fseek($fh, $offset);
                    $data = (string) \fread($fh, $size - $offset);
                    \fclose($fh);
                    $offset = $size;
                    $this->emitLogChunks($sse, $data);
                }
                $idleWhenNotRunning = 0;
            }
            $running = $this->isTaskProcessRunning($executeName);
            if (!$running) {
                $idleWhenNotRunning++;
                if ($idleWhenNotRunning >= 8) {
                    break;
                }
            } else {
                $idleWhenNotRunning = 0;
            }
            \Weline\Framework\Runtime\SchedulerSystem::usleep(400000);
            $sse->maybeHeartbeat();
        }
        $sse->complete([
            'exit_code' => 0,
            'message' => (string) \__('日志流已结束'),
        ]);
    }

    private function emitLogChunks(SseWriter $sse, string $data): void
    {
        while ($data !== '') {
            $take = \strlen($data) > self::TAIL_CHUNK ? \substr($data, 0, self::TAIL_CHUNK) : $data;
            $data = \strlen($data) > self::TAIL_CHUNK ? \substr($data, self::TAIL_CHUNK) : '';
            $sse->sendEvent('chunk', ['content' => $take, 'stream' => 'stdout']);
        }
    }
}
