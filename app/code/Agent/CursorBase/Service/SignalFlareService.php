<?php

declare(strict_types=1);

namespace Agent\CursorBase\Service;

use Agent\CursorBase\Api\SignalFlareInterface;
use Agent\CursorBase\Helper\PlatformHelper;

/**
 * 信号弹服务实现
 * 
 * 职责：[SUPERVISOR_TASK] 信号弹的注入和清理
 */
class SignalFlareService implements SignalFlareInterface
{
    private bool $verbose = false;

    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;
        return $this;
    }

    /**
     * 注入信号弹到文件
     */
    public function inject(string $filePath, string $agentId, array $task): bool
    {
        if (!file_exists($filePath)) {
            $dir = dirname($filePath);
            PlatformHelper::ensureDirectoryExists($dir);
            file_put_contents($filePath, "<?php\n\ndeclare(strict_types=1);\n\n");
        }

        $content = file_get_contents($filePath);

        if ($this->hasSignalFlare($filePath)) {
            $this->log("文件已有 SUPERVISOR_TASK 信号弹，跳过注入");
            return false;
        }

        $taskDescription = $this->sanitizeForComment($task['text'] ?? $task['description'] ?? '');
        $taskId = $task['code_id'] ?? $agentId . '_' . time();
        $priority = $task['priority'] ?? 'normal';
        $missionPath = 'dev/ai/agents/' . $agentId . '/mission.json';

        $signalFlare = <<<SIGNAL
/* 🤖 [SUPERVISOR_TASK] ═══════════════════════════════════════════════════════
 * 
 * @AgentID: {$agentId}
 * @TaskID: {$taskId}
 * @Priority: {$priority}
 * 
 * ▶ 任务: {$taskDescription}
 * 
 * ▶ 指令: 立即读取 `{$missionPath}` 获取完整决策包
 * 
 * ▶ 完成后:
 *   1. 删除此 [SUPERVISOR_TASK] 注释块
 *   2. 在文件末尾写入: // @Status: Completed by {$agentId} [时间戳]
 * 
 * ═══════════════════════════════════════════════════════════════════════════ */

SIGNAL;

        if (str_starts_with(trim($content), '<?php')) {
            if (preg_match('/\<\?php\s*(declare\s*\([^)]+\)\s*;)?/s', $content, $matches)) {
                $insertPos = strlen($matches[0]);
            } else {
                $insertPos = strpos($content, '<?php') + 5;
            }
            $newContent = substr($content, 0, $insertPos) . "\n\n" . $signalFlare . substr($content, $insertPos);
        } else {
            $newContent = $signalFlare . $content;
        }

        $result = file_put_contents($filePath, $newContent) !== false;

        if ($result) {
            $this->log("已注入信号弹到: {$filePath}");
        }

        return $result;
    }

    /**
     * 清理文件中的信号弹
     */
    public function cleanup(string $filePath, string $agentId): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $content = file_get_contents($filePath);
        $pattern = '/\/\*\s*🤖\s*\[SUPERVISOR_TASK\].*?═+\s*\*\/\s*\n*/s';
        $newContent = preg_replace($pattern, '', $content);

        if ($newContent !== $content) {
            file_put_contents($filePath, $newContent);
            $this->log("已清理 {$agentId} 的信号弹");
            return true;
        }

        return false;
    }

    /**
     * 检查文件是否包含信号弹
     */
    public function hasSignalFlare(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $content = file_get_contents($filePath);
        return str_contains($content, '[SUPERVISOR_TASK]');
    }

    /**
     * 获取文件中的信号弹信息
     */
    public function getSignalFlareInfo(string $filePath): ?array
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);

        if (!str_contains($content, '[SUPERVISOR_TASK]')) {
            return null;
        }

        $info = [];

        if (preg_match('/@AgentID:\s*(\S+)/', $content, $matches)) {
            $info['agent_id'] = $matches[1];
        }

        if (preg_match('/@TaskID:\s*(\S+)/', $content, $matches)) {
            $info['task_id'] = $matches[1];
        }

        if (preg_match('/@Priority:\s*(\S+)/', $content, $matches)) {
            $info['priority'] = $matches[1];
        }

        if (preg_match('/▶ 任务:\s*(.+)$/m', $content, $matches)) {
            $info['description'] = trim($matches[1]);
        }

        return empty($info) ? null : $info;
    }

    /**
     * 清理注释中的特殊字符
     */
    private function sanitizeForComment(string $text): string
    {
        $text = str_replace(['*/', '/*'], ['* /', '/ *'], $text);
        $text = preg_replace('/@(?!Agent|File|Method|CodeID|Status|TaskID|Priority)/', '@ ', $text);
        return trim($text);
    }

    /**
     * 日志输出
     */
    private function log(string $message): void
    {
        if ($this->verbose) {
            echo "[SignalFlare] {$message}\n";
        }

        $logFile = BP . 'var/log/signal-flare.log';
        PlatformHelper::ensureDirectoryExists(dirname($logFile));

        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
    }
}
