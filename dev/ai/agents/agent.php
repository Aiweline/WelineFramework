#!/usr/bin/env php
<?php
/**
 * AI Agent CLI - 多模型智能体
 *
 * 用法:
 *   php agent.php [选项]
 *
 * 选项:
 *   --provider=<名称>     模型提供商: openai | anthropic | lmstudio | custom
 *   --model=<模型名>      模型名称 (如 gpt-4o, claude-opus-4-6, qwen3-coder-30b)
 *   --api-key=<key>       API Key (anthropic/openai 必填)
 *   --base-url=<url>      自定义 endpoint (custom/lmstudio 使用)
 *   --system=<提示词>     系统提示词 (可选)
 *   --max-tokens=<数字>   最大 token 数 (默认 4096)
 *   --log=<路径>          日志文件路径 (可选)
 *   --no-stream           禁用流式输出
 *   --help                显示帮助
 *
 * 示例:
 *   # LM Studio 本地模型
 *   php agent.php --provider=lmstudio --model=qwen3-coder-30b --base-url=http://127.0.0.1:1234
 *
 *   # OpenAI
 *   php agent.php --provider=openai --model=gpt-4o --api-key=sk-xxx
 *
 *   # Anthropic
 *   php agent.php --provider=anthropic --model=claude-sonnet-4-6 --api-key=sk-ant-xxx
 *
 *   # 自定义 endpoint (OpenAI 兼容)
 *   php agent.php --provider=custom --model=my-model --base-url=https://my-api.com --api-key=xxx
 */

declare(strict_types=1);

// ============================================================
// 配置解析
// ============================================================

function parseArgs(array $argv): array
{
    $opts = [
        'provider'   => 'lmstudio',
        'model'      => null,
        'api_key'    => null,
        'base_url'   => null,
        'system'     => 'You are a helpful assistant.',
        'max_tokens' => 4096,
        'log'        => null,
        'stream'     => true,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            showHelp();
            exit(0);
        }
        if ($arg === '--no-stream') {
            $opts['stream'] = false;
            continue;
        }
        if (preg_match('/^--([a-z\-]+)=(.*)$/s', $arg, $m)) {
            $key = str_replace('-', '_', $m[1]);
            $opts[$key] = $m[2];
        }
    }

    // max_tokens 转整数
    $opts['max_tokens'] = (int)$opts['max_tokens'];

    return $opts;
}

function showHelp(): void
{
    global $argv;
    $script = basename($argv[0]);
    echo <<<HELP
AI Agent CLI

用法: php {$script} [选项]

选项:
  --provider=<名称>     模型提供商: openai | anthropic | lmstudio | custom
  --model=<模型名>      模型名称
  --api-key=<key>       API Key
  --base-url=<url>      自定义 base URL
  --system=<提示词>     系统提示词
  --max-tokens=<数字>   最大 token 数 (默认 4096)
  --log=<路径>          日志文件路径
  --no-stream           禁用流式输出
  --help                显示此帮助

提供商预设:
  lmstudio   → http://127.0.0.1:1234  (OpenAI 兼容)
  openai     → https://api.openai.com
  anthropic  → https://api.anthropic.com
  custom     → 需要 --base-url

HELP;
}

// ============================================================
// 提供商配置
// ============================================================

function resolveProvider(array $opts): array
{
    $provider = strtolower($opts['provider']);

    $presets = [
        'lmstudio'  => ['base_url' => 'http://127.0.0.1:1234', 'type' => 'openai'],
        'openai'    => ['base_url' => 'https://api.openai.com', 'type' => 'openai'],
        'anthropic' => ['base_url' => 'https://api.anthropic.com', 'type' => 'anthropic'],
        'custom'    => ['base_url' => null, 'type' => 'openai'],
    ];

    if (!isset($presets[$provider])) {
        die(colorize("错误: 不支持的 provider '{$provider}'，可选: openai, anthropic, lmstudio, custom\n", 'red'));
    }

    $preset = $presets[$provider];

    return [
        'type'     => $preset['type'],
        'base_url' => $opts['base_url'] ?? $preset['base_url'],
        'api_key'  => $opts['api_key'] ?? '',
        'model'    => $opts['model'] ?? defaultModel($provider),
    ];
}

function defaultModel(string $provider): string
{
    return match($provider) {
        'openai'    => 'gpt-4o',
        'anthropic' => 'claude-sonnet-4-6',
        'lmstudio'  => 'local-model',
        default     => 'default',
    };
}

// ============================================================
// 日志
// ============================================================

