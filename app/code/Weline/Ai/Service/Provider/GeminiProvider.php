<?php
declare(strict_types=1);

namespace Weline\Ai\Service\Provider;

use Weline\Ai\Helper\ErrorMessageHelper;
use Weline\Ai\Model\AiModel;
use Weline\Framework\App\Exception;

class GeminiProvider implements ProviderInterface, ImageGenerationProviderInterface, ModelListingProviderInterface, ProviderConnectionTestInterface
{
    use ModelListingProviderTrait;
    use ProviderConnectionTestTrait;

    private const MAX_RETRIES = 3;
    private const RETRY_DELAY = 1;

    public function generate(AiModel $model, string $prompt, array $params = []): array
    {
        $config = $this->resolveConfig($model, $params);
        $apiKey = $this->getApiKey($config);
        if ($apiKey === '') {
            throw new Exception(ErrorMessageHelper::getMissingApiKeyMessage());
        }

        $modelCode = (string)($config['model'] ?? $model->getModelCode());
        $requestData = [
            'contents' => $this->buildContents($prompt, $params),
            'generationConfig' => $this->buildGenerationConfig($config, $params, false),
        ];

        $response = $this->requestJson(
            $this->resolveGenerateContentUrl($config, $modelCode, $apiKey),
            $requestData,
            $this->resolveProxyInfo($model, $config),
            (int)($params['timeout'] ?? $config['timeout'] ?? 180),
            $apiKey
        );

        $text = $this->extractText($response);

        return [
            'content' => $text,
            'usage' => $this->normalizeUsage($response['usageMetadata'] ?? []),
            'model' => $modelCode,
            'finish_reason' => (string)($response['candidates'][0]['finishReason'] ?? ''),
            'raw' => $response,
        ];
    }

    public function generateStream(AiModel $model, string $prompt, callable $callback, array $params = []): array
    {
        $result = $this->generate($model, $prompt, $params);
        if (($result['content'] ?? '') !== '') {
            $callback($result['content']);
        }

        return $result;
    }

    public function generateImage(AiModel $model, string $prompt, array $params = []): array
    {
        $config = $this->resolveConfig($model, $params);
        $apiKey = $this->getApiKey($config);
        if ($apiKey === '') {
            throw new Exception(__('API key is required for image generation.'));
        }

        $modelCode = (string)($config['model'] ?? $model->getModelCode());
        $requestData = [
            'contents' => $this->buildContents($prompt, $params),
            'generationConfig' => $this->buildGenerationConfig($config, $params, true),
        ];

        $response = $this->requestJson(
            $this->resolveGenerateContentUrl($config, $modelCode, $apiKey),
            $requestData,
            $this->resolveProxyInfo($model, $config),
            (int)($params['timeout'] ?? $config['timeout'] ?? 180),
            $apiKey
        );

        $images = [];
        foreach ($this->extractParts($response) as $part) {
            $inlineData = $part['inlineData'] ?? $part['inline_data'] ?? null;
            if (!is_array($inlineData)) {
                continue;
            }
            $data = trim((string)($inlineData['data'] ?? ''));
            if ($data === '') {
                continue;
            }
            $images[] = [
                'b64_json' => $data,
                'mime_type' => (string)($inlineData['mimeType'] ?? $inlineData['mime_type'] ?? 'image/png'),
                'revised_prompt' => $this->extractText($response),
            ];
        }

        return [
            'images' => $images,
            'usage' => $this->normalizeUsage($response['usageMetadata'] ?? []),
            'model' => $modelCode,
            'finish_reason' => (string)($response['candidates'][0]['finishReason'] ?? 'stop'),
            'raw' => $response,
        ];
    }

    public function supports(string $modelCode): bool
    {
        return str_starts_with($modelCode, 'gemini-') || str_starts_with($modelCode, 'bard-');
    }

    public function getProviderCode(): string
    {
        return 'google';
    }

    public function getSupportedModels(): array
    {
        return VendorConfigManager::getProviderModels($this->getProviderCode());
    }

