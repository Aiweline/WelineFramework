<?php
declare(strict_types=1);

namespace Weline\Bot\Skill;

use Weline\Bot\Interface\SkillInterface;
use Weline\Bot\Service\SkillContext;
use Weline\Bot\Service\SkillResult;
use Weline\Framework\System\Process\Processer;

/**
 * Shell 命令执行技能
 *
 * 安全地执行 Shell 命令
 */
class ShellSkill implements SkillInterface
{
    /**
     * 允许的命令白名单
     */
    private const DEFAULT_WHITELIST = [
        'ls', 'dir', 'pwd', 'whoami', 'date', 'echo', 'cat', 'head', 'tail',
        'grep', 'find', 'wc', 'sort', 'uniq', 'cut', 'tr',
        'git', 'npm', 'node', 'php', 'composer',
    ];

    public function getCode(): string
    {
        return 'shell.execute';
    }

    public function getName(): string
    {
        return __('Shell 命令执行');
    }

    public function getDescription(): string
    {
        return __('执行 Shell 命令（受白名单限制）');
    }

    public function getCategory(): string
    {
        return 'shell';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => __('要执行的命令'),
                ],
                'timeout' => [
                    'type' => 'integer',
                    'default' => 30,
                    'description' => __('超时时间（秒）'),
                ],
                'cwd' => [
                    'type' => 'string',
                    'description' => __('工作目录'),
                ],
            ],
            'required' => ['command'],
        ];
    }

    public function getPermissionRequired(): array
    {
        return ['shell.execute'];
    }

    public function execute(array $params, SkillContext $context): SkillResult
    {
        $command = $params['command'] ?? '';
        $timeout = $params['timeout'] ?? 30;
        $cwd = $params['cwd'] ?? null;

        if (empty($command)) {
            return SkillResult::error('Command is required');
        }

        // 安全检查
        $securityCheck = $this->checkCommandSecurity($command);
        if (!$securityCheck['safe']) {
            return SkillResult::error($securityCheck['reason']);
        }

        try {
            // 使用 Processer 执行命令
            $result = $this->executeCommand($command, $cwd, $timeout);

            return SkillResult::success([
                'command' => $command,
                'stdout' => $result['stdout'],
                'stderr' => $result['stderr'],
                'exit_code' => $result['exit_code'],
                'execution_time' => $result['execution_time'],
            ]);

        } catch (\Throwable $e) {
            return SkillResult::error("Command execution failed: {$e->getMessage()}");
        }
    }

    public function isDangerous(): bool
    {
        return true;
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * 检查命令安全性
     */
    private function checkCommandSecurity(string $command): array
    {
        // 危险命令检测
        $dangerousPatterns = [
            '/\brm\s+-rf\b/i',
            '/\brm\s+.*\s+\//i',
            '/\bdd\s+if=/i',
            '/\bmkfs\b/i',
            '/\bformat\b/i',
            '/\bshutdown\b/i',
            '/\breboot\b/i',
            '/\bhalt\b/i',
            '/\binit\s+[06]/i',
            '/\bchmod\s+777\b/i',
            '/\bchown\s+.*:\s*\*/i',
            '/\b>\s*\/dev\//i',
            '/\bcurl\s+.*\|\s*bash\b/i',
            '/\bwget\s+.*\|\s*bash\b/i',
            '/\beval\s+/i',
            '/\bexec\s+/i',
            '/\bsource\s+/i',
            '/\b\.\s+/i',
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $command)) {
                return [
                    'safe' => false,
                    'reason' => __('检测到危险命令模式，禁止执行'),
                ];
            }
        }

        // 提取命令名
        $commandName = $this->extractCommandName($command);

        // 检查是否在白名单中（可配置）
        $whitelist = $this->getWhitelist();
        $isAllowed = false;
        foreach ($whitelist as $allowed) {
            if ($commandName === $allowed || str_starts_with($commandName, $allowed)) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            return [
                'safe' => false,
                'reason' => __("命令不在白名单中: {$commandName}"),
            ];
        }

        return ['safe' => true, 'reason' => ''];
    }

    /**
     * 提取命令名
     */
    private function extractCommandName(string $command): string
    {
        // 移除前面的空格
        $command = trim($command);

        // 处理管道和重定向
        $parts = preg_split('/[\|>]/', $command);
        $firstPart = trim($parts[0]);

        // 提取命令名
        $words = preg_split('/\s+/', $firstPart);
        return $words[0] ?? '';
    }

    /**
     * 执行命令
     */
    private function executeCommand(string $command, ?string $cwd, int $timeout): array
    {
        $startTime = microtime(true);

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd ?? getcwd());

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start process');
        }

        // 关闭 stdin
        fclose($pipes[0]);

        // 读取输出
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $executionTime = microtime(true) - $startTime;

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $exitCode,
            'execution_time' => round($executionTime, 3),
        ];
    }

    /**
     * 获取命令白名单
     */
    private function getWhitelist(): array
    {
        // TODO: 从配置中读取
        return self::DEFAULT_WHITELIST;
    }
}