function logWrite(?string $path, string $level, string $message): void
{
    if (!$path) return;
    $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), strtoupper($level), $message);
    file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

// ============================================================
// 终端颜色
// ============================================================

function colorize(string $text, string $color): string
{
    if (!stream_isatty(STDOUT)) return $text;
    $colors = [
        'red'     => "\033[31m",
        'green'   => "\033[32m",
        'yellow'  => "\033[33m",
        'blue'    => "\033[34m",
        'magenta' => "\033[35m",
        'cyan'    => "\033[36m",
        'gray'    => "\033[90m",
        'bold'    => "\033[1m",
        'reset'   => "\033[0m",
    ];
    return ($colors[$color] ?? '') . $text . $colors['reset'];
}

// ============================================================
// HTTP 请求 (cURL)
// ============================================================

function curlRequest(
    string $url,
    array $headers,
    array $payload,
    bool $stream,
    ?callable $onChunk = null
): ?array {
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => !$stream,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    if ($stream && $onChunk) {
        $buffer = '';
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$buffer, $onChunk) {
            $buffer .= $data;
            // 按行处理 SSE
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $onChunk(rtrim($line));
            }
            return strlen($data);
        });
        curl_exec($ch);
        // 处理剩余 buffer
        if ($buffer !== '') $onChunk(rtrim($buffer));
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) {
            fwrite(STDERR, colorize("\ncURL 错误: $err\n", 'red'));
            return null;
        }
        return [];
    }

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err || $body === false) {
        fwrite(STDERR, colorize("cURL 错误: $err\n", 'red'));
        return null;
    }

    $decoded = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        fwrite(STDERR, colorize("JSON 解析错误: " . substr($body, 0, 200) . "\n", 'red'));
        return null;
    }

    return $decoded;
}

// ============================================================
// OpenAI 兼容 API (lmstudio / openai / custom)
// ============================================================

function chatOpenAI(
    array $provider,
    array $messages,
    int $maxTokens,
    bool $stream,
    ?string $logPath
): ?string {
    $url = rtrim($provider['base_url'], '/') . '/v1/chat/completions';

    $headers = [
        'Content-Type: application/json',
    ];
    if ($provider['api_key']) {
        $headers[] = 'Authorization: Bearer ' . $provider['api_key'];
    }

    $payload = [
        'model'      => $provider['model'],
        'messages'   => $messages,
        'max_tokens' => $maxTokens,
        'stream'     => $stream,
    ];

    logWrite($logPath, 'info', "OpenAI 请求: {$url} model={$provider['model']} messages=" . count($messages));

    if (!$stream) {
        $resp = curlRequest($url, $headers, $payload, false);
        if (!$resp) return null;

        if (isset($resp['error'])) {
            fwrite(STDERR, colorize("API 错误: " . ($resp['error']['message'] ?? json_encode($resp['error'])) . "\n", 'red'));
            return null;
        }

        return $resp['choices'][0]['message']['content'] ?? null;
    }

    // 流式
    $result = '';
    curlRequest($url, $headers, $payload, true, function (string $line) use (&$result) {
        if (!str_starts_with($line, 'data: ')) return;
        $data = substr($line, 6);
        if ($data === '[DONE]') return;
        $obj = json_decode($data, true);
        if (!$obj) return;
        $delta = $obj['choices'][0]['delta']['content'] ?? '';
        if ($delta !== '') {
            echo $delta;
            $result .= $delta;
        }
    });

    return $result;
}

// ============================================================
// Anthropic API
// ============================================================

function chatAnthropic(
    array $provider,
    array $messages,
    string $system,
    int $maxTokens,
    bool $stream,
    ?string $logPath
): ?string {
    $url = rtrim($provider['base_url'], '/') . '/v1/messages';

    $headers = [
        'Content-Type: application/json',
        'x-api-key: ' . $provider['api_key'],
        'anthropic-version: 2023-06-01',
    ];

    // Anthropic 不接受 system 在 messages 里
    $anthropicMessages = array_values(array_filter($messages, fn($m) => $m['role'] !== 'system'));

    $payload = [
        'model'      => $provider['model'],
        'system'     => $system,
        'messages'   => $anthropicMessages,
        'max_tokens' => $maxTokens,
        'stream'     => $stream,
    ];

    logWrite($logPath, 'info', "Anthropic 请求: model={$provider['model']} messages=" . count($anthropicMessages));

    if (!$stream) {
        $resp = curlRequest($url, $headers, $payload, false);
        if (!$resp) return null;

        if (isset($resp['error'])) {
            fwrite(STDERR, colorize("API 错误: " . ($resp['error']['message'] ?? json_encode($resp['error'])) . "\n", 'red'));
            return null;
        }

        return $resp['content'][0]['text'] ?? null;
    }

    // 流式 SSE
    $result = '';
    curlRequest($url, $headers, $payload, true, function (string $line) use (&$result) {
        if (!str_starts_with($line, 'data: ')) return;
        $data = substr($line, 6);
        $obj  = json_decode($data, true);
        if (!$obj) return;
        if (($obj['type'] ?? '') === 'content_block_delta') {
            $delta = $obj['delta']['text'] ?? '';
            if ($delta !== '') {
                echo $delta;
                $result .= $delta;
            }
        }
    });

    return $result;
}

