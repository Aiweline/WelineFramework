<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Ai\Service\Provider;

use Weline\Ai\Model\AiModel;
use Weline\Ai\Helper\ErrorMessageHelper;
use Weline\Framework\App\Exception;

/**
 * Anthropic Claude API提供者
 * 
 * 功能：
 * - 调用Anthropic Claude API生成内容
 * - 支持流式响应
 * - 支持代理配置
 * - 错误处理和重试机制
 * - Token使用量统计
 */
class AnthropicProvider implements ProviderInterface
{
    /**
     * 最大重试次数
     */
    private const MAX_RETRIES = 3;

    /**
     * 重试延迟（秒）
     */
    private const RETRY_DELAY = 1;

    /**
     * Anthropic API版本
     */
    private const API_VERSION = '2023-06-01';

    /**
     * 构造函数
     */
    public function __construct()
    {
        // 无参数构造函数，用于依赖注入兼容性
    }

    /**
     * 调用Anthropic API
     * 
     * @param AiModel $model
     * @param string $prompt
     * @param array $params
     * @return array
     * @throws Exception
     */
    public function generate(AiModel $model, string $prompt, array $params = []): array
    {
        $config = $model->getConfig();
        
        // 合并provider_config（优先）
        $providerConfig = $model->getData('provider_config');
        if (!empty($providerConfig)) {
            $providerData = is_string($providerConfig) ? json_decode($providerConfig, true) : $providerConfig;
            if (is_array($providerData)) {
                foreach ($providerData as $k => $v) {
                    if ($v !== '' && $v !== null) {
                        $config[$k] = $v;
                    }
                }
            }
        }
        
        $apiKey = $this->getApiKey($config);
        
        if (empty($apiKey)) {
            throw new Exception(ErrorMessageHelper::getMissingApiKeyMessage());
        }

        $messages = $this->buildMessages($prompt, $params);
        $systemMessage = $this->extractSystemMessage($params);
        
        // 超时优先级：params.timeout > config.timeout > 默认180秒；0 表示不限制
        $timeout = isset($params['timeout']) ? (int)$params['timeout'] : (isset($config['timeout']) ? (int)$config['timeout'] : 180);
        
        // 设置执行时间限制
        if ($timeout > 0) {
            $timeLimit = $timeout + 10;
            @set_time_limit($timeLimit);
        } else {
            @set_time_limit(0);
        }

        try {
            $requestData = [
            'model' => $config['model'] ?? $model->getModelCode(),
            'messages' => $messages,
            'max_tokens' => (int)($params['max_tokens'] ?? $config['max_tokens'] ?? 4096),
            ];

        // 添加可选参数（确保数值类型正确）
        if (isset($params['temperature']) || isset($config['temperature'])) {
            $requestData['temperature'] = (float)($params['temperature'] ?? $config['temperature'] ?? 0.7);
        }
        if (isset($params['top_p']) || isset($config['top_p'])) {
            $requestData['top_p'] = (float)($params['top_p'] ?? $config['top_p']);
        }
        if (isset($params['top_k']) || isset($config['top_k'])) {
            $requestData['top_k'] = (int)($params['top_k'] ?? $config['top_k']);
        }
        
        // 添加系统消息
        if (!empty($systemMessage)) {
            $requestData['system'] = $systemMessage;
        }

        // 智能体模式：添加 tools（tool_use）
        if (!empty($params['tools']) && is_array($params['tools'])) {
            $requestData['tools'] = $this->convertToolsToAnthropicFormat($params['tools']);
        }

        // 优先使用base_url，如果没有则使用api_url，最后使用默认值
        $apiUrl = $config['base_url'] ?? $config['api_url'] ?? 'https://api.anthropic.com/v1';
        if (!str_ends_with($apiUrl, '/messages')) {
            $apiUrl = rtrim($apiUrl, '/') . '/messages';
        }
        
        // 确保proxyInfo是数组
        $proxyInfo = $model->getProxyInfo();
        if (!is_array($proxyInfo)) {
            $proxyInfo = [];
        }
        
        $response = $this->callApiWithRetry(
            $apiUrl,
            $apiKey,
            $requestData,
            $proxyInfo,
            $timeout
        );

        // 提取 tool_calls（智能体模式）
        $toolCalls = $this->extractToolCalls($response);
        $stopReason = $response['stop_reason'] ?? '';

        $result = [
            'content' => $this->extractContent($response),
            'usage' => [
                'prompt_tokens' => $response['usage']['input_tokens'] ?? 0,
                'completion_tokens' => $response['usage']['output_tokens'] ?? 0,
                'total_tokens' => ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0),
            ],
            'model' => $response['model'] ?? '',
            'finish_reason' => $stopReason,
        ];

            if (!empty($toolCalls)) {
                $result['tool_calls'] = $toolCalls;
                // 保留原始 content blocks 供 agent 构建后续消息
                $result['assistant_content'] = $response['content'] ?? [];
            }

            return $result;
        } finally {
            @set_time_limit(0);
        }
    }

    /**
     * 流式生成
     * 
     * @param AiModel $model
     * @param string $prompt
     * @param callable $callback
     * @param array $params
     * @return array
     * @throws Exception
     */
    public function generateStream(AiModel $model, string $prompt, callable $callback, array $params = []): array
    {
        $config = $model->getConfig();
        
        // 合并provider_config（优先）
        $providerConfig = $model->getData('provider_config');
        if (!empty($providerConfig)) {
            $providerData = is_string($providerConfig) ? json_decode($providerConfig, true) : $providerConfig;
            if (is_array($providerData)) {
                foreach ($providerData as $k => $v) {
                    if ($v !== '' && $v !== null) {
                        $config[$k] = $v;
                    }
                }
            }
        }
        
        $apiKey = $this->getApiKey($config);
        
        if (empty($apiKey)) {
            throw new Exception(ErrorMessageHelper::getMissingApiKeyMessage());
        }

        $messages = $this->buildMessages($prompt, $params);
        $systemMessage = $this->extractSystemMessage($params);
        
        // 超时优先级：params.timeout > config.timeout > 默认180秒；0 表示不限制
        $timeout = isset($params['timeout']) ? (int)$params['timeout'] : (isset($config['timeout']) ? (int)$config['timeout'] : 180);
        
        // 设置执行时间限制
        if ($timeout > 0) {
            $timeLimit = $timeout + 10;
            @set_time_limit($timeLimit);
        } else {
            @set_time_limit(0);
        }

        try {
            $requestData = [
            'model' => $config['model'] ?? $model->getModelCode(),
            'messages' => $messages,
            'max_tokens' => (int)($params['max_tokens'] ?? $config['max_tokens'] ?? 4096),
            'stream' => true,
            ];

        // 添加可选参数（确保数值类型正确）
        if (isset($params['temperature']) || isset($config['temperature'])) {
            $requestData['temperature'] = (float)($params['temperature'] ?? $config['temperature'] ?? 0.7);
        }
        if (isset($params['top_p']) || isset($config['top_p'])) {
            $requestData['top_p'] = (float)($params['top_p'] ?? $config['top_p']);
        }
        if (isset($params['top_k']) || isset($config['top_k'])) {
            $requestData['top_k'] = (int)($params['top_k'] ?? $config['top_k']);
        }
        
        // 添加系统消息
        if (!empty($systemMessage)) {
            $requestData['system'] = $systemMessage;
        }

        // 智能体模式：添加 tools（tool_use）
        if (!empty($params['tools']) && is_array($params['tools'])) {
            $requestData['tools'] = $this->convertToolsToAnthropicFormat($params['tools']);
        }

        $totalTokens = [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
        ];

        $fullContent = '';
        
        // 优先使用base_url，如果没有则使用api_url，最后使用默认值
        $apiUrl = $config['base_url'] ?? $config['api_url'] ?? 'https://api.anthropic.com/v1';
        if (!str_ends_with($apiUrl, '/messages')) {
            $apiUrl = rtrim($apiUrl, '/') . '/messages';
        }
        
        // 确保proxyInfo是数组
        $proxyInfo = $model->getProxyInfo();
        if (!is_array($proxyInfo)) {
            $proxyInfo = [];
        }
        
        $this->callStreamApi(
            $apiUrl,
            $apiKey,
            $requestData,
            function($chunk, $usage = null) use ($callback, &$fullContent, &$totalTokens) {
                $fullContent .= $chunk;
                $callback($chunk);
                
                // 更新token使用量（如果有）
                if ($usage) {
                    $totalTokens['prompt_tokens'] = $usage['input_tokens'] ?? $totalTokens['prompt_tokens'];
                    $totalTokens['completion_tokens'] = $usage['output_tokens'] ?? $totalTokens['completion_tokens'];
                }
            },
            $proxyInfo,
            $timeout
        );

        // 如果流式调用没有返回任何内容，抛出明确错误
        if (empty(trim($fullContent))) {
            throw new Exception('AI 流式生成完成但未返回任何内容，请检查模型配置（API Key、Base URL、模型名称）是否正确');
        }

        // 如果没有从API获取到token统计，则估算
        if ($totalTokens['completion_tokens'] === 0) {
            $totalTokens['completion_tokens'] = $this->estimateTokens($fullContent);
        }
        if ($totalTokens['prompt_tokens'] === 0) {
            $totalTokens['prompt_tokens'] = $this->estimateTokens($prompt);
        }
        $totalTokens['total_tokens'] = $totalTokens['prompt_tokens'] + $totalTokens['completion_tokens'];

            return [
                'content' => $fullContent,
                'usage' => $totalTokens,
            ];
        } finally {
            @set_time_limit(0);
        }
    }

    /**
     * 构建消息数组（Anthropic格式）
     * 
     * @param string $prompt
     * @param array $params
     * @return array
     */
    private function buildMessages(string $prompt, array $params): array
    {
        // 智能体模式：使用完整消息历史，需要转换格式
        if (!empty($params['messages']) && is_array($params['messages'])) {
            return $this->convertMessagesForAnthropic($params['messages']);
        }

        $messages = [];

        // 历史对话（需要转换格式）
        if (!empty($params['history']) && is_array($params['history'])) {
            foreach ($params['history'] as $message) {
                // 跳过系统消息，Anthropic使用单独的system参数
                if (($message['role'] ?? '') === 'system') {
                    continue;
                }
                $messages[] = [
                    'role' => $message['role'] ?? 'user',
                    'content' => $message['content'] ?? ''
                ];
            }
        }

        // 用户消息
        if (!empty($prompt)) {
            $messages[] = [
                'role' => 'user',
                'content' => $prompt
            ];
        }

        return $messages;
    }

    /**
     * 将 OpenAI 格式的消息历史转换为 Anthropic 格式
     * 
     * 主要差异：
     * - system 消息单独传，不放在 messages 中
     * - tool 角色 → tool_result content block
     * - assistant tool_calls → assistant content blocks with tool_use
     */
    private function convertMessagesForAnthropic(array $messages): array
    {
        $result = [];

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? '';

            // 跳过 system（Anthropic 使用单独参数）
            if ($role === 'system') {
                continue;
            }

            // tool 角色的消息转换为 tool_result
            if ($role === 'tool') {
                $result[] = [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'tool_result',
                            'tool_use_id' => $msg['tool_call_id'] ?? '',
                            'content' => $msg['content'] ?? '',
                        ]
                    ]
                ];
                continue;
            }

            // assistant 消息如果包含 tool_calls，转为 tool_use content blocks
            if ($role === 'assistant' && !empty($msg['tool_calls'])) {
                $content = [];
                // 先添加文本部分
                if (!empty($msg['content'])) {
                    $content[] = ['type' => 'text', 'text' => $msg['content']];
                }
                // 再添加 tool_use blocks
                foreach ($msg['tool_calls'] as $tc) {
                    $arguments = $tc['function']['arguments'] ?? $tc['arguments'] ?? '{}';
                    if (is_string($arguments)) {
                        $arguments = json_decode($arguments, true) ?: new \stdClass();
                    }
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => $tc['id'] ?? uniqid('tu_'),
                        'name' => $tc['function']['name'] ?? $tc['name'] ?? '',
                        'input' => $arguments,
                    ];
                }
                $result[] = ['role' => 'assistant', 'content' => $content];
                continue;
            }

            // 普通消息
            $result[] = [
                'role' => $role,
                'content' => $msg['content'] ?? ''
            ];
        }

        return $result;
    }

    /**
     * 将框架中间格式的 Tool 定义转换为 Anthropic tool_use 格式
     */
    private function convertToolsToAnthropicFormat(array $tools): array
    {
        $anthropicTools = [];
        foreach ($tools as $tool) {
            $anthropicTools[] = [
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                'input_schema' => $tool['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
            ];
        }
        return $anthropicTools;
    }

    /**
     * 从 Anthropic 响应中提取 tool_use blocks
     */
    private function extractToolCalls(array $response): array
    {
        $toolCalls = [];

        if (!empty($response['content']) && is_array($response['content'])) {
            foreach ($response['content'] as $block) {
                if (($block['type'] ?? '') === 'tool_use') {
                    $toolCalls[] = [
                        'id' => $block['id'] ?? uniqid('tu_'),
                        'name' => $block['name'] ?? '',
                        'arguments' => $block['input'] ?? [],
                    ];
                }
            }
        }

        return $toolCalls;
    }

    /**
     * 提取系统消息
     * 
     * @param array $params
     * @return string
     */
    private function extractSystemMessage(array $params): string
    {
        // 首先检查params中的system_message
        if (!empty($params['system_message'])) {
            return $params['system_message'];
        }

        // 智能体模式：从 messages 中提取 system 消息
        if (!empty($params['messages']) && is_array($params['messages'])) {
            foreach ($params['messages'] as $message) {
                if (($message['role'] ?? '') === 'system') {
                    return $message['content'] ?? '';
                }
            }
        }

        // 然后从历史记录中查找系统消息
        if (!empty($params['history']) && is_array($params['history'])) {
            foreach ($params['history'] as $message) {
                if (($message['role'] ?? '') === 'system') {
                    return $message['content'] ?? '';
                }
            }
        }

        return '';
    }

    /**
     * 从响应中提取内容
     * 
     * @param array $response
     * @return string
     */
    private function extractContent(array $response): string
    {
        $content = '';
        
        if (!empty($response['content']) && is_array($response['content'])) {
            foreach ($response['content'] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $content .= $block['text'] ?? '';
                }
            }
        }
        
        return $content;
    }

    /**
     * 带重试的API调用
     * 
     * @param string $url
     * @param string $apiKey
     * @param array $data
     * @param array $proxyInfo
     * @param int $timeout
     * @param int $retryCount
     * @return array
     * @throws Exception
     */
    private function callApiWithRetry(string $url, string $apiKey, array $data, array $proxyInfo, int $timeout, int $retryCount = 0): array
    {
        $startTime = microtime(true);
        $maxExecutionTime = ini_get('max_execution_time');
        $timeLimit = $maxExecutionTime > 0 ? (int)$maxExecutionTime : null;
        
        try {
            $ch = $this->initCurl($url, $apiKey, $data, $proxyInfo, $timeout);
            
            // 在执行前检查剩余时间
            if ($timeLimit !== null && $timeLimit > 0) {
                $elapsedBeforeRequest = microtime(true) - $startTime;
                $remainingTime = $timeLimit - $elapsedBeforeRequest;
                if ($remainingTime < 5) {
                    throw new Exception($this->getTimeoutErrorMessage($timeout));
                }
            }
            
            error_clear_last();
            
            $response = curl_exec($ch);
            
            // 检查是否超时
            $lastError = error_get_last();
            if ($lastError && (
                strpos($lastError['message'], 'Maximum execution time') !== false ||
                strpos($lastError['message'], 'exceeded') !== false
            )) {
                throw new Exception($this->getTimeoutErrorMessage($timeout));
            }
            
            $elapsedTime = microtime(true) - $startTime;
            if ($timeLimit !== null && $elapsedTime >= ($timeLimit - 2)) {
                throw new Exception($this->getTimeoutErrorMessage($timeout));
            }
            
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            if ($response === false) {
                if (strpos($error, 'timeout') !== false || strpos($error, 'timed out') !== false) {
                    throw new Exception($this->getTimeoutErrorMessage($timeout));
                }
                throw new Exception("API请求失败: {$error}");
            }

            $result = json_decode($response, true);
            
            if ($httpCode >= 500 && $retryCount < self::MAX_RETRIES) {
                sleep(self::RETRY_DELAY * ($retryCount + 1));
                return $this->callApiWithRetry($url, $apiKey, $data, $proxyInfo, $timeout, $retryCount + 1);
            }

            if ($httpCode !== 200) {
                $errorMsg = $result['error']['message'] ?? "HTTP错误: {$httpCode}";
                throw new Exception("API返回错误: {$errorMsg}");
            }

            if (empty($result['content'])) {
                throw new Exception("API响应格式错误");
            }

            return $result;

        } catch (\Exception $e) {
            if (strpos($e->getMessage(), '请求超时') !== false || 
                strpos($e->getMessage(), '执行时间') !== false) {
                throw $e;
            }
            
            if ($retryCount < self::MAX_RETRIES) {
                sleep(self::RETRY_DELAY * ($retryCount + 1));
                return $this->callApiWithRetry($url, $apiKey, $data, $proxyInfo, $timeout, $retryCount + 1);
            }
            throw new Exception("API调用失败（已重试{$retryCount}次）: " . $e->getMessage());
        }
    }

    /**
     * 流式API调用
     * 
     * @param string $url
     * @param string $apiKey
     * @param array $data
     * @param callable $callback
     * @param array $proxyInfo
     * @param int $timeout
     * @throws Exception
     */
    private function callStreamApi(string $url, string $apiKey, array $data, callable $callback, array $proxyInfo, int $timeout): void
    {
        $startTime = microtime(true);
        $maxExecutionTime = ini_get('max_execution_time');
        $timeLimit = $maxExecutionTime > 0 ? (int)$maxExecutionTime : null;
        
        $ch = $this->initCurl($url, $apiKey, $data, $proxyInfo, $timeout);
        
        if ($timeLimit !== null && $timeLimit > 0) {
            $elapsedBeforeRequest = microtime(true) - $startTime;
            $remainingTime = $timeLimit - $elapsedBeforeRequest;
            if ($remainingTime < 5) {
                throw new Exception($this->getTimeoutErrorMessage($timeout));
            }
        }
        
        error_clear_last();
        
        // 用于捕获非 SSE 格式的响应（API 错误等）
        $rawResponseBuffer = '';
        $hasValidChunk = false;
        
        // 设置流式处理回调
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) use ($callback, $startTime, $timeLimit, &$rawResponseBuffer, &$hasValidChunk) {
            // 检查是否超时
            if ($timeLimit !== null) {
                $elapsedTime = microtime(true) - $startTime;
                if ($elapsedTime >= ($timeLimit - 2)) {
                    return -1;
                }
            }
            
            // 累积原始响应（限制大小，仅用于错误诊断）
            if (strlen($rawResponseBuffer) < 4096) {
                $rawResponseBuffer .= $data;
            }
            
            $lines = explode("\n", $data);
            
            foreach ($lines as $line) {
                $line = trim($line);
                
                if (empty($line) || !str_starts_with($line, 'data: ')) {
                    continue;
                }
                
                $jsonData = substr($line, 6);
                
                if ($jsonData === '[DONE]') {
                    continue;
                }
                
                $event = json_decode($jsonData, true);
                
                if (!$event) {
                    continue;
                }
                
                // Anthropic流式响应格式处理
                $type = $event['type'] ?? '';
                
                switch ($type) {
                    case 'content_block_delta':
                        $delta = $event['delta'] ?? [];
                        if (($delta['type'] ?? '') === 'text_delta') {
                            $hasValidChunk = true;
                            $callback($delta['text'] ?? '');
                        }
                        break;
                    
                    case 'message_delta':
                        // 消息结束时可能包含usage信息
                        $usage = $event['usage'] ?? null;
                        if ($usage) {
                            $callback('', $usage);
                        }
                        break;
                    
                    case 'error':
                        // Anthropic 错误事件
                        $errorMsg = $event['error']['message'] ?? ($event['message'] ?? 'Unknown Anthropic error');
                        throw new Exception("Anthropic API 错误: {$errorMsg}");
                }
            }
            
            return strlen($data);
        });

        curl_exec($ch);
        
        // 获取 HTTP 状态码
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        $lastError = error_get_last();
        if ($lastError && (
            strpos($lastError['message'], 'Maximum execution time') !== false ||
            strpos($lastError['message'], 'exceeded') !== false
        )) {
            curl_close($ch);
            throw new Exception($this->getTimeoutErrorMessage($timeout));
        }
        
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            if (strpos($error, 'timeout') !== false || strpos($error, 'timed out') !== false) {
                throw new Exception($this->getTimeoutErrorMessage($timeout));
            }
            throw new Exception("流式API调用失败: {$error}");
        }
        
        // 检查 HTTP 状态码
        if ($httpCode !== 200) {
            $errorMsg = $this->parseApiErrorResponse($rawResponseBuffer, $httpCode);
            throw new Exception($errorMsg);
        }
        
        // 检查是否收到了有效内容
        if (!$hasValidChunk && !empty($rawResponseBuffer)) {
            $errorMsg = $this->parseApiErrorResponse($rawResponseBuffer, $httpCode);
            throw new Exception($errorMsg);
        }
    }

    /**
     * 初始化CURL（Anthropic特定配置）
     * 
     * @param string $url
     * @param string $apiKey
     * @param array $data
     * @param array $proxyInfo
     * @param int $timeout
     * @return \CurlHandle|false
     */
    private function initCurl(string $url, string $apiKey, array $data, array $proxyInfo, int $timeout): \CurlHandle|false
    {
        $ch = curl_init($url);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        
        // Anthropic使用x-api-key和anthropic-version头
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . self::API_VERSION,
        ]);
        
        $timeout = max(0, (int)$timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        if ($timeout > 0) {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min($timeout, 60));
        } else {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
        }
        
        // SSL配置
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        if ($isWindows) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        }

        // 代理配置
        if (!empty($proxyInfo['enabled'])) {
            $proxy = $proxyInfo['host'] . ':' . $proxyInfo['port'];
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            
            if (!empty($proxyInfo['username']) && !empty($proxyInfo['password'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyInfo['username'] . ':' . $proxyInfo['password']);
            }
        }

        return $ch;
    }

    /**
     * 获取API密钥
     * 
     * @param array $config
     * @return string
     */
    private function getApiKey(array $config): string
    {
        // 优先使用环境变量
        if (!empty($config['api_key_env'])) {
            $envKey = getenv($config['api_key_env']);
            if ($envKey) {
                return $envKey;
            }
        }

        // 使用配置中的密钥
        return $config['api_key'] ?? '';
    }

    /**
     * 估算token数量
     * 
     * @param string $text
     * @return int
     */
    private function estimateTokens(string $text): int
    {
        // Claude的token估算与GPT类似
        $englishChars = preg_match_all('/[a-zA-Z0-9\s]/', $text);
        $otherChars = mb_strlen($text) - $englishChars;
        
        return (int)ceil($englishChars / 4 + $otherChars / 1.5);
    }

    /**
     * 检查模型支持
     * 
     * @param string $modelCode
     * @return bool
     */
    public function supports(string $modelCode): bool
    {
        // 支持Claude系列模型
        return str_starts_with($modelCode, 'claude-') || str_contains($modelCode, 'claude');
    }

    /**
     * 获取供应商代码
     * 
     * @return string
     */
    public function getProviderCode(): string
    {
        return 'anthropic';
    }

    /**
     * 获取该供应商支持的模型列表
     * 
     * @return array
     */
    public function getSupportedModels(): array
    {
        return VendorConfigManager::getProviderModels($this->getProviderCode());
    }

    /**
     * 获取超时错误消息
     * 
     * @param int $timeout 超时时间（秒）
     * @return string
     */
    private function getTimeoutErrorMessage(int $timeout): string
    {
        $message = '';
        if ($timeout > 0) {
            $message = sprintf(
                __('AI请求超时（已设置超时时间为 %d 秒）。这可能是因为：1) 网络连接较慢；2) AI服务响应较慢；3) 请求内容较复杂。建议：1) 检查网络连接；2) 尝试增加超时时间设置；3) 简化请求内容；4) 稍后重试。'),
                $timeout
            );
        } else {
            $message = __('AI请求超时。这可能是因为：1) 网络连接较慢；2) AI服务响应较慢；3) 请求内容较复杂。建议：1) 检查网络连接；2) 尝试设置合理的超时时间；3) 简化请求内容；4) 稍后重试。');
        }
        
        $message = strip_tags($message);
        $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $message = trim(preg_replace('/\s+/', ' ', $message));
        
        return $message;
    }

    /**
     * 解析 API 错误响应，提取可读的错误信息
     */
    private function parseApiErrorResponse(string $rawResponse, int $httpCode): string
    {
        $trimmed = trim($rawResponse);
        
        // 尝试解析 JSON 错误响应
        if (!empty($trimmed)) {
            $errorData = json_decode($trimmed, true);
            if (is_array($errorData)) {
                // Anthropic 格式: {"type": "error", "error": {"type": "...", "message": "..."}}
                if (isset($errorData['error']['message'])) {
                    $errType = $errorData['error']['type'] ?? 'api_error';
                    return "Anthropic API 错误 (HTTP {$httpCode}, {$errType}): " . $errorData['error']['message'];
                }
                if (isset($errorData['message'])) {
                    return "Anthropic API 错误 (HTTP {$httpCode}): " . $errorData['message'];
                }
            }
        }
        
        // HTTP 状态码友好提示
        $statusMessages = [
            401 => 'API 密钥无效或已过期，请检查 AI 模型配置中的 API Key',
            403 => 'API 访问被拒绝，请检查账户权限',
            404 => 'API 端点不存在，请检查 Base URL 配置',
            429 => 'API 请求频率超限或额度不足，请稍后重试',
            500 => 'AI 服务内部错误，请稍后重试',
            502 => 'AI 服务网关错误，请稍后重试',
            503 => 'AI 服务暂时不可用，请稍后重试',
            529 => 'Anthropic API 过载，请稍后重试',
        ];
        
        if (isset($statusMessages[$httpCode])) {
            return "Anthropic API 错误 (HTTP {$httpCode}): " . $statusMessages[$httpCode];
        }
        
        if ($httpCode > 0) {
            $preview = mb_substr($trimmed, 0, 200);
            return "Anthropic API 返回异常 (HTTP {$httpCode})" . ($preview ? "，响应: {$preview}" : '');
        }
        
        return "Anthropic API 无响应，请检查网络连接和 API 地址配置";
    }
}
