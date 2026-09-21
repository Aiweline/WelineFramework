<?php
declare(strict_types=1);

namespace Weline\Agent\Service\CodingAgent\Tool;

use Weline\Ai\Interface\ToolInterface;

/**
 * 终端命令执行工具（Cursor 风格）
 *
 * 安全执行 Shell 命令，受白名单限制
 */
class RunTerminalTool implements ToolInterface
{
    private const WHITELIST = [
        'ls', 'dir', 'pwd', 'whoami', 'date', 'echo', 'head', 'tail', 'wc',
        'grep', 'find', 'rg', 'sort', 'uniq', 'cut', 'tr', 'xargs',
        'git', 'npm', 'node', 'npx', 'php', 'composer',
    ];

    public function getName(): string
    {
        return 'run_terminal_cmd';
    }

    public function getDescription(): string
    {
        return __('执行 Shell 命令。仅限安全命令（git、npm、php、grep 等）。');
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => __('要执行的命令（例如 php bin/w cache:clear）'),
                ],
                'cwd' => [
                    'type' => 'string',
                    'description' => __('工作目录，可选'),
                ],
                'timeout' => [
                    'type' => 'integer',
                    'default' => 60,
                    'description' => __('超时时间（秒）'),
                ],
            ],
            'required' => ['command'],
        ];
    }

    public function execute(array $args): mixed
    {
        $command = trim($args['command'] ?? '');
        $cwd = $args['cwd'] ?? null;
        $timeout = min(300, max(5, (int) ($args['timeout'] ?? 60)));

        if (empty($command)) {
            return ['error' => __('command 必填')];
        }

        if (!$this->isAllowed($command)) {
            return ['error' => __('命令不允许。仅可使用安全命令（git、npm、php、grep 等）。')];
        }

        try {
            $workDir = $cwd ?? (defined('BP') ? BP : getcwd());
            $originalCwd = getcwd();
            if ($workDir !== '' && is_dir($workDir)) {
                chdir($workDir);
            }

            $output = [];
            $returnCode = 0;
            \Weline\Framework\System\Process\Processer::execute($command, $output, $returnCode);

            if ($originalCwd !== false && $workDir !== '') {
                @chdir($originalCwd);
            }

            return [
                'command' => $command,
                'stdout' => implode("\n", $output),
                'stderr' => '',
                'exit_code' => $returnCode,
            ];
        } catch (\Throwable $e) {
            return [
                'command' => $command,
                'stdout' => '',
                'stderr' => $e->getMessage(),
                'exit_code' => -1,
            ];
        }
    }

    public function isEnabled(): bool
    {
        return true;
    }

    private function isAllowed(string $command): bool
    {
        $parts = preg_split('/\s+/', $command, 2);
        $cmd = strtolower($parts[0] ?? '');

        foreach (self::WHITELIST as $allowed) {
            if (str_starts_with($cmd, $allowed) || str_starts_with($allowed, $cmd)) {
                if (str_contains($command, '|') || str_contains($command, ';') || str_contains($command, '&&')) {
                    return $this->checkChainedCommand($command);
                }
                return true;
            }
        }

        return false;
    }

    private function checkChainedCommand(string $command): bool
    {
        $segments = preg_split('/\s*\|\s*|\s*;\s*|\s*&&\s*/', $command);
        foreach ($segments as $seg) {
            $seg = trim($seg);
            if ($seg === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $seg, 2);
            $cmd = strtolower($parts[0] ?? '');
            $found = false;
            foreach (self::WHITELIST as $allowed) {
                if (str_starts_with($cmd, $allowed) || $cmd === $allowed) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }
        return true;
    }
}