// ============================================================
// 统一调用入口
// ============================================================

function chat(
    array $provider,
    array $messages,
    string $system,
    int $maxTokens,
    bool $stream,
    ?string $logPath
): ?string {
    if ($provider['type'] === 'anthropic') {
        return chatAnthropic($provider, $messages, $system, $maxTokens, $stream, $logPath);
    }
    return chatOpenAI($provider, $messages, $maxTokens, $stream, $logPath);
}

// ============================================================
// 主循环
// ============================================================

function main(array $argv): void
{
    $opts     = parseArgs($argv);
    $provider = resolveProvider($opts);
    $logPath  = $opts['log'] ?: null;
    $stream   = $opts['stream'];
    $system   = $opts['system'];
    $maxTok   = $opts['max_tokens'];

    // 启动信息
    echo colorize("╔══════════════════════════════════════════╗\n", 'cyan');
    echo colorize("║          AI Agent CLI                    ║\n", 'cyan');
    echo colorize("╚══════════════════════════════════════════╝\n", 'cyan');
    echo colorize("提供商: ", 'gray') . colorize($opts['provider'], 'yellow');
    echo colorize("  模型: ", 'gray') . colorize($provider['model'], 'yellow');
    echo colorize("  流式: ", 'gray') . colorize($stream ? '是' : '否', 'yellow') . "\n";
    echo colorize("Base URL: ", 'gray') . colorize($provider['base_url'] ?? '(未设置)', 'gray') . "\n";
    if ($logPath) echo colorize("日志: $logPath\n", 'gray');
    echo colorize("输入 /help 查看命令，Ctrl+C 或 /exit 退出\n\n", 'gray');

    $messages = [];

    // 对 OpenAI 兼容 API，system 作为第一条消息
    if ($provider['type'] !== 'anthropic' && $system) {
        $messages[] = ['role' => 'system', 'content' => $system];
    }

    logWrite($logPath, 'info', "Agent 启动 provider={$opts['provider']} model={$provider['model']}");

    // stdin 循环
    while (true) {
        echo colorize("\n你: ", 'green');
        $input = fgets(STDIN);

        if ($input === false) {
            // EOF
            echo colorize("\n再见！\n", 'cyan');
            break;
        }

        $input = trim($input);
        if ($input === '') continue;

        // 内置命令
        if (str_starts_with($input, '/')) {
            $cmd = strtolower(explode(' ', $input)[0]);
            switch ($cmd) {
                case '/exit':
                case '/quit':
                    echo colorize("再见！\n", 'cyan');
                    exit(0);

                case '/clear':
                    $messages = [];
                    if ($provider['type'] !== 'anthropic' && $system) {
                        $messages[] = ['role' => 'system', 'content' => $system];
                    }
                    echo colorize("对话已清空\n", 'yellow');
                    logWrite($logPath, 'info', '对话历史已清空');
                    continue 2;

                case '/model':
                    $parts = explode(' ', $input, 2);
                    if (isset($parts[1])) {
                        $provider['model'] = trim($parts[1]);
                        echo colorize("模型已切换: {$provider['model']}\n", 'yellow');
                        logWrite($logPath, 'info', "模型切换: {$provider['model']}");
                    } else {
                        echo colorize("当前模型: {$provider['model']}\n", 'yellow');
                    }
                    continue 2;

                case '/provider':
                    $parts = explode(' ', $input, 2);
                    if (isset($parts[1])) {
                        $opts['provider'] = trim($parts[1]);
                        try {
                            $provider = resolveProvider($opts);
                            echo colorize("提供商已切换: {$opts['provider']} → {$provider['base_url']}\n", 'yellow');
                        } catch (Throwable $e) {
                            echo colorize("切换失败: {$e->getMessage()}\n", 'red');
                        }
                    } else {
                        echo colorize("当前提供商: {$opts['provider']}\n", 'yellow');
                    }
                    continue 2;

                case '/url':
                    $parts = explode(' ', $input, 2);
                    if (isset($parts[1])) {
                        $provider['base_url'] = trim($parts[1]);
                        echo colorize("Base URL 已更新: {$provider['base_url']}\n", 'yellow');
                    } else {
                        echo colorize("当前 URL: {$provider['base_url']}\n", 'yellow');
                    }
                    continue 2;

                case '/key':
                    $parts = explode(' ', $input, 2);
                    if (isset($parts[1])) {
                        $provider['api_key'] = trim($parts[1]);
                        echo colorize("API Key 已更新\n", 'yellow');
                    }
                    continue 2;

                case '/system':
                    $parts = explode(' ', $input, 2);
                    if (isset($parts[1])) {
                        $system = trim($parts[1]);
                        // 更新 messages 里的 system
                        if ($provider['type'] !== 'anthropic') {
                            foreach ($messages as &$m) {
                                if ($m['role'] === 'system') { $m['content'] = $system; break; }
                            }
                            unset($m);
                        }
                        echo colorize("系统提示词已更新\n", 'yellow');
                    } else {
                        echo colorize("当前系统提示词: $system\n", 'yellow');
                    }
                    continue 2;

                case '/history':
                    $count = count(array_filter($messages, fn($m) => $m['role'] !== 'system'));
                    echo colorize("当前对话轮数: $count\n", 'yellow');
                    foreach ($messages as $m) {
                        if ($m['role'] === 'system') continue;
                        $role    = $m['role'] === 'user' ? colorize('用户', 'green') : colorize('AI', 'blue');
                        $preview = mb_substr($m['content'], 0, 80);
                        if (mb_strlen($m['content']) > 80) $preview .= '...';
                        echo "  [{$role}] {$preview}\n";
                    }
                    continue 2;

                case '/stream':
                    $stream = !$stream;
                    echo colorize("流式输出: " . ($stream ? '开启' : '关闭') . "\n", 'yellow');
                    continue 2;

                case '/status':
                    echo colorize("提供商: ", 'gray')    . $opts['provider'] . "\n";
                    echo colorize("模型:   ", 'gray')    . $provider['model'] . "\n";
                    echo colorize("URL:    ", 'gray')    . ($provider['base_url'] ?? '未设置') . "\n";
                    echo colorize("API Key: ", 'gray')   . ($provider['api_key'] ? '已设置' : '未设置') . "\n";
                    echo colorize("流式:   ", 'gray')    . ($stream ? '是' : '否') . "\n";
                    echo colorize("系统提示: ", 'gray')  . mb_substr($system, 0, 60) . "\n";
                    continue 2;

                case '/help':
                    echo colorize("\n可用命令:\n", 'cyan');
                    $cmds = [
                        '/clear'           => '清空对话历史',
                        '/model <名称>'    => '切换模型',
                        '/provider <名称>' => '切换提供商 (openai/anthropic/lmstudio/custom)',
                        '/url <地址>'      => '修改 base URL',
                        '/key <key>'       => '修改 API Key',
                        '/system <提示词>' => '修改系统提示词',
                        '/history'         => '查看对话历史',
                        '/stream'          => '切换流式输出',
                        '/status'          => '查看当前配置',
                        '/help'            => '显示帮助',
                        '/exit'            => '退出',
                    ];
                    foreach ($cmds as $c => $desc) {
                        echo '  ' . colorize(str_pad($c, 22), 'yellow') . $desc . "\n";
                    }
                    echo "\n";
                    continue 2;

                default:
                    echo colorize("未知命令: $cmd，输入 /help 查看帮助\n", 'red');
                    continue 2;
            }
        }

        // 正常用户消息
        $messages[] = ['role' => 'user', 'content' => $input];
        logWrite($logPath, 'info', "用户: " . mb_substr($input, 0, 100));

        echo colorize("\nAI: ", 'blue');

        $reply = chat($provider, $messages, $system, $maxTok, $stream, $logPath);

        if ($reply === null) {
            // 错误时移除刚加入的用户消息，避免污染历史
            array_pop($messages);
            logWrite($logPath, 'error', '请求失败');
            continue;
        }

        if (!$stream) {
            echo $reply;
        }

        echo "\n";

        $messages[] = ['role' => 'assistant', 'content' => $reply];
        logWrite($logPath, 'info', "AI: " . mb_substr($reply, 0, 100));
    }
}

// 捕获 Ctrl+C
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, function () {
        echo colorize("\n再见！\n", 'cyan');
        exit(0);
    });
    pcntl_async_signals(true);
}

main($argv);
