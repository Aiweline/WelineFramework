<?php

declare(strict_types=1);

namespace Agent\CursorBase\Service;

use Agent\CursorBase\Api\CursorAiInterface;
use Agent\CursorBase\Helper\PlatformHelper;

/**
 * Cursor AI CLI 服务
 *
 * 通过 Cursor Agent CLI (agent 命令) 调用 AI，支持流式响应。
 * 使用临时文件传递 prompt 避免命令行长度限制。
 * Windows 下直接调用底层 node.exe + index.js 以提升性能。
 */
class CursorAiService implements CursorAiInterface
{
    private string $logDir;
    private bool $verbose = false;
    private int $timeout = 120;
    private ?string $workspace = null;
    private string $model = 'auto';
    private ?string $apiKey = null;

    public function __construct()
    {
        $this->logDir = BP . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR;
        PlatformHelper::ensureDirectoryExists($this->logDir);
    }

    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;
        return $this;
    }

    public function setTimeout(int $seconds): self
    {
        $this->timeout = max(10, $seconds);
        return $this;
    }

    public function setWorkspace(?string $path): self
    {
        $this->workspace = $path;
        return $this;
    }

    public function setModel(?string $model): self
    {
        $this->model = $model ?: 'auto';
        return $this;
    }

    public function setApiKey(?string $apiKey): self
    {
        $this->apiKey = $apiKey;
        return $this;
    }

    /**
     * 获取 API Key（优先级：setApiKey > 环境变量 CURSOR_API_KEY > env.php 配置）
     */
    private function getApiKey(): ?string
    {
        if ($this->apiKey) {
            return $this->apiKey;
        }

        $envKey = getenv('CURSOR_API_KEY');
        if ($envKey) {
            return $envKey;
        }

        $config = \Weline\Framework\App\Env::getInstance()->getConfig('cursor_api_key');
        if ($config) {
            return $config;
        }

        return null;
    }

    public function getSessionDir(): string
    {
        return $this->logDir;
    }

    // ==================== 可用性检测 ====================

    public function isAvailable(): bool
    {
        return $this->resolveAgentExecutable() !== null;
    }

    public function getInstallStatus(): array
    {
        $info = $this->resolveAgentExecutable();

        if ($info !== null) {
            return [
                'installed' => true,
                'path' => $info['display'],
                'logged_in' => true,
                'message' => 'Cursor CLI 已就绪',
            ];
        }

        return [
            'installed' => false,
            'path' => null,
            'logged_in' => false,
            'message' => 'Cursor CLI (agent) 未安装',
            'install_instructions' => $this->getInstallInstructions(),
        ];
    }

    public function getInstallInstructions(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return [
                'platform' => 'Windows',
                'command' => "irm 'https://cursor.com/install?win32=true' | iex",
                'steps' => [
                    '1. 打开 PowerShell（管理员权限更佳）',
                    "2. 运行: irm 'https://cursor.com/install?win32=true' | iex",
                    '3. 等待安装完成',
                    '4. 运行: agent login',
                    '5. 在浏览器中完成授权',
                ],
            ];
        }

        return [
            'platform' => 'Unix/macOS',
            'command' => 'curl https://cursor.com/install -fsS | bash',
            'steps' => [
                '1. 打开终端',
                '2. 运行: curl https://cursor.com/install -fsS | bash',
                '3. 运行: agent login',
                '4. 在浏览器中完成授权',
            ],
        ];
    }

    /**
     * 解析 agent 可执行路径
     *
     * Windows 优先直接调用 node.exe + index.js（绕过 .cmd/.ps1 开销）。
     *
     * @return array{node: string, script: string, display: string}|null
     */
    private function resolveAgentExecutable(): ?array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $userProfile = getenv('USERPROFILE') ?: '';
            $base = $userProfile . '\\AppData\\Local\\cursor-agent';

            if (is_dir($base . '\\versions')) {
                $versions = @scandir($base . '\\versions', SCANDIR_SORT_DESCENDING);
                if ($versions) {
                    foreach ($versions as $ver) {
                        if ($ver === '.' || $ver === '..') {
                            continue;
                        }
                        $node = $base . '\\versions\\' . $ver . '\\node.exe';
                        $script = $base . '\\versions\\' . $ver . '\\index.js';
                        if (is_file($node) && is_file($script)) {
                            return [
                                'node' => $node,
                                'script' => $script,
                                'display' => $base . '\\agent.cmd',
                            ];
                        }
                    }
                }
            }
        }

        // Unix / macOS
        $home = getenv('HOME') ?: '';
        if ($home) {
            $candidates = [
                $home . '/.cursor-agent/agent',
                '/usr/local/bin/agent',
            ];
            foreach ($candidates as $path) {
                if (is_file($path) && is_executable($path)) {
                    return ['node' => $path, 'script' => '', 'display' => $path];
                }
            }
        }

        // PATH fallback
        $output = [];
        $rc = 0;
        if (PHP_OS_FAMILY === 'Windows') {
            exec('where agent 2>nul', $output, $rc);
        } else {
            exec('which agent 2>/dev/null', $output, $rc);
        }
        if ($rc === 0 && !empty($output[0])) {
            $p = trim($output[0]);
            return ['node' => $p, 'script' => '', 'display' => $p];
        }

        return null;
    }

    // ==================== 对话接口 ====================

    /**
     * 非流式对话
     */
    public function chat(string $prompt, string $systemPrompt = '', array $context = [], int $timeout = 0): array
    {
        $timeout = $timeout > 0 ? $timeout : $this->timeout;
        $fullPrompt = $this->buildPrompt($prompt, $systemPrompt, $context);

        return $this->execute($fullPrompt, $timeout, false, null);
    }

    /**
     * 流式对话 — 每收到文本片段立刻回调
     *
     * @param callable(string $chunk): void $onChunk
     */
    public function chatStream(string $prompt, string $systemPrompt = '', array $context = [], ?callable $onChunk = null, int $timeout = 0): array
    {
        $timeout = $timeout > 0 ? $timeout : $this->timeout;
        $fullPrompt = $this->buildPrompt($prompt, $systemPrompt, $context);

        return $this->execute($fullPrompt, $timeout, true, $onChunk);
    }

    /**
     * 清理过期会话（兼容旧接口）
     */
    public function cleanupSessions(int $maxAgeSeconds = 86400): int
    {
        return 0;
    }

    // ==================== 内部实现 ====================

    /**
     * 构建 prompt — 用户消息在前，上下文在后
     */
    private function buildPrompt(string $userMessage, string $systemPrompt, array $chatHistory): string
    {
        $parts = [];

        if (!empty($systemPrompt)) {
            $parts[] = $systemPrompt;
        }

        if (!empty($chatHistory)) {
            $historyLines = [];
            // 只保留最近 6 条
            $recent = array_slice($chatHistory, -6);
            foreach ($recent as $msg) {
                $role = ($msg['role'] ?? 'user') === 'user' ? 'User' : 'Assistant';
                $historyLines[] = "{$role}: {$msg['content']}";
            }
            $parts[] = "Recent conversation:\n" . implode("\n", $historyLines);
        }

        $parts[] = "User: {$userMessage}";

        return implode("\n\n", $parts);
    }

    /**
     * 核心执行：写临时文件 → proc_open(node.exe index.js) → 流式/非流式读取
     */
    private function execute(string $fullPrompt, int $timeout, bool $stream, ?callable $onChunk): array
    {
        $info = $this->resolveAgentExecutable();
        if ($info === null) {
            return [
                'success' => false,
                'response' => '',
                'error' => 'Cursor CLI (agent) 未安装',
            ];
        }

        // 写 prompt 到临时文件
        $tempFile = @tempnam(sys_get_temp_dir(), 'cursor_ai_');
        if (!$tempFile) {
            return ['success' => false, 'response' => '', 'error' => '无法创建临时文件'];
        }
        file_put_contents($tempFile, $fullPrompt, LOCK_EX);

        // 构建命令行（Windows 用 .ps1 脚本，Unix 直接拼）
        $cmdLine = $this->buildCommandLine($info, $tempFile, $stream);
        $this->log("CMD: {$cmdLine}");

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmdLine, $descriptors, $pipes);
        if (!is_resource($process)) {
            $this->cleanup($tempFile);
            return ['success' => false, 'response' => '', 'error' => '无法启动进程'];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);

        $response = '';
        $startTime = time();

        while (true) {
            $status = proc_get_status($process);

            $buf = @fread($pipes[1], 8192);
            if ($buf !== false && $buf !== '') {
                // 清理 ANSI 转义序列
                $cleanBuf = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $buf);
                $cleanBuf = preg_replace('/\x1b\[\d*[GK]/', '', $cleanBuf);
                
                $response .= $cleanBuf;
                
                if ($stream && $onChunk && $cleanBuf !== '') {
                    $onChunk($cleanBuf);
                }
            }

            if (!$status['running']) {
                break;
            }

            if (time() - $startTime > $timeout) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                $this->cleanup($tempFile);
                return ['success' => false, 'response' => $response, 'error' => "超时 ({$timeout}s)"];
            }

            usleep(30000); // 30ms
        }

        // 读取剩余
        $remaining = @stream_get_contents($pipes[1]);
        if ($remaining !== false && $remaining !== '') {
            $cleanRemaining = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $remaining);
            $cleanRemaining = preg_replace('/\x1b\[\d*[GK]/', '', $cleanRemaining);
            
            $response .= $cleanRemaining;
            
            if ($stream && $onChunk && $cleanRemaining !== '') {
                $onChunk($cleanRemaining);
            }
        }

        // stderr
        $stderr = @stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        $returnCode = proc_close($process);

        $this->cleanup($tempFile);

        // text 模式下去除 ANSI 转义序列
        if (!$stream) {
            $response = preg_replace('/\x1b\[[0-9;]*m/', '', $response);
            $response = trim($response);
        }

        $this->log("返回码: {$returnCode}, 响应长度: " . strlen($response));

        $hasContent = !empty(trim($response));
        $error = null;
        
            if (!$hasContent) {
            if ($returnCode !== 0) {
                $error = "CLI 错误 (code {$returnCode})";
                if ($stderr) {
                    $stderrClean = preg_replace('/\x1b\[[0-9;]*m/', '', trim($stderr));
                    if ($stderrClean) {
                        $error .= ': ' . mb_substr($stderrClean, 0, 200);
                    }
                }
            } else {
                $apiKey = $this->getApiKey();
                if (!$apiKey) {
                    $error = "缺少 Cursor API Key，请配置 env.php 中的 cursor_api_key 或设置环境变量 CURSOR_API_KEY";
                } else {
                    $error = "Cursor Agent 无响应（可能是网络问题或 API Key 无效）";
                }
                if ($stderr) {
                    $stderrClean = preg_replace('/\x1b\[[0-9;]*m/', '', trim($stderr));
                    if ($stderrClean) {
                        $error .= ' - ' . mb_substr($stderrClean, 0, 200);
                    }
                }
            }
        }

        return [
            'success' => $hasContent,
            'response' => $response,
            'error' => $error,
        ];
    }

    /**
     * 构建命令行
     *
     * Windows: 生成 .ps1 脚本 → powershell 执行
     * Unix: 直接拼命令
     */
    private function buildCommandLine(array $info, string $tempFile, bool $stream): string
    {
        // 使用 text 格式简化处理，stream-json 在某些情况下会有重复问题
        $outputFormat = 'text';
        $streamFlag = '';

        if (PHP_OS_FAMILY === 'Windows') {
            // 用 .ps1 脚本避免转义地狱
            $nodePath = addcslashes($info['node'], "'");
            $scriptPath = addcslashes($info['script'], "'");
            $tempPath = addcslashes($tempFile, "'");
            $workspace = addcslashes($this->workspace ?? rtrim(BP, '\\/'), "'");

            if (!empty($scriptPath)) {
                // 直接调用 node.exe + index.js（最快）
                $runLine = "& '{$nodePath}' '{$scriptPath}' -p \$prompt --output-format {$outputFormat}{$streamFlag} --trust --workspace '{$workspace}'";
            } else {
                $runLine = "& '{$nodePath}' -p \$prompt --output-format {$outputFormat}{$streamFlag} --trust --workspace '{$workspace}'";
            }

            $runLine .= " --model '{$this->model}'";

            $apiKey = $this->getApiKey();
            if ($apiKey) {
                $runLine .= " --api-key '{$apiKey}'";
            }

            $ps1Content = "\$prompt = [IO.File]::ReadAllText('{$tempPath}', [Text.Encoding]::UTF8)\n{$runLine}\n";

            $ps1File = $tempFile . '.ps1';
            file_put_contents($ps1File, $ps1Content, LOCK_EX);

            return "powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File \"{$ps1File}\"";
        }

        // Unix
        $agent = escapeshellarg($info['node']);
        $scriptArg = !empty($info['script']) ? ' ' . escapeshellarg($info['script']) : '';
        $workspace = escapeshellarg($this->workspace ?? rtrim(BP, '/'));
        $modelFlag = ' --model ' . escapeshellarg($this->model);
        $apiKey = $this->getApiKey();
        $apiKeyFlag = $apiKey ? ' --api-key ' . escapeshellarg($apiKey) : '';
        $promptArg = escapeshellarg(file_get_contents($tempFile));

        return "{$agent}{$scriptArg} -p {$promptArg} --output-format {$outputFormat}{$streamFlag} --trust --workspace {$workspace}{$modelFlag}{$apiKeyFlag}";
    }

    /**
     * 解析 stream-json 格式的输出块
     *
     * Cursor stream-json 每行一个 JSON：
     *   {"type":"assistant","message":{"content":[{"text":"..."}]}}
     *   {"type":"result","duration_ms":1234}
     */
    private function parseStreamChunks(string $buffer, string &$accumulated, callable $onChunk): void
    {
        $lines = explode("\n", $buffer);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // 清除 ANSI 转义序列
            $line = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $line);
            $line = preg_replace('/\x1b\[\d*[GK]/', '', $line);
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $data = @json_decode($line, true);
            if (!is_array($data)) {
                // 非 JSON 行 — 可能是纯文本 fallback
                if (strlen($line) > 0 && $line[0] !== '{') {
                    $accumulated .= $line;
                    $onChunk($line);
                }
                continue;
            }

            $type = $data['type'] ?? '';

            if ($type === 'assistant') {
                $contents = $data['message']['content'] ?? [];
                foreach ($contents as $block) {
                    $text = $block['text'] ?? '';
                    if ($text !== '') {
                        $accumulated .= $text;
                        $onChunk($text);
                    }
                }
            }
            // 'result' type means end — caller loop checks proc status
        }
    }

    /**
     * 清理临时文件
     */
    private function cleanup(string $tempFile): void
    {
        @unlink($tempFile);
        @unlink($tempFile . '.ps1');
    }

    private function log(string $message): void
    {
        if ($this->verbose) {
            echo "[CursorAI] {$message}\n";
        }

        $logFile = $this->logDir . 'cursor-ai.log';
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
    }
}
