<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/10/09
 */

namespace Weline\Ai\Service\Provider;

use Weline\Ai\Model\AiModel;
use Weline\Ai\Helper\ErrorMessageHelper;
use Weline\Framework\App\Exception;

/**
 * OpenAI API提供者
 * 
 * 功能：
 * - 调用OpenAI API生成内容
 * - 支持流式响应
 * - 支持代理配置
 * - 错误处理和重试机制
 * - Token使用量统计
 */
class OpenAiProvider implements ProviderInterface
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
     * 构造函数
     */
    public function __construct()
    {
        // 无参数构造函数，用于依赖注入兼容性
    }

    /**
     * 调用OpenAI API
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
        // 超时优先级：params.timeout > config.timeout > 默认180秒；0 表示不限制
        $timeout = isset($params['timeout']) ? (int)$params['timeout'] : (isset($config['timeout']) ? (int)$config['timeout'] : 180);
        
        // 设置执行时间限制，确保有足够的时间完成请求
        if ($timeout > 0) {
            // 设置时间限制为 timeout + 10 秒的缓冲时间
            $timeLimit = $timeout + 10;
            @set_time_limit($timeLimit);
            
            // 检查当前剩余时间是否足够
            $currentLimit = ini_get('max_execution_time');
            if ($currentLimit > 0 && $currentLimit < $timeLimit) {
                // 如果当前限制小于需要的限制，尝试增加
                @set_time_limit($timeLimit);
            }
        } else {
            @set_time_limit(0);
        }
        $requestData = [
            'model' => $config['model'] ?? $model->getModelCode(),
            'messages' => $messages,
            'temperature' => (float)($params['temperature'] ?? $config['temperature'] ?? 0.7),
            'max_tokens' => (int)($params['max_tokens'] ?? $config['max_tokens'] ?? 2000),
            'top_p' => (float)($params['top_p'] ?? $config['top_p'] ?? 1.0),
            'frequency_penalty' => (float)($params['frequency_penalty'] ?? $config['frequency_penalty'] ?? 0.0),
            'presence_penalty' => (float)($params['presence_penalty'] ?? $config['presence_penalty'] ?? 0.0),
            'stream' => false,
        ];

        // 智能体模式：添加 tools（function calling）
        if (!empty($params['tools']) && is_array($params['tools'])) {
            $requestData['tools'] = $this->convertToolsToOpenAiFormat($params['tools']);
            $requestData['tool_choice'] = $params['tool_choice'] ?? 'auto';
        }

        // 优先使用base_url，如果没有则使用api_url，最后使用默认值
        $apiUrl = $config['base_url'] ?? $config['api_url'] ?? 'https://api.openai.com/v1';
        if (!str_ends_with($apiUrl, '/chat/completions')) {
            $apiUrl = rtrim($apiUrl, '/') . '/chat/completions';
        }
        
        // 确保proxyInfo是数组（可能存储为 JSON 字符串）
        $proxyInfo = $model->getProxyInfo();
        if (is_string($proxyInfo) && !empty($proxyInfo)) {
            $proxyInfo = json_decode($proxyInfo, true) ?: [];
        }
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
        $finishReason = $response['choices'][0]['finish_reason'] ?? '';

        $message = $response['choices'][0]['message'] ?? [];
        $result = [
            'content' => $message['content'] ?? '',
            'usage' => [
                'prompt_tokens' => $response['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $response['usage']['completion_tokens'] ?? 0,
                'total_tokens' => $response['usage']['total_tokens'] ?? 0,
            ],
            'model' => $response['model'] ?? '',
            'finish_reason' => $finishReason,
        ];

        // 捕获推理/思考链内容（DeepSeek reasoning_content 等）
        if (!empty($message['reasoning_content'])) {
            $result['reasoning_content'] = $message['reasoning_content'];
        }

        // 如果有 tool_calls，附加到结果中
        if (!empty($toolCalls)) {
            $result['tool_calls'] = $toolCalls;
            // 保留原始 message 用于构建后续消息（agent 需要完整的 assistant message）
            $result['assistant_message'] = $message;
        }

        return $result;
    }

    /**
     * 流式生成并返回完整结构化响应（含 tool_calls）
     * 
     * 用于智能体模式：使用流式传输保持连接活跃，同时通过回调实时推送
     * reasoning_content 和 content，最终返回与 generate() 相同格式的结果。
     * 
     * @param AiModel $model
     * @param string $prompt
     * @param array $params 额外参数，支持：
     *   - messages: 消息数组
     *   - tools: 工具定义
     *   - on_reasoning: callable(string $chunk) 推理内容回调
     *   - on_content: callable(string $chunk) 正文内容回调
     *   - on_heartbeat: callable() 心跳回调（每收到数据就触发）
     * @return array 与 generate() 相同格式：content, reasoning_content, tool_calls, finish_reason, usage, assistant_message
     * @throws Exception
     */
    public function generateStreamFull(AiModel $model, string $prompt, array $params = []): array
    {
        $config = $model->getConfig();

        // 合并 provider_config
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
        $timeout = isset($params['timeout']) ? (int)$params['timeout'] : (isset($config['timeout']) ? (int)$config['timeout'] : 180);

        $requestData = [
            'model' => $config['model'] ?? $model->getModelCode(),
            'messages' => $messages,
            'temperature' => (float)($params['temperature'] ?? $config['temperature'] ?? 0.7),
            'max_tokens' => (int)($params['max_tokens'] ?? $config['max_tokens'] ?? 2000),
            'stream' => true,
        ];

        // 智能体模式：添加 tools
        if (!empty($params['tools']) && is_array($params['tools'])) {
            $requestData['tools'] = $this->convertToolsToOpenAiFormat($params['tools']);
            $requestData['tool_choice'] = $params['tool_choice'] ?? 'auto';
        }

        $apiUrl = $config['base_url'] ?? $config['api_url'] ?? 'https://api.openai.com/v1';
        if (!str_ends_with($apiUrl, '/chat/completions')) {
            $apiUrl = rtrim($apiUrl, '/') . '/chat/completions';
        }

        $proxyInfo = $model->getProxyInfo();
        if (is_string($proxyInfo) && !empty($proxyInfo)) {
            $proxyInfo = json_decode($proxyInfo, true) ?: [];
        }
        if (!is_array($proxyInfo)) {
            $proxyInfo = [];
        }

        // 回调
        $onReasoning = $params['on_reasoning'] ?? null;
        $onContent = $params['on_content'] ?? null;
        $onHeartbeat = $params['on_heartbeat'] ?? null;

        // 累积器
        $fullContent = '';
        $fullReasoning = '';
        $toolCallsAccum = []; // index => ['id'=>..., 'name'=>..., 'arguments'=>'']
        $finishReason = '';
        $modelName = '';

        $ch = $this->initCurl($apiUrl, $apiKey, $requestData, $proxyInfo, $timeout);

        $rawResponseBuffer = '';
        $hasValidChunk = false;
        $startTime = microtime(true);

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $data) use (
            &$fullContent, &$fullReasoning, &$toolCallsAccum, &$finishReason, &$modelName,
            &$rawResponseBuffer, &$hasValidChunk, $startTime, $timeout,
            $onReasoning, $onContent, $onHeartbeat
        ) {
            if (strlen($rawResponseBuffer) < 4096) {
                $rawResponseBuffer .= $data;
            }

            // 心跳：收到任何数据都触发
            if ($onHeartbeat) {
                $onHeartbeat();
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

                $chunk = json_decode($jsonData, true);
                if (!is_array($chunk)) {
                    continue;
                }

                $hasValidChunk = true;
                $delta = $chunk['choices'][0]['delta'] ?? [];
                $choiceFinish = $chunk['choices'][0]['finish_reason'] ?? null;
                if ($choiceFinish) {
                    $finishReason = $choiceFinish;
                }
                if (!empty($chunk['model'])) {
                    $modelName = $chunk['model'];
                }

                // 推理/思考内容
                if (!empty($delta['reasoning_content'])) {
                    $fullReasoning .= $delta['reasoning_content'];
                    if ($onReasoning) {
                        $onReasoning($delta['reasoning_content']);
                    }
                }

                // 正文内容
                if (isset($delta['content']) && $delta['content'] !== '') {
                    $fullContent .= $delta['content'];
                    if ($onContent) {
                        $onContent($delta['content']);
                    }
                }

                // tool_calls 增量累积
                if (!empty($delta['tool_calls'])) {
                    foreach ($delta['tool_calls'] as $tc) {
                        $idx = $tc['index'] ?? 0;
                        if (!isset($toolCallsAccum[$idx])) {
                            $toolCallsAccum[$idx] = [
                                'id' => $tc['id'] ?? uniqid('tc_'),
                                'name' => $tc['function']['name'] ?? '',
                                'arguments' => '',
                            ];
                        }
                        if (isset($tc['id'])) {
                            $toolCallsAccum[$idx]['id'] = $tc['id'];
                        }
                        if (!empty($tc['function']['name'])) {
                            $toolCallsAccum[$idx]['name'] = $tc['function']['name'];
                        }
                        if (isset($tc['function']['arguments'])) {
                            $toolCallsAccum[$idx]['arguments'] .= $tc['function']['arguments'];
                        }
                    }
                }
            }

            return strlen($data);
        });

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            if (strpos($error, 'timeout') !== false || strpos($error, 'timed out') !== false) {
                throw new Exception($this->getTimeoutErrorMessage($timeout));
            }
            throw new Exception("流式API调用失败: {$error}");
        }
        if ($httpCode !== 200) {
            throw new Exception($this->parseApiErrorResponse($rawResponseBuffer, $httpCode));
        }
        if (!$hasValidChunk && !empty($rawResponseBuffer)) {
            throw new Exception($this->parseApiErrorResponse($rawResponseBuffer, $httpCode));
        }

        // 构建 tool_calls（与 generate() 相同格式）
        $toolCalls = [];
        foreach ($toolCallsAccum as $tc) {
            $args = $tc['arguments'];
            if (is_string($args)) {
                $args = json_decode($args, true) ?: [];
            }
            $toolCalls[] = [
                'id' => $tc['id'],
                'name' => $tc['name'],
                'arguments' => $args,
            ];
        }

        // 构建结果（与 generate() 返回格式一致）
        $result = [
            'content' => $fullContent,
            'usage' => [
                'prompt_tokens' => $this->estimateTokens(json_encode($requestData['messages'])),
                'completion_tokens' => $this->estimateTokens($fullContent . $fullReasoning),
                'total_tokens' => 0,
            ],
            'model' => $modelName,
            'finish_reason' => $finishReason,
        ];
        $result['usage']['total_tokens'] = $result['usage']['prompt_tokens'] + $result['usage']['completion_tokens'];

        if (!empty($fullReasoning)) {
            $result['reasoning_content'] = $fullReasoning;
        }

        if (!empty($toolCalls)) {
            $result['tool_calls'] = $toolCalls;
            // 构建 assistant_message（用于后续消息历史）
            $result['assistant_message'] = [
                'role' => 'assistant',
                'content' => $fullContent ?: null,
                'tool_calls' => array_map(fn($tc) => [
                    'id' => $tc['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $tc['name'],
                        'arguments' => json_encode($tc['arguments']),
                    ],
                ], $toolCalls),
            ];
        }

        return $result;
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
        // 超时优先级：params.timeout > config.timeout > 默认180秒；0 表示不限制
        $timeout = isset($params['timeout']) ? (int)$params['timeout'] : (isset($config['timeout']) ? (int)$config['timeout'] : 180);
        
        // 调试日志：记录超时时间的来源和值
        if (DEV) {
            $timeoutSource = isset($params['timeout']) ? 'params' : (isset($config['timeout']) ? 'model_config' : 'default');
            error_log(sprintf('AI流式超时设置: %d秒 (来源: %s, model_code: %s, config_keys: %s)', 
                $timeout, 
                $timeoutSource, 
                $model->getModelCode(),
                implode(',', array_keys($config))
            ));
        }
        
        // 设置执行时间限制，确保有足够的时间完成请求
        if ($timeout > 0) {
            // 设置时间限制为 timeout + 10 秒的缓冲时间
            $timeLimit = $timeout + 10;
            @set_time_limit($timeLimit);
            
            // 检查当前剩余时间是否足够
            $currentLimit = ini_get('max_execution_time');
            if ($currentLimit > 0 && $currentLimit < $timeLimit) {
                // 如果当前限制小于需要的限制，尝试增加
                @set_time_limit($timeLimit);
            }
        } else {
            @set_time_limit(0);
        }
        $requestData = [
            'model' => $config['model'] ?? $model->getModelCode(),
            'messages' => $messages,
            'temperature' => (float)($params['temperature'] ?? $config['temperature'] ?? 0.7),
            'max_tokens' => (int)($params['max_tokens'] ?? $config['max_tokens'] ?? 2000),
            'stream' => true,
        ];

        // 智能体模式：添加 tools（function calling）
        if (!empty($params['tools']) && is_array($params['tools'])) {
            $requestData['tools'] = $this->convertToolsToOpenAiFormat($params['tools']);
            $requestData['tool_choice'] = $params['tool_choice'] ?? 'auto';
        }

        $totalTokens = [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
        ];

        $fullContent = '';
        
        // 优先使用base_url，如果没有则使用api_url，最后使用默认值
        $apiUrl = $config['base_url'] ?? $config['api_url'] ?? 'https://api.openai.com/v1';
        if (!str_ends_with($apiUrl, '/chat/completions')) {
            $apiUrl = rtrim($apiUrl, '/') . '/chat/completions';
        }
        
        // 确保proxyInfo是数组（可能存储为 JSON 字符串）
        $proxyInfo = $model->getProxyInfo();
        if (is_string($proxyInfo) && !empty($proxyInfo)) {
            $proxyInfo = json_decode($proxyInfo, true) ?: [];
        }
        if (!is_array($proxyInfo)) {
            $proxyInfo = [];
        }
        
        // 推理/思考内容回调
        $reasoningCallback = $params['reasoning_callback'] ?? null;
        $fullReasoning = '';

        $this->callStreamApi(
            $apiUrl,
            $apiKey,
            $requestData,
            function($chunk) use ($callback, &$fullContent) {
                $fullContent .= $chunk;
                $callback($chunk);
            },
            $proxyInfo,
            $timeout,
            $reasoningCallback ? function($chunk) use ($reasoningCallback, &$fullReasoning) {
                $fullReasoning .= $chunk;
                $reasoningCallback($chunk);
            } : null
        );

        // 如果流式调用没有返回任何内容，抛出明确错误
        if (empty(trim($fullContent)) && empty(trim($fullReasoning))) {
            throw new Exception('AI 流式生成完成但未返回任何内容，请检查模型配置（API Key、Base URL、模型名称）是否正确');
        }

        // 估算token使用量
        $totalTokens['completion_tokens'] = $this->estimateTokens($fullContent);
        $totalTokens['prompt_tokens'] = $this->estimateTokens($prompt);
        $totalTokens['total_tokens'] = $totalTokens['prompt_tokens'] + $totalTokens['completion_tokens'];

        $result = [
            'content' => $fullContent,
            'usage' => $totalTokens,
        ];

        if (!empty($fullReasoning)) {
            $result['reasoning_content'] = $fullReasoning;
        }

        return $result;
    }

    /**
     * 构建消息数组
     * 
     * @param string $prompt
     * @param array $params
     * @return array
     */
    private function buildMessages(string $prompt, array $params): array
    {
        // 智能体模式：直接使用完整消息历史
        if (!empty($params['messages']) && is_array($params['messages'])) {
            return $params['messages'];
        }

        $messages = [];

        // 系统消息
        if (!empty($params['system_message'])) {
            $messages[] = [
                'role' => 'system',
                'content' => $params['system_message']
            ];
        }

        // 历史对话
        if (!empty($params['history']) && is_array($params['history'])) {
            $messages = array_merge($messages, $params['history']);
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
     * 将框架中间格式的 Tool 定义转换为 OpenAI function calling 格式
     * 
     * 框架格式：[['name' => '...', 'description' => '...', 'parameters' => [...]]]
     * OpenAI 格式：[['type' => 'function', 'function' => ['name' => '...', 'description' => '...', 'parameters' => [...]]]]
     * 
     * @param array $tools 框架中间格式的 Tool 定义
     * @return array OpenAI 格式
     */
    private function convertToolsToOpenAiFormat(array $tools): array
    {
        $openAiTools = [];
        foreach ($tools as $tool) {
            $openAiTools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters' => $tool['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
                ],
            ];
        }
        return $openAiTools;
    }

    /**
     * 从 OpenAI 响应中提取 tool_calls
     * 
     * @param array $response OpenAI API 响应
     * @return array 标准化的 tool_calls 数组
     */
    private function extractToolCalls(array $response): array
    {
        $toolCalls = [];
        $message = $response['choices'][0]['message'] ?? [];

        if (!empty($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $tc) {
                $arguments = $tc['function']['arguments'] ?? '{}';
                if (is_string($arguments)) {
                    $arguments = json_decode($arguments, true) ?: [];
                }
                $toolCalls[] = [
                    'id' => $tc['id'] ?? uniqid('tc_'),
                    'name' => $tc['function']['name'] ?? '',
                    'arguments' => $arguments,
                ];
            }
        }

        return $toolCalls;
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
        // 记录开始时间，用于检测超时（在方法开始时记录）
        $startTime = microtime(true);
        $maxExecutionTime = ini_get('max_execution_time');
        $timeLimit = $maxExecutionTime > 0 ? (int)$maxExecutionTime : null;
        
        try {
            $ch = $this->initCurl($url, $apiKey, $data, $proxyInfo, $timeout);
            
            // 在执行前检查剩余时间，如果时间不足，提前抛出错误
            if ($timeLimit !== null && $timeLimit > 0) {
                $elapsedBeforeRequest = microtime(true) - $startTime;
                $remainingTime = $timeLimit - $elapsedBeforeRequest;
                // 如果剩余时间少于5秒，提前抛出错误
                if ($remainingTime < 5) {
                    throw new Exception($this->getTimeoutErrorMessage($timeout));
                }
            }
            
            // 清除之前的错误
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
            
            // 检查执行时间是否接近限制
            $elapsedTime = microtime(true) - $startTime;
            if ($timeLimit !== null && $elapsedTime >= ($timeLimit - 2)) {
                throw new Exception($this->getTimeoutErrorMessage($timeout));
            }
            
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            if ($response === false) {
                // 检查是否是超时错误
                if (strpos($error, 'timeout') !== false || strpos($error, 'timed out') !== false) {
                    throw new Exception($this->getTimeoutErrorMessage($timeout));
                }
                throw new Exception("API请求失败: {$error}");
            }

            $result = json_decode($response, true);
            
            if ($httpCode >= 500 && $retryCount < self::MAX_RETRIES) {
                // 服务器错误，重试
                sleep(self::RETRY_DELAY * ($retryCount + 1));
                return $this->callApiWithRetry($url, $apiKey, $data, $proxyInfo, $timeout, $retryCount + 1);
            }

            if ($httpCode !== 200) {
                $errorMsg = $result['error']['message'] ?? "HTTP错误: {$httpCode}";
                throw new Exception("API返回错误: {$errorMsg}");
            }

            if (!isset($result['choices'][0]['message']['content'])) {
                throw new Exception("API响应格式错误");
            }

            return $result;

        } catch (\Exception $e) {
            // 如果是超时错误，直接抛出，不重试
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
    private function callStreamApi(string $url, string $apiKey, array $data, callable $callback, array $proxyInfo, int $timeout, ?callable $reasoningCallback = null): void
    {
        // 记录开始时间，用于检测超时（在方法开始时记录）
        $startTime = microtime(true);
        $maxExecutionTime = ini_get('max_execution_time');
        $timeLimit = $maxExecutionTime > 0 ? (int)$maxExecutionTime : null;
        
        $ch = $this->initCurl($url, $apiKey, $data, $proxyInfo, $timeout);
        
        // 在执行前检查剩余时间，如果时间不足，提前抛出错误
        if ($timeLimit !== null && $timeLimit > 0) {
            $elapsedBeforeRequest = microtime(true) - $startTime;
            $remainingTime = $timeLimit - $elapsedBeforeRequest;
            // 如果剩余时间少于5秒，提前抛出错误
            if ($remainingTime < 5) {
                throw new Exception($this->getTimeoutErrorMessage($timeout));
            }
        }
        
        // 清除之前的错误
        error_clear_last();
        
        // 用于捕获非 SSE 格式的响应（API 错误等）
        $rawResponseBuffer = '';
        $hasValidChunk = false;
        
        // 设置流式处理回调
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) use ($callback, $reasoningCallback, $startTime, $timeLimit, &$rawResponseBuffer, &$hasValidChunk) {
            // 检查是否超时
            if ($timeLimit !== null) {
                $elapsedTime = microtime(true) - $startTime;
                if ($elapsedTime >= ($timeLimit - 2)) {
                    return -1; // 返回 -1 会中断 curl_exec
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
                
                $chunk = json_decode($jsonData, true);
                $delta = $chunk['choices'][0]['delta'] ?? [];
                
                // 处理推理/思考内容（DeepSeek reasoning_content）
                if (!empty($delta['reasoning_content']) && $reasoningCallback) {
                    $hasValidChunk = true;
                    $reasoningCallback($delta['reasoning_content']);
                }
                
                // 处理正文内容
                if (isset($delta['content'])) {
                    $hasValidChunk = true;
                    $callback($delta['content']);
                }
            }
            
            return strlen($data);
        });

        curl_exec($ch);
        
        // 获取 HTTP 状态码
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // 检查是否超时
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
            // 检查是否是超时错误
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
     * 解析 API 错误响应，提取可读的错误信息
     */
    private function parseApiErrorResponse(string $rawResponse, int $httpCode): string
    {
        $trimmed = trim($rawResponse);
        
        // 尝试解析 JSON 错误响应
        if (!empty($trimmed)) {
            $errorData = json_decode($trimmed, true);
            if (is_array($errorData)) {
                // OpenAI 格式: {"error": {"message": "...", "type": "..."}}
                if (isset($errorData['error']['message'])) {
                    $errType = $errorData['error']['type'] ?? 'api_error';
                    return "AI API 错误 (HTTP {$httpCode}, {$errType}): " . $errorData['error']['message'];
                }
                // 其他格式: {"message": "..."} 或 {"detail": "..."}
                if (isset($errorData['message'])) {
                    return "AI API 错误 (HTTP {$httpCode}): " . $errorData['message'];
                }
                if (isset($errorData['detail'])) {
                    return "AI API 错误 (HTTP {$httpCode}): " . $errorData['detail'];
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
        ];
        
        if (isset($statusMessages[$httpCode])) {
            return "AI API 错误 (HTTP {$httpCode}): " . $statusMessages[$httpCode];
        }
        
        if ($httpCode > 0) {
            $preview = mb_substr($trimmed, 0, 200);
            return "AI API 返回异常 (HTTP {$httpCode})" . ($preview ? "，响应: {$preview}" : '');
        }
        
        return "AI API 无响应，请检查网络连接和 API 地址配置";
    }

    /**
     * 初始化CURL
     * 
     * @param string $url
     * @param string $apiKey
     * @param array $data
     * @param array $proxyInfo
     * @return \CurlHandle|false
     */
    private function initCurl(string $url, string $apiKey, array $data, array $proxyInfo, int $timeout): \CurlHandle|false
    {
        $ch = curl_init($url);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ]);
        // 根据模型配置设置超时（秒）；0 表示不限制
        $timeout = max(0, (int)$timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        // 连接超时单独设置，防止长时间卡在连接阶段（不超过60秒）
        if ($timeout > 0) {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min($timeout, 60));
        } else {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
        }
        
        // SSL配置：在Windows本地开发环境中，可能需要跳过SSL验证
        // 生产环境建议配置正确的CA证书包
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        if ($isWindows) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            // Windows 双栈网络可能导致 IPv6 超时，强制使用 IPv4
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
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
        // 简单估算：英文约4字符=1token，中文约1.5字符=1token
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
        // 支持OpenAI和兼容OpenAI API的模型（如DeepSeek等，但不包括Claude，Claude使用AnthropicProvider）
        return str_contains($modelCode, 'gpt') 
            || str_contains($modelCode, 'openai') 
            || str_contains($modelCode, 'deepseek')
            || str_starts_with($modelCode, 'o1-')
            || str_starts_with($modelCode, 'o3-');
    }

    /**
     * 获取供应商代码
     * 
     * @return string
     */
    public function getProviderCode(): string
    {
        return 'openai';
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
     * @return string 友好的错误消息（纯文本，不包含HTML标签）
     */
    private function getTimeoutErrorMessage(int $timeout): string
    {
        // 生成纯文本错误消息，去除所有HTML标签
        $message = '';
        if ($timeout > 0) {
            $message = sprintf(
                __('AI请求超时（已设置超时时间为 %d 秒）。这可能是因为：1) 网络连接较慢；2) AI服务响应较慢；3) 请求内容较复杂。建议：1) 检查网络连接；2) 尝试增加超时时间设置；3) 简化请求内容；4) 稍后重试。'),
                $timeout
            );
        } else {
            $message = __('AI请求超时。这可能是因为：1) 网络连接较慢；2) AI服务响应较慢；3) 请求内容较复杂。建议：1) 检查网络连接；2) 尝试设置合理的超时时间；3) 简化请求内容；4) 稍后重试。');
        }
        
        // 去除所有HTML标签，只保留纯文本
        $message = strip_tags($message);
        // 解码HTML实体
        $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // 去除多余的空白字符
        $message = trim(preg_replace('/\s+/', ' ', $message));
        
        return $message;
    }
}