    protected function buildVisionConnectionTestParams(AiModel $model, array $params): array
    {
        return [
            'prompt' => '',
            'params' => [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [
                        ['text' => 'Describe this image briefly and reply OK if you can read it.'],
                        ['inlineData' => [
                            'mimeType' => 'image/png',
                            'data' => $this->getConnectionTestImageBase64(),
                        ]],
                    ],
                ]],
            ],
        ];
    }

    private function resolveConfig(AiModel $model, array $params): array
    {
        $config = $model->getConfig();
        $providerConfig = $model->getData(AiModel::schema_fields_PROVIDER_CONFIG);
        if (!empty($providerConfig)) {
            $providerData = is_string($providerConfig) ? json_decode($providerConfig, true) : $providerConfig;
            if (is_array($providerData)) {
                foreach ($providerData as $key => $value) {
                    if ($value !== '' && $value !== null) {
                        $config[$key] = $value;
                    }
                }
            }
        }
        if (is_array($params['resolved_config'] ?? null)) {
            $config = array_merge($config, $params['resolved_config']);
        }

        return $config;
    }

    private function getApiKey(array $config): string
    {
        if (!empty($config['api_key_env'])) {
            $envKey = getenv((string)$config['api_key_env']);
            if ($envKey) {
                return (string)$envKey;
            }
        }

        return (string)($config['api_key'] ?? '');
    }

    private function resolveGenerateContentUrl(array $config, string $modelCode, string $apiKey): string
    {
        $baseUrl = trim((string)($config['api_url'] ?? $config['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta'));
        if ($baseUrl === '') {
            $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
        }
        if (preg_match('#^https?://generativelanguage\.googleapis\.com/?$#i', $baseUrl) === 1) {
            $baseUrl = rtrim($baseUrl, '/') . '/v1beta';
        }
        if (str_contains($baseUrl, ':generateContent')) {
            $url = $baseUrl;
        } else {
            $url = rtrim($baseUrl, '/') . '/models/' . rawurlencode($modelCode) . ':generateContent';
        }

        return $url;
    }

    private function buildContents(string $prompt, array $params): array
    {
        if (!empty($params['contents']) && is_array($params['contents'])) {
            return $params['contents'];
        }
        if (!empty($params['messages']) && is_array($params['messages'])) {
            return $this->messagesToContents($params['messages']);
        }

        return [[
            'role' => 'user',
            'parts' => [['text' => $prompt]],
        ]];
    }

    private function messagesToContents(array $messages): array
    {
        $contents = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = (string)($message['role'] ?? 'user');
            $text = (string)($message['content'] ?? '');
            if ($text === '') {
                continue;
            }
            $contents[] = [
                'role' => $role === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $text]],
            ];
        }

        return $contents;
    }

    private function buildGenerationConfig(array $config, array $params, bool $imageMode): array
    {
        $generationConfig = [];
        foreach ([
            'temperature' => isset($params['temperature']) || isset($config['temperature']) ? (float)($params['temperature'] ?? $config['temperature']) : null,
            'topP' => isset($params['top_p']) || isset($config['top_p']) ? (float)($params['top_p'] ?? $config['top_p']) : null,
            'topK' => isset($params['top_k']) || isset($config['top_k']) ? (int)($params['top_k'] ?? $config['top_k']) : null,
            'maxOutputTokens' => !$imageMode ? (int)($params['max_tokens'] ?? $config['max_tokens'] ?? 8192) : null,
        ] as $key => $value) {
            if ($value !== null) {
                $generationConfig[$key] = $value;
            }
        }

        if ($imageMode) {
            $modalities = $params['response_modalities'] ?? $config['response_modalities'] ?? ['TEXT', 'IMAGE'];
            $generationConfig['responseModalities'] = array_values(array_map('strval', is_array($modalities) ? $modalities : [$modalities]));
        }

        return $generationConfig;
    }

    private function resolveProxyInfo(AiModel $model, array $config): array
    {
        $proxyInfo = $model->getProxyInfo();
        if (is_string($proxyInfo) && $proxyInfo !== '') {
            $proxyInfo = json_decode($proxyInfo, true) ?: [];
        }
        if (!is_array($proxyInfo)) {
            $proxyInfo = [];
        }
        if ($proxyInfo === [] && is_array($config['proxy'] ?? null)) {
            $proxyInfo = $config['proxy'];
        }

        return $proxyInfo;
    }

    private function requestJson(string $url, array $data, array $proxyInfo, int $timeout, string $apiKey, int $retryCount = 0): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new Exception('Failed to initialize Gemini request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey,
            ],
            CURLOPT_TIMEOUT => max(0, $timeout),
            CURLOPT_CONNECTTIMEOUT => $timeout > 0 ? min($timeout, 60) : 60,
        ]);

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }

        if (!empty($proxyInfo['enabled']) && !empty($proxyInfo['host'])) {
            $proxy = (string)$proxyInfo['host'];
            if (!empty($proxyInfo['port'])) {
                $proxy .= ':' . (string)$proxyInfo['port'];
            }
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            if (!empty($proxyInfo['username'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, (string)$proxyInfo['username'] . ':' . (string)($proxyInfo['password'] ?? ''));
            }
        }

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            throw new Exception('Gemini API request failed: ' . ($curlError !== '' ? $curlError : 'empty response'));
        }

        $result = json_decode((string)$response, true);
        if (!is_array($result)) {
            throw new Exception('Gemini API returned invalid JSON.');
        }

        if ($httpCode >= 500 && $retryCount < self::MAX_RETRIES) {
            sleep(self::RETRY_DELAY * ($retryCount + 1));
            return $this->requestJson($url, $data, $proxyInfo, $timeout, $apiKey, $retryCount + 1);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = (string)($result['error']['message'] ?? ('HTTP ' . $httpCode));
            throw new Exception('Gemini API returned error: ' . $message);
        }

        return $result;
    }

    private function extractText(array $response): string
    {
        $texts = [];
        foreach ($this->extractParts($response) as $part) {
            if (isset($part['text']) && is_scalar($part['text'])) {
                $text = trim((string)$part['text']);
                if ($text !== '') {
                    $texts[] = $text;
                }
            }
        }

        return implode("\n", $texts);
    }

    private function extractParts(array $response): array
    {
        $parts = $response['candidates'][0]['content']['parts'] ?? [];
        return is_array($parts) ? $parts : [];
    }

    private function normalizeUsage(array $usage): array
    {
        $promptTokens = (int)($usage['promptTokenCount'] ?? 0);
        $completionTokens = (int)($usage['candidatesTokenCount'] ?? 0);
        $totalTokens = (int)($usage['totalTokenCount'] ?? ($promptTokens + $completionTokens));

        return [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
        ];
    }
}
