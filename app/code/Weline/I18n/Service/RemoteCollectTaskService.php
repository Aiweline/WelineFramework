<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\App\Env;
use Weline\I18n\Queue\RemoteDictionaryCollectQueue;

/**
 * 远程词典收集任务：CSPRNG task_id + 所有者绑定 + 单飞 + 24h TTL。
 */
final class RemoteCollectTaskService
{
    public const STATUS_STARTING = 'starting';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';

    private const TTL_SECONDS = 86400;
    private const ACTIVE_LOCK = '_active_collect.lock';

    public function __construct(
        private readonly DictionaryCollectService $collectService,
    ) {
    }

    /**
     * @return array{task_id:string}
     */
    public function start(string $ownerKey, int $websiteId = 0): array
    {
        $ownerKey = trim($ownerKey);
        if ($ownerKey === '') {
            throw new \InvalidArgumentException((string)__('缺少任务所有者'));
        }

        $this->expireStale();
        if ($this->findActiveTaskId() !== null) {
            throw new \RuntimeException((string)__('已有进行中的远程收集任务'), 422);
        }

        $taskId = bin2hex(random_bytes(16));
        $now = time();
        $record = [
            'task_id' => $taskId,
            'owner_key' => $ownerKey,
            'website_id' => $websiteId,
            'status' => self::STATUS_STARTING,
            'percent' => 0,
            'message' => (string)__('任务已创建'),
            'error' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $now + self::TTL_SECONDS,
        ];
        $this->writeTask($taskId, $record);
        $this->writeActiveLock($taskId);

        try {
            $created = w_query('queue', 'create', [
                'class' => RemoteDictionaryCollectQueue::class,
                'name' => (string)__('远程词典收集'),
                'module' => 'Weline_I18n',
                'content' => [
                    'task_id' => $taskId,
                    'owner_key' => $ownerKey,
                    'website_id' => $websiteId,
                ],
                'status' => 'pending',
                'auto' => true,
                // 由队列调度器拉取；禁止 create 同步跑 collect（词典扫描会阻塞 REST）
                'dispatch' => false,
                'biz_key' => 'i18n:remote_collect:' . $taskId,
            ]);
            if (!is_array($created) && !is_object($created)) {
                throw new \RuntimeException((string)__('入队失败'));
            }
        } catch (\Throwable $e) {
            $record['status'] = self::STATUS_FAILED;
            $record['error'] = $e->getMessage();
            $record['message'] = (string)__('入队失败');
            $record['updated_at'] = time();
            $this->writeTask($taskId, $record);
            $this->clearActiveLock($taskId);
            throw $e;
        }

        w_log_info('remote collect started', [
            'task_id' => $taskId,
            'website_id' => $websiteId,
            'owner_bound' => true,
        ], 'i18n');

        return ['task_id' => $taskId];
    }

    /**
     * @return array{task_id:string,status:string,percent:int,message:string,error:?string,owner_bound:bool}
     */
    public function status(string $taskId, string $ownerKey): array
    {
        $taskId = strtolower(trim($taskId));
        $ownerKey = trim($ownerKey);
        if ($taskId === '' || !preg_match('/^[a-f0-9]{32}$/', $taskId)) {
            throw new \RuntimeException((string)__('任务不存在'), 404);
        }

        $this->expireStale();
        $record = $this->readTask($taskId);
        if ($record === null) {
            throw new \RuntimeException((string)__('任务不存在'), 404);
        }
        if (!hash_equals((string)($record['owner_key'] ?? ''), $ownerKey)) {
            throw new \RuntimeException((string)__('任务不存在'), 404);
        }

        if ((int)($record['expires_at'] ?? 0) < time()
            && !in_array((string)($record['status'] ?? ''), [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_EXPIRED], true)
        ) {
            $record['status'] = self::STATUS_EXPIRED;
            $record['message'] = (string)__('任务已过期');
            $record['updated_at'] = time();
            $this->writeTask($taskId, $record);
            $this->clearActiveLock($taskId);
        }

        return [
            'task_id' => $taskId,
            'status' => (string)($record['status'] ?? self::STATUS_FAILED),
            'percent' => (int)($record['percent'] ?? 0),
            'message' => (string)($record['message'] ?? ''),
            'error' => isset($record['error']) && $record['error'] !== null && $record['error'] !== ''
                ? (string)$record['error']
                : null,
            'owner_bound' => true,
        ];
    }

