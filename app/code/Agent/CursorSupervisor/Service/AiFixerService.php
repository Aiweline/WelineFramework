<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Service;

use Weline\Framework\App\Env;

/**
 * AI 修复服务
 * 
 * 功能：
 * 1. 调用 AI API 修复代码错误
 * 2. 支持多种 AI 提供商（OpenAI、Claude、本地 LLM）
 * 3. 智能提示词生成
 */
class AiFixerService
{
    private ?string $apiKey = null;
    private string $apiEndpoint = 'https://api.openai.com/v1/chat/completions';
    private string $model = 'gpt-4';
    private bool $enabled = false;
    private int $maxTokens = 4096;
    private float $temperature = 0.3;
    
    public function __construct()
    {
        $this->loadConfig();
    }
    
    /**
     * 加载配置
     */
    private function loadConfig(): void
    {
        $config = Env::getInstance()->getConfig();
        $aiConfig = $config['cursor_supervisor'] ?? [];
        
        $this->apiKey = $aiConfig['api_key'] ?? null;
        $this->apiEndpoint = $aiConfig['api_endpoint'] ?? $this->apiEndpoint;
        $this->model = $aiConfig['model'] ?? $this->model;
        $this->enabled = !empty($this->apiKey);
        $this->maxTokens = $aiConfig['max_tokens'] ?? $this->maxTokens;
        $this->temperature = $aiConfig['temperature'] ?? $this->temperature;
    }
    
    /**
     * 检查 AI 修复是否启用
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
    
    /**
     * 设置 API Key
     */
    public function setApiKey(string $apiKey): self
    {
        $this->apiKey = $apiKey;
        $this->enabled = !empty($apiKey);
        return $this;
    }
    
    /**
     * 设置 API 端点
     */
    public function setApiEndpoint(string $endpoint): self
    {
        $this->apiEndpoint = $endpoint;
        return $this;
    }
    
    /**
     * 设置模型
     */
    public function setModel(string $model): self
    {
        $this->model = $model;
        return $this;
    }
    
    /**
     * 修复语法错误
     * 
     * @param string $file 文件路径
     * @param string $error 错误信息
     * @return array ['success' => bool, 'error' => string|null, 'original' => string, 'fixed' => string|null]
     */
    public function fixSyntaxError(string $file, string $error): array
    {
        if (!$this->enabled) {
            return [
                'success' => false,
                'error' => 'AI 修复未启用，请在 env.php 中配置 cursor_supervisor.api_key',
                'original' => '',
                'fixed' => null,
            ];
        }
        
        if (!file_exists($file)) {
            return [
                'success' => false,
                'error' => "文件不存在: {$file}",
                'original' => '',
                'fixed' => null,
            ];
        }
        
        $content = file_get_contents($file);
        
        $prompt = $this->buildSyntaxFixPrompt($content, $error);
        $fixedCode = $this->callAiApi($prompt);
        
        if ($fixedCode === null) {
            return [
                'success' => false,
                'error' => 'AI API 调用失败',
                'original' => $content,
                'fixed' => null,
            ];
        }
        
        // 清理 AI 返回的代码（移除 Markdown 代码块标记）
        $fixedCode = $this->cleanAiResponse($fixedCode);
        
        // 备份原文件
        $backupFile = $file . '.bak.' . date('YmdHis');
        copy($file, $backupFile);
        
        // 写入修复后的代码
        file_put_contents($file, $fixedCode);
        
        return [
            'success' => true,
            'error' => null,
            'original' => $content,
            'fixed' => $fixedCode,
            'backup' => $backupFile,
        ];
    }
    
    /**
     * 修复逻辑错误
     */
    public function fixLogicError(string $file, string $error): array
    {
        if (!$this->enabled) {
            return [
                'success' => false,
                'error' => 'AI 修复未启用',
                'original' => '',
                'fixed' => null,
            ];
        }
        
        if (!file_exists($file)) {
            return [
                'success' => false,
                'error' => "文件不存在: {$file}",
                'original' => '',
                'fixed' => null,
            ];
        }
        
        $content = file_get_contents($file);
        
        $prompt = $this->buildLogicFixPrompt($content, $error);
        $fixedCode = $this->callAiApi($prompt);
        
        if ($fixedCode === null) {
            return [
                'success' => false,
                'error' => 'AI API 调用失败',
                'original' => $content,
                'fixed' => null,
            ];
        }
        
        $fixedCode = $this->cleanAiResponse($fixedCode);
        
        // 备份原文件
        $backupFile = $file . '.bak.' . date('YmdHis');
        copy($file, $backupFile);
        
        // 写入修复后的代码
        file_put_contents($file, $fixedCode);
        
        return [
            'success' => true,
            'error' => null,
            'original' => $content,
            'fixed' => $fixedCode,
            'backup' => $backupFile,
        ];
    }
    
