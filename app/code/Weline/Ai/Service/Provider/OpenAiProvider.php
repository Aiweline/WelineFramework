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
            throw new Exception('OpenAI API密钥未配置');
        }

        $messages = $this->buildMessages($prompt, $params);
        // 超时优先级：params.timeout > config.timeout；0 表示不限制
        $timeout = isset($params['timeout']) ? (int)$params['timeout'] : (isset($config['timeout']) ? (int)$config['timeout'] : 100);
        if ($timeout > 0) { @set_time_limit($timeout + 10); } else { @set_time_limit(0); }
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
            throw new Exception('OpenAI API密钥未配置');
        }

        $messages = $this->buildMessages($prompt, $params);
        $timeout = isset($params['timeout']) ? (int)$params['timeout'] : (isset($config['timeout']) ? (int)$config['timeout'] : 100);
        if ($timeout > 0) { @set_time_limit($timeout + 10); } else { @set_time_limit(0); }
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
     * @param int $retryCount
     * @return array
     * @throws Exception
     */
    private function callApiWithRetry(string $url, string $apiKey, array $data, array $proxyInfo, int $timeout, int $retryCount = 0): array
    {
        try {
            $ch = $this->initCurl($url, $apiKey, $data, $proxyInfo, $timeout);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
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
     * @throws Exception
     */
    private function callStreamApi(string $url, string $apiKey, array $data, callable $callback, array $proxyInfo, int $timeout): void
    {
        $ch = $this->initCurl($url, $apiKey, $data, $proxyInfo, $timeout);
        
        // 设置流式处理回调
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) use ($callback) {
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
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
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
     * @return resource
     */
    private function initCurl(string $url, string $apiKey, array $data, array $proxyInfo, int $timeout)
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
        // 支持OpenAI和兼容OpenAI API的模型（如DeepSeek、Claude等）
        return str_contains($modelCode, 'gpt') 
            || str_contains($modelCode, 'openai') 
            || str_contains($modelCode, 'deepseek')
            || str_contains($modelCode, 'claude');
    }
}