    public function runCollect(string $taskId): void
    {
        $record = $this->readTask($taskId);
        if ($record === null) {
            return;
        }

        $this->patch($taskId, [
            'status' => self::STATUS_RUNNING,
            'percent' => 1,
            'message' => (string)__('开始收集'),
        ]);

        $localeCode = (string)(Env::default_LANGUAGE_CODE ?: 'zh_Hans_CN');
        $result = $this->collectService->collect(
            $localeCode,
            function (string $message, ?int $progress = null) use ($taskId): void {
                $this->patch($taskId, [
                    'status' => self::STATUS_RUNNING,
                    'percent' => max(1, min(99, (int)($progress ?? 50))),
                    'message' => $message,
                ]);
            },
            false,
        );

        if (($result['success'] ?? false) === true) {
            $this->patch($taskId, [
                'status' => self::STATUS_COMPLETED,
                'percent' => 100,
                'message' => (string)($result['message'] ?? __('收集完成')),
                'error' => null,
            ]);
        } else {
            $this->patch($taskId, [
                'status' => self::STATUS_FAILED,
                'percent' => (int)($this->readTask($taskId)['percent'] ?? 0),
                'message' => (string)($result['message'] ?? __('收集失败')),
                'error' => (string)($result['error'] ?? $result['message'] ?? __('收集失败')),
            ]);
        }
        $this->clearActiveLock($taskId);
    }

    /** @param array<string,mixed> $patch */
    private function patch(string $taskId, array $patch): void
    {
        $record = $this->readTask($taskId);
        if ($record === null) {
            return;
        }
        foreach ($patch as $k => $v) {
            $record[$k] = $v;
        }
        $record['updated_at'] = time();
        $this->writeTask($taskId, $record);
    }

    private function findActiveTaskId(): ?string
    {
        $lockFile = $this->dir() . self::ACTIVE_LOCK;
        if (!is_file($lockFile)) {
            return null;
        }
        $taskId = trim((string)@file_get_contents($lockFile));
        if ($taskId === '' || !preg_match('/^[a-f0-9]{32}$/', $taskId)) {
            @unlink($lockFile);

            return null;
        }
        $record = $this->readTask($taskId);
        if ($record === null) {
            @unlink($lockFile);

            return null;
        }
        $status = (string)($record['status'] ?? '');
        if (in_array($status, [self::STATUS_STARTING, self::STATUS_RUNNING], true)
            && (int)($record['expires_at'] ?? 0) >= time()
        ) {
            return $taskId;
        }
        @unlink($lockFile);

        return null;
    }

    private function expireStale(): void
    {
        $dir = $this->dir();
        foreach (glob($dir . '*.json') ?: [] as $file) {
            $raw = @file_get_contents($file);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }
            $status = (string)($data['status'] ?? '');
            if (in_array($status, [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_EXPIRED], true)) {
                continue;
            }
            if ((int)($data['expires_at'] ?? 0) >= time()) {
                continue;
            }
            $data['status'] = self::STATUS_EXPIRED;
            $data['message'] = (string)__('任务已过期');
            $data['updated_at'] = time();
            $taskId = (string)($data['task_id'] ?? '');
            if ($taskId !== '') {
                $this->writeTask($taskId, $data);
                $this->clearActiveLock($taskId);
            }
        }
    }

    /** @return array<string,mixed>|null */
    private function readTask(string $taskId): ?array
    {
        $path = $this->dir() . $taskId . '.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /** @param array<string,mixed> $record */
    private function writeTask(string $taskId, array $record): void
    {
        $dir = $this->dir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create remote collect task directory');
        }
        $path = $dir . $taskId . '.json';
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($tmp, $json) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to persist remote collect task');
        }
    }

    private function writeActiveLock(string $taskId): void
    {
        $path = $this->dir() . self::ACTIVE_LOCK;
        file_put_contents($path, $taskId);
    }

    private function clearActiveLock(string $taskId): void
    {
        $path = $this->dir() . self::ACTIVE_LOCK;
        if (!is_file($path)) {
            return;
        }
        $current = trim((string)@file_get_contents($path));
        if ($current === '' || hash_equals($current, $taskId)) {
            @unlink($path);
        }
    }

    private function dir(): string
    {
        $base = defined('BP') ? (string)BP : (getcwd() ?: '.');

        return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'var' . DIRECTORY_SEPARATOR . 'i18n' . DIRECTORY_SEPARATOR . 'remote-collect' . DIRECTORY_SEPARATOR;
    }
}
