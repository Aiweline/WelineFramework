<?php
declare(strict_types=1);

namespace Weline\Bot\Service\CodingAgent\Tool;

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
        return __('Execute a shell command. Restricted to safe commands (git, npm, php, grep, etc).');
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => __('Command to run (e.g. php bin/w cache:clear)'),
                ],
                'cwd' => [
                    'type' => 'string',
                    'description' => __('Working directory, optional'),
                ],
                'timeout' => [
                    'type' => 'integer',
                    'default' => 60,
                    'description' => __('Timeout in seconds'),
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
            return ['error' => __('command is required')];
        }

        if (!$this->isAllowed($command)) {
            return ['error' => __('Command not allowed. Use safe commands only (git, npm, php, grep, etc).')];
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
