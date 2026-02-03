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
            'temperature' => $params['temperature'] ?? $config['temperature'] ?? 0.7,
            'max_tokens' => $params['max_tokens'] ?? $config['max_tokens'] ?? 2000,
            'top_p' => $params['top_p'] ?? $config['top_p'] ?? 1.0,
            'frequency_penalty' => $params['frequency_penalty'] ?? $config['frequency_penalty'] ?? 0.0,
            'presence_penalty' => $params['presence_penalty'] ?? $config['presence_penalty'] ?? 0.0,
            'stream' => false,
        ];

        // 优先使用base_url，如果没有则使用api_url，最后使用默认值
        $apiUrl = $config['base_url'] ?? $config['api_url'] ?? 'https://api.openai.com/v1';
        if (!str_ends_with($apiUrl, '/chat/completions')) {
            $apiUrl = rtrim($apiUrl, '/') . '/chat/completions';
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

        return [
            'content' => $response['choices'][0]['message']['content'] ?? '',
            'usage' => [
                'prompt_tokens' => $response['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $response['usage']['completion_tokens'] ?? 0,
                'total_tokens' => $response['usage']['total_tokens'] ?? 0,
            ],
            'model' => $response['model'] ?? '',
            'finish_reason' => $response['choices'][0]['finish_reason'] ?? '',
        ];
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
            'temperature' => $params['temperature'] ?? $config['temperature'] ?? 0.7,
            'max_tokens' => $params['max_tokens'] ?? $config['max_tokens'] ?? 2000,
            'stream' => true,
        ];

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
        
        // 确保proxyInfo是数组
        $proxyInfo = $model->getProxyInfo();
        if (!is_array($proxyInfo)) {
            $proxyInfo = [];
        }
        
        $this->callStreamApi(
            $apiUrl,
            $apiKey,
            $requestData,
            function($chunk) use ($callback, &$fullContent) {
                $fullContent .= $chunk;
                $callback($chunk);
            },
            $proxyInfo,
            $timeout
        );

        // 估算token使用量
        $totalTokens['completion_tokens'] = $this->estimateTokens($fullContent);
        $totalTokens['prompt_tokens'] = $this->estimateTokens($prompt);
        $totalTokens['total_tokens'] = $totalTokens['prompt_tokens'] + $totalTokens['completion_tokens'];

        return [
            'content' => $fullContent,
            'usage' => $totalTokens,
        ];
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
        $messages[] = [
            'role' => 'user',
            'content' => $prompt
        ];

        return $messages;
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
    private function callStreamApi(string $url, string $apiKey, array $data, callable $callback, array $proxyInfo, int $timeout): void
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
        
        // 设置流式处理回调
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) use ($callback, $startTime, $timeLimit) {
            // 检查是否超时
            if ($timeLimit !== null) {
                $elapsedTime = microtime(true) - $startTime;
                if ($elapsedTime >= ($timeLimit - 2)) {
                    return -1; // 返回 -1 会中断 curl_exec
                }
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
                
                if (isset($chunk['choices'][0]['delta']['content'])) {
                    $callback($chunk['choices'][0]['delta']['content']);
                }
            }
            
            return strlen($data);
        });

        curl_exec($ch);
        
        // 检查是否超时
        $lastError = error_get_last();
        if ($lastError && (
            strpos($lastError['message'], 'Maximum execution time') !== false ||
            strpos($lastError['message'], 'exceeded') !== false
        )) {
            throw new Exception($this->getTimeoutErrorMessage($timeout));
        }
        
        $error = curl_error($ch);

        if ($error) {
            // 检查是否是超时错误
            if (strpos($error, 'timeout') !== false || strpos($error, 'timed out') !== false) {
                throw new Exception($this->getTimeoutErrorMessage($timeout));
            }
            throw new Exception("流式API调用失败: {$error}");
        }
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

