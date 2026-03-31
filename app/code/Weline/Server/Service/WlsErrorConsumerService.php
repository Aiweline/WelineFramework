<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Server\Console\Server\WlsErrorScanner;

/**
 * WLS 错误消费服务
 *
 * 职责：
 * 1. 从 tasks.json 中原子申领最早的 wls_fix_* 任务（防并发）
 * 2. 按 error_timestamp ASC 有序消费（FIFO）
 * 3. 调用 AI 修复错误
 * 4. 消费完成后从 tasks.json 删除任务
 * 5. 最多每次消费 N 个（可配置）
 */
class WlsErrorConsumerService
{
    private const TASKS_FILE = 'dev/ai/agents/tasks.json';

    /** 每次最多消费任务数 */
    private int $maxConsumePerRun = 5;

    /** 申领锁文件 */
    private string $lockFile;

    /** 任务池数据 */
    private array $pool = [];

    private bool $verbose = false;

    public function __construct()
    {
        $this->lockFile = BP . '/var/cache/wls_error_consumer.lock';
    }

    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;
        return $this;
    }

    public function setMaxConsumePerRun(int $max): self
    {
        $this->maxConsumePerRun = max(1, $max);
        return $this;
    }

    /**
     * 执行消费：申领 + 修复 + 删除
     *
     * @return array{claimed:int, fixed:int, failed:int, errors:list<string>}
     */
    public function consume(): array
    {
        $result = ['claimed' => 0, 'fixed' => 0, 'failed' => 0, 'errors' => []];

        // 1. 原子申领
        $tasks = $this->atomicClaim();
        if (empty($tasks)) {
            $this->log('无待消费的 WLS 错误任务');
            return $result;
        }

        $this->log('申领到 ' . count($tasks) . ' 个任务');

        // 2. 逐个修复
        foreach ($tasks as $task) {
            $agentId = $task['id'];
            $this->updateStatus($agentId, 'running');

            try {
                $fixed = $this->fixError($task);
                if ($fixed) {
                    $this->removeTask($agentId);
                    $result['fixed']++;
                    $this->log("已修复: {$agentId}");
                } else {
                    $this->updateStatus($agentId, 'failed', '修复返回 false');
                    $result['failed']++;
                    $result['errors'][] = $task['description'] ?? $agentId;
                }
            } catch (\Throwable $e) {
                $this->updateStatus($agentId, 'failed', $e->getMessage());
                $result['failed']++;
                $result['errors'][] = $e->getMessage();
                $this->log('修复异常: ' . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * 同步消费所有积压的 wls_fix_* 任务，直到全部清空
     * 用于在新需求开始前把错误全部消化完
     *
     * @param int $maxIterations 最多迭代轮次，防止无限循环
     * @return array{loops:int, total_claimed:int, total_fixed:int, total_failed:int, all_errors:list<string>}
     */
    public function consumeAllBlocking(int $maxIterations = 20): array
    {
        $result = [
            'loops' => 0,
            'total_claimed' => 0,
            'total_fixed' => 0,
            'total_failed' => 0,
            'all_errors' => [],
        ];

        for ($i = 0; $i < $maxIterations; $i++) {
            $result['loops']++;
            $r = $this->consume();
            $result['total_claimed'] += $r['claimed'];
            $result['total_fixed'] += $r['fixed'];
            $result['total_failed'] += $r['failed'];
            foreach ($r['errors'] as $e) {
                $result['all_errors'][] = $e;
            }
            if ($r['claimed'] === 0) {
                break;
            }
        }

        $this->log(sprintf(
            'WLS错误全部消费完成: 共%d轮 申领=%d 已修=%d 失败=%d',
            $result['loops'],
            $result['total_claimed'],
            $result['total_fixed'],
            $result['total_failed']
        ));

        return $result;
    }

    /**
     * 原子申领：从 tasks.json 中找出最早的 wls_fix_* 任务并标记为 running
     * 使用文件锁保证并发安全
     *
     * @return array list of claimed tasks (sorted by error_timestamp ASC)
     */
    private function atomicClaim(): array
    {
        $tasksFile = BP . '/' . self::TASKS_FILE;

        // 获取文件锁
        $lockFd = fopen($this->lockFile, 'c');
        if ($lockFd === false || !flock($lockFd, LOCK_EX)) {
            $this->log('无法获取文件锁，跳过本次消费');
            return [];
        }

        try {
            $this->pool = $this->loadPool($tasksFile);
            $now = date('Y-m-d H:i:s');

            // 找出所有 wls_fix_* 且状态为 todo 的任务
            $candidates = [];
            foreach ($this->pool['agents'] ?? [] as $agentId => $task) {
                if (!str_starts_with($agentId, 'wls_fix_')) {
                    continue;
                }
                if (($task['status'] ?? '') !== 'todo') {
                    continue;
                }
                $candidates[$agentId] = $task;
            }

            if (empty($candidates)) {
                return [];
            }

            // 按 error_timestamp ASC 排序（FIFO）
            uasort($candidates, function ($a, $b) {
                $ta = $a['error_timestamp'] ?? '0';
                $tb = $b['error_timestamp'] ?? '0';
                return strcmp($ta, $tb);
            });

            // 取前 maxConsumePerRun 个
            $toClaim = array_slice($candidates, 0, $this->maxConsumePerRun, true);
            $claimed = [];

            foreach ($toClaim as $agentId => &$task) {
                $task['status'] = 'running';
                $task['started_at'] = $now;
                $this->pool['agents'][$agentId] = &$task;
                $claimed[] = &$this->pool['agents'][$agentId];
            }

            $this->savePool($tasksFile, $this->pool);

            return $claimed;
        } finally {
            flock($lockFd, LOCK_UN);
            fclose($lockFd);
        }
    }

    /**
     * 调用 AI 修复单个错误
     *
     * @param array $task
     * @return bool true=修复成功（删除任务），false=修复失败（保留任务）
     */
    private function fixError(array $task): bool
    {
        $sourceFile = $task['file'] ?? '';
        $description = $task['description'] ?? '';
        $errorType = $task['error_type'] ?? 'Unknown';

        // 优先修复源码文件
        if ($sourceFile !== '' && file_exists($sourceFile)) {
            $fixResult = $this->fixViaAi($sourceFile, $description, $errorType);
            if ($fixResult) {
                return true;
            }
        }

        // 无法修复，返回 false
        return false;
    }

    /**
     * 调用 AI 修复源码文件
     *
     * @param string $file 源码文件路径
     * @param string $description 错误描述
     * @param string $errorType 错误类型
     * @return bool
     */
    private function fixViaAi(string $file, string $description, string $errorType): bool
    {
        $agentId = 'wls_fix_' . substr(md5($file . $errorType), 0, 8);
        $taskInfo = [
            'text' => "[WLS] {$errorType}: {$description}",
            'code_id' => $agentId,
            'priority' => $this->classifyPriority($errorType),
            'file' => $file,
            'line' => 1,
        ];
        $matchResult = [
            'target_file' => $file,
            'target_line' => 1,
            'issues' => ["[WLS Error] {$errorType}: " . mb_substr($description, 0, 200)],
        ];

        try {
            /** @var \Agent\CursorBase\Service\AgentDispatcher $dispatcher */
            $dispatcher = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Agent\CursorBase\Service\AgentDispatcher::class
            );
            $dispatcher->setAutoTrigger(true);
            $success = $dispatcher->dispatch($agentId, $taskInfo, $matchResult);
            return $success;
        } catch (\Throwable $e) {
            $this->log('AgentDispatcher 派发失败: ' . $e->getMessage());
            return false;
        }
    }

    private function classifyPriority(string $errorType): string
    {
        return match ($errorType) {
            'Fatal', 'E_COMPILE_ERROR' => 'critical',
            'ParseError', 'TypeError', 'Uncaught' => 'high',
            'PDOException' => 'high',
            default => 'normal',
        };
    }

    /**
     * 更新任务状态
     */
    private function updateStatus(string $agentId, string $status, ?string $error = null): void
    {
        $tasksFile = BP . '/' . self::TASKS_FILE;
        $pool = $this->loadPool($tasksFile);

        if (isset($pool['agents'][$agentId])) {
            $pool['agents'][$agentId]['status'] = $status;
            if ($status === 'done' || $status === 'completed') {
                $pool['agents'][$agentId]['completed_at'] = date('Y-m-d H:i:s');
                // 移到 completed
                $pool['completed'][$agentId] = $pool['agents'][$agentId];
                unset($pool['agents'][$agentId]);
            }
            if ($status === 'failed') {
                $pool['agents'][$agentId]['error'] = $error;
                $pool['failed'][$agentId] = $pool['agents'][$agentId];
                unset($pool['agents'][$agentId]);
            }
            $this->savePool($tasksFile, $pool);
        }
    }

    /**
     * 从 agents 列表中删除已完成任务
     */
    private function removeTask(string $agentId): void
    {
        $tasksFile = BP . '/' . self::TASKS_FILE;
        $pool = $this->loadPool($tasksFile);

        if (isset($pool['agents'][$agentId])) {
            $pool['agents'][$agentId]['status'] = 'done';
            $pool['agents'][$agentId]['completed_at'] = date('Y-m-d H:i:s');
            $pool['completed'][$agentId] = $pool['agents'][$agentId];
            unset($pool['agents'][$agentId]);
            $this->savePool($tasksFile, $pool);
        }
    }

    private function loadPool(string $tasksFile): array
    {
        if (!file_exists($tasksFile)) {
            return $this->defaultPool();
        }
        $content = @file_get_contents($tasksFile);
        if ($content === false || $content === '') {
            return $this->defaultPool();
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : $this->defaultPool();
    }

    private function savePool(string $tasksFile, array $pool): void
    {
        $dir = dirname($tasksFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $pool['updated_at'] = date('Y-m-d H:i:s');
        file_put_contents($tasksFile, json_encode($pool, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function defaultPool(): array
    {
        return [
            'project' => basename(BP),
            'version' => '1.0',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'master' => ['status' => 'idle', 'last_task' => null, 'model' => 'deepseek'],
            'agents' => [],
            'completed' => [],
            'failed' => [],
        ];
    }

    private function log(string $msg): void
    {
        if ($this->verbose) {
            echo '[' . date('H:i:s') . '] [WlsErrorConsumer] ' . $msg . PHP_EOL;
        }
    }
}