    /**
     * 补全未完成的代码
     */
    public function completeCode(string $file): array
    {
        if (!$this->enabled) {
            return [
                'success' => false,
                'error' => 'AI 修复未启用',
                'original' => '',
                'fixed' => null,
            ];
        }
        
        if (!file_exists($file)) {
            return [
                'success' => false,
                'error' => "文件不存在: {$file}",
                'original' => '',
                'fixed' => null,
            ];
        }
        
        $content = file_get_contents($file);
        
        $prompt = $this->buildCompletionPrompt($content);
        $completedCode = $this->callAiApi($prompt);
        
        if ($completedCode === null) {
            return [
                'success' => false,
                'error' => 'AI API 调用失败',
                'original' => $content,
                'fixed' => null,
            ];
        }
        
        $completedCode = $this->cleanAiResponse($completedCode);
        
        // 备份原文件
        $backupFile = $file . '.bak.' . date('YmdHis');
        copy($file, $backupFile);
        
        // 写入补全后的代码
        file_put_contents($file, $completedCode);
        
        return [
            'success' => true,
            'error' => null,
            'original' => $content,
            'fixed' => $completedCode,
            'backup' => $backupFile,
        ];
    }
    
    /**
     * 构建语法修复提示词
     */
    private function buildSyntaxFixPrompt(string $content, string $error): string
    {
        return <<<PROMPT
你是一个比 Cursor 更资深的 PHP 架构师。你的任务是"擦屁股"和"补漏"。

## 监督准则

1. **错误修正**：针对提供的报错信息，修复语法死循环、未定义类、或 PHP 版本不兼容问题。
2. **格式强制**：必须包含 `<?php` 标签，严禁输出 Markdown 代码块符号，严禁输出任何解释文本。
3. **质量要求**：如果使用了过时的函数（如 `mysql_*`），请自动升级为 PDO 或原生兼容方案。
4. **保持风格**：保持原代码的命名风格、注释风格和缩进风格。

## 代码内容

```php
{$content}
```

## 报错信息

```
{$error}
```

## 要求

请直接修复并返回完整的 PHP 代码。不要添加任何解释，不要使用 Markdown 代码块。直接输出修复后的 PHP 代码。
PROMPT;
    }
    
    /**
     * 构建逻辑修复提示词
     */
    private function buildLogicFixPrompt(string $content, string $error): string
    {
        return <<<PROMPT
你是一个资深的 PHP 架构师。请修复以下代码中的逻辑错误。

## 监督准则

1. **错误修正**：分析运行时报错信息，找出逻辑问题并修复。
2. **保持功能**：修复时不要改变代码的主要功能和意图。
3. **格式强制**：必须包含 `<?php` 标签，严禁输出 Markdown 代码块符号。
4. **质量要求**：确保修复后的代码逻辑正确、健壮。

## 代码内容

```php
{$content}
```

## 运行时错误

```
{$error}
```

## 要求

请直接修复并返回完整的 PHP 代码。不要添加任何解释。
PROMPT;
    }
    
    /**
     * 构建代码补全提示词
     */
    private function buildCompletionPrompt(string $content): string
    {
        return <<<PROMPT
你是一个资深的 PHP 架构师。请补全以下代码中未完成的部分。

## 监督准则

1. **补全逻辑**：如果代码中有 `// ...` 或 `// TODO`，根据上下文逻辑推导并实现具体代码。
2. **保持风格**：保持原代码的命名风格、注释风格和架构风格。
3. **格式强制**：必须包含 `<?php` 标签，严禁输出 Markdown 代码块符号。
4. **质量要求**：补全的代码要符合 SOLID 原则和 PHP 最佳实践。

## 代码内容

```php
{$content}
```

## 要求

请补全并返回完整的 PHP 代码。不要添加任何解释。
PROMPT;
    }
    
    /**
     * 调用 AI API
     */
    private function callAiApi(string $prompt): ?string
    {
        $data = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => '你是一个专业的 PHP 代码修复助手。只输出 PHP 代码，不要任何解释或 Markdown 标记。',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
        ];
        
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiEndpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            w_log_error("AI API 请求错误: {$error}", [], 'ai_fixer');
            return null;
        }
        
        if ($httpCode !== 200) {
            w_log_error("AI API 返回错误码: {$httpCode}, 响应: {$response}", [], 'ai_fixer');
            return null;
        }
        
        $result = json_decode($response, true);
        
        if (!isset($result['choices'][0]['message']['content'])) {
            w_log_error("AI API 响应格式错误: {$response}", [], 'ai_fixer');
            return null;
        }
        
        return $result['choices'][0]['message']['content'];
    }
    
    /**
     * 清理 AI 返回的代码
     */
    private function cleanAiResponse(string $code): string
    {
        // 移除 Markdown 代码块标记
        $code = preg_replace('/^```php\s*/i', '', $code);
        $code = preg_replace('/^```\s*/m', '', $code);
        $code = preg_replace('/```\s*$/m', '', $code);
        
        // 确保以 <?php 开头
        $code = trim($code);
        if (!str_starts_with($code, '<?php') && !str_starts_with($code, '<?')) {
            $code = "<?php\n\n" . $code;
        }
        
        return $code;
    }
}
