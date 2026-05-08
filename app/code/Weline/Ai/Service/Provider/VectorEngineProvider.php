<?php
declare(strict_types=1);

namespace Weline\Ai\Service\Provider;

use Weline\Ai\Helper\ErrorMessageHelper;
use Weline\Ai\Model\AiModel;
use Weline\Framework\App\Exception;

/**
 * VectorEngine provider.
 *
 * VectorEngine is treated as an OpenAI-compatible multi-modal supplier:
 * - GET /models for remote model discovery
 * - POST /chat/completions for text models
 * - POST /embeddings for connection tests and embedding generation
 * - POST /images/generations for OpenAI-compatible text-to-image models
 */
class VectorEngineProvider extends OpenAiProvider
{
    use ModelListingProviderTrait;

    public function generate(AiModel $model, string $prompt, array $params = []): array
    {
        $modelCode = (string)$model->getModelCode();
        if (
            !$this->isVisionModel($model)
            && (
                $model->supportsPrimaryModality(AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE)
                || $this->isImageModelCode($modelCode)
            )
        ) {
            $imageResult = $this->generateImage($model, $prompt, $params);
            return [
                'content' => 'image generated: ' . count($imageResult['images'] ?? []),
                'images' => is_array($imageResult['images'] ?? null) ? $imageResult['images'] : [],
                'usage' => is_array($imageResult['usage'] ?? null) ? $imageResult['usage'] : [],
                'model' => (string)($imageResult['model'] ?? $modelCode),
                'finish_reason' => (string)($imageResult['finish_reason'] ?? 'stop'),
                'raw' => $imageResult['raw'] ?? $imageResult,
            ];
        }
        if (!$model->supportsPrimaryModality(AiModel::PRIMARY_MODALITY_EMBEDDING) && !$this->isEmbeddingModelCode($modelCode)) {
            return $this->generateText($model, $prompt, $params);
        }

        $config = $this->resolveVectorConfig($model, $params);
        $apiKey = $this->resolveVectorApiKey($config);
        if ($apiKey === '') {
            throw new Exception(ErrorMessageHelper::getMissingApiKeyMessage());
        }

        $modelCode = (string)($config['model'] ?? $modelCode);
        $payload = [
            'model' => $modelCode,
            'input' => $params['input'] ?? $prompt,
            'encoding_format' => $params['encoding_format'] ?? $config['encoding_format'] ?? 'float',
        ];
        if (isset($params['dimensions']) || isset($config['dimensions'])) {
            $payload['dimensions'] = (int)($params['dimensions'] ?? $config['dimensions']);
        }

        $requestUrl = $this->resolveEmbeddingsUrl($config);
        $response = $this->postJson(
            $requestUrl,
            $apiKey,
            $payload,
            (int)($params['timeout'] ?? $config['timeout'] ?? 60)
        );

        $first = $response['data'][0]['embedding'] ?? [];
        $dimension = is_array($first) ? count($first) : 0;

        return [
            'content' => 'embedding generated' . ($dimension > 0 ? (': ' . $dimension . ' dimensions') : ''),
            'usage' => [
                'prompt_tokens' => (int)($response['usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => 0,
                'total_tokens' => (int)($response['usage']['total_tokens'] ?? 0),
            ],
            'model' => (string)($response['model'] ?? $modelCode),
            'finish_reason' => 'stop',
            'embedding_dimension' => $dimension,
            'request_url' => $requestUrl,
            'raw' => $response,
        ];
    }

    public function generateStream(AiModel $model, string $prompt, callable $callback, array $params = []): array
    {
        $result = $this->generate($model, $prompt, $params);
        $callback((string)($result['content'] ?? ''));
        return $result;
    }

    public function testConnection(AiModel $model, array $params = []): array
    {
        $started = microtime(true);
        $modelCode = (string)$model->getModelCode();
        if ($model->supportsPrimaryModality(AiModel::PRIMARY_MODALITY_EMBEDDING) || $this->isEmbeddingModelCode($modelCode)) {
            $result = $this->generate($model, 'VectorEngine embedding connection test', array_replace([
                'input' => 'VectorEngine embedding connection test',
                'test_mode' => true,
                'timeout' => 12,
            ], $params));

            return [
                'success' => true,
                'content' => (string)($result['content'] ?? 'embedding generated'),
                'response' => (string)($result['content'] ?? 'embedding generated'),
                'images' => is_array($result['images'] ?? null) ? $result['images'] : [],
                'duration' => round((microtime(true) - $started) * 1000, 2),
                'model' => (string)($result['model'] ?? $modelCode),
                'request_url' => (string)($result['request_url'] ?? ''),
                'raw' => $result['raw'] ?? $result,
            ];
        }

        if ($this->isVisionModel($model)) {
            $result = $this->generateText($model, '', array_replace([
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Describe this image briefly and reply OK if you can read it.'],
                        ['type' => 'image_url', 'image_url' => ['url' => $this->getVisionTestImageDataUrl()]],
                    ],
                ]],
                'temperature' => 0,
                'max_tokens' => 64,
                'test_mode' => true,
                'timeout' => 20,
            ], $params));

            $content = trim((string)($result['content'] ?? ''));
            if ($content === '') {
                throw new Exception(__('VectorEngine 鍥炬枃妯″瀷娴嬭瘯鍝嶅簲涓虹┖'));
            }

            return [
                'success' => true,
                'content' => $content,
                'response' => $content,
                'images' => is_array($result['images'] ?? null) ? $result['images'] : [],
                'duration' => round((microtime(true) - $started) * 1000, 2),
                'model' => (string)($result['model'] ?? $modelCode),
                'request_url' => (string)($result['request_url'] ?? ''),
                'raw' => $result['raw'] ?? $result,
            ];
        }

        if ($model->supportsPrimaryModality(AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE) || $this->isImageModelCode($modelCode)) {
            $result = $this->generateImage($model, 'Create a simple 1:1 test image with the word OK on a clean background.', array_replace([
                'test_mode' => true,
                'timeout' => 30,
                'response_modalities' => ['TEXT', 'IMAGE'],
            ], $params));
            $images = is_array($result['images'] ?? null) ? $result['images'] : [];
            if ($images === []) {
                throw new Exception('VectorEngine image test returned no image.');
            }

            return [
                'success' => true,
                'content' => 'image generated: ' . $modelCode,
                'response' => 'image generated: ' . $modelCode,
                'images' => $images,
                'duration' => round((microtime(true) - $started) * 1000, 2),
                'model' => (string)($result['model'] ?? $modelCode),
                'request_url' => (string)($result['request_url'] ?? ''),
                'raw' => $result['raw'] ?? $result,
            ];
        }

        return parent::testConnection($model, $params);
    }

    public function generateImage(AiModel $model, string $prompt, array $params = []): array
    {
        $config = $this->resolveVectorConfig($model, $params);
        $apiKey = $this->resolveVectorApiKey($config);
        if ($apiKey === '') {
            throw new Exception(ErrorMessageHelper::getMissingApiKeyMessage());
        }

        $modelCode = (string)($config['model'] ?? $model->getModelCode());
        if ($this->isGeminiImageModelCode($modelCode)) {
            return $this->generateGeminiImage($config, $modelCode, $prompt, $params, $apiKey);
        }

        $payload = [
            'model' => $modelCode,
            'prompt' => $prompt,
            'n' => max(1, (int)($params['n'] ?? $params['count'] ?? 1)),
            'size' => (string)($params['size'] ?? $config['image_size'] ?? $config['size'] ?? '1024x1024'),
        ];
        $responseFormat = $params['response_format'] ?? $config['response_format'] ?? null;
        if (is_array($responseFormat)) {
            $payload['response_format'] = $responseFormat;
        } elseif (is_scalar($responseFormat) && trim((string)$responseFormat) !== '') {
            $payload['response_format'] = ['type' => trim((string)$responseFormat)];
        }

        foreach ([
            'watermark' => $params['watermark'] ?? $config['watermark'] ?? null,
            'prompt_extend' => $params['prompt_extend'] ?? $config['prompt_extend'] ?? null,
            'negative_prompt' => $params['negative_prompt'] ?? $config['negative_prompt'] ?? null,
            'image' => $params['image'] ?? $config['image'] ?? null,
            'sequential_image_generation' => $params['sequential_image_generation'] ?? $config['sequential_image_generation'] ?? null,
            'stream' => $params['stream'] ?? $config['stream'] ?? null,
        ] as $key => $value) {
            if ($value !== null && $value !== '') {
                $payload[$key] = $value;
            }
        }

        $requestUrl = $this->resolveImagesUrl($config);
        $response = $this->postJson(
            $requestUrl,
            $apiKey,
            $payload,
            (int)($params['timeout'] ?? $config['timeout'] ?? 120)
        );

        return $this->normalizeVectorImageResponse($response, $modelCode, $payload, $requestUrl);
    }

    /**
     * VectorEngine proxies Gemini image models through the Google generateContent shape.
     *
     * @param array<string,mixed> $config
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function generateGeminiImage(array $config, string $modelCode, string $prompt, array $params, string $apiKey): array
    {
        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
        ];

        $image = $params['image'] ?? $config['image'] ?? null;
        if (is_string($image) && trim($image) !== '') {
            $payload['contents'][0]['parts'][] = $this->buildGeminiInlineImagePart($image);
        }

        $responseMimeType = (string)($params['response_mime_type'] ?? $config['response_mime_type'] ?? '');
        if ($responseMimeType !== '') {
            $payload['generationConfig']['responseMimeType'] = $responseMimeType;
        }

        $requestUrl = $this->resolveGeminiGenerateContentUrl($config, $modelCode);
        $response = $this->postJson(
            $requestUrl,
            $apiKey,
            $payload,
            (int)($params['timeout'] ?? $config['timeout'] ?? 120)
        );

        return $this->normalizeGeminiImageResponse($response, $modelCode, $payload, $requestUrl);
    }

    private function generateText(AiModel $model, string $prompt, array $params = []): array
    {
        $config = $this->resolveVectorConfig($model, $params);
        $apiKey = $this->resolveVectorApiKey($config);
        if ($apiKey === '') {
            throw new Exception(ErrorMessageHelper::getMissingApiKeyMessage());
        }

        $modelCode = (string)($config['model'] ?? $model->getModelCode());
        $payload = [
            'model' => $modelCode,
            'messages' => $this->buildVectorMessages($prompt, $params),
            'temperature' => (float)($params['temperature'] ?? $config['temperature'] ?? 0),
            'max_tokens' => max(32, (int)($params['max_tokens'] ?? $config['max_tokens'] ?? 128)),
            'stream' => false,
        ];
        foreach ([
            'top_p' => $params['top_p'] ?? $config['top_p'] ?? null,
            'frequency_penalty' => $params['frequency_penalty'] ?? $config['frequency_penalty'] ?? null,
            'presence_penalty' => $params['presence_penalty'] ?? $config['presence_penalty'] ?? null,
        ] as $key => $value) {
            if ($value !== null && $value !== '') {
                $payload[$key] = $value;
            }
        }

        $requestUrl = $this->resolveChatUrl($config);
        $response = $this->postJson(
            $requestUrl,
            $apiKey,
            $payload,
            (int)($params['timeout'] ?? $config['timeout'] ?? 60)
        );

        $message = is_array($response['choices'][0]['message'] ?? null) ? $response['choices'][0]['message'] : [];
        $content = (string)($message['content'] ?? '');
        if ($content === '' && !empty($params['test_mode']) && is_array($response['choices'][0] ?? null)) {
            $content = 'VectorEngine text response received';
        }

        return [
            'content' => $content,
            'usage' => is_array($response['usage'] ?? null) ? $response['usage'] : [],
            'model' => (string)($response['model'] ?? $modelCode),
            'finish_reason' => (string)($response['choices'][0]['finish_reason'] ?? 'stop'),
            'request_url' => $requestUrl,
            'raw' => $response,
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function buildVectorMessages(string $prompt, array $params): array
    {
        if (is_array($params['messages'] ?? null) && $params['messages'] !== []) {
            return $params['messages'];
        }
        return [
            ['role' => 'user', 'content' => $prompt],
        ];
    }

    public function supports(string $modelCode): bool
    {
        $modelCode = strtolower(trim($modelCode));
        return str_starts_with($modelCode, 've-')
            || str_contains($modelCode, 'embedding')
            || str_contains($modelCode, 'gpt-')
            || str_contains($modelCode, 'claude')
            || str_contains($modelCode, 'gemini')
            || str_contains($modelCode, 'deepseek')
            || str_contains($modelCode, 'qwen')
            || str_contains($modelCode, 'doubao')
            || $this->isImageModelCode($modelCode)
            || str_contains($modelCode, 'bge')
            || str_contains($modelCode, 'gte')
            || str_contains($modelCode, 'e5');
    }

    public function getProviderCode(): string
    {
        return 'vectorengine';
    }

    private function resolveVectorConfig(AiModel $model, array $params): array
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

    private function resolveVectorApiKey(array $config): string
    {
        if (!empty($config['api_key_env'])) {
            $envKey = getenv((string)$config['api_key_env']);
            if ($envKey) {
                return (string)$envKey;
            }
        }

        return trim((string)($config['api_key'] ?? ''));
    }

    private function resolveEmbeddingsUrl(array $config): string
    {
        $baseUrl = trim((string)($config['embeddings_api_url'] ?? $config['api_url'] ?? $config['base_url'] ?? ''));
        if ($baseUrl === '') {
            throw new Exception('VectorEngine API base URL is required.');
        }
        $baseUrl = $this->normalizeVectorOpenAiBaseUrl($baseUrl);
        foreach (['/chat/completions', '/completions', '/embeddings', '/images/generations'] as $suffix) {
            if (str_ends_with($baseUrl, $suffix)) {
                $baseUrl = substr($baseUrl, 0, -strlen($suffix));
                break;
            }
        }
        if (!str_ends_with($baseUrl, '/embeddings')) {
            $baseUrl = rtrim($baseUrl, '/') . '/embeddings';
        }

        return $baseUrl;
    }

    private function resolveChatUrl(array $config): string
    {
        $baseUrl = trim((string)($config['chat_api_url'] ?? $config['api_url'] ?? $config['base_url'] ?? ''));
        if ($baseUrl === '') {
            throw new Exception('VectorEngine API base URL is required.');
        }
        $baseUrl = $this->normalizeVectorOpenAiBaseUrl($baseUrl);
        if (!str_ends_with($baseUrl, '/chat/completions')) {
            $baseUrl = rtrim($baseUrl, '/') . '/chat/completions';
        }
        return $baseUrl;
    }

    private function resolveImagesUrl(array $config): string
    {
        $baseUrl = trim((string)($config['image_api_url'] ?? $config['api_url'] ?? $config['base_url'] ?? ''));
        if ($baseUrl === '') {
            throw new Exception('VectorEngine API base URL is required.');
        }
        $baseUrl = $this->normalizeVectorOpenAiBaseUrl($baseUrl);
        if (!str_ends_with($baseUrl, '/images/generations')) {
            $baseUrl = rtrim($baseUrl, '/') . '/images/generations';
        }
        return $baseUrl;
    }

    private function isImageModelCode(string $modelCode): bool
    {
        $modelCode = strtolower($modelCode);
        foreach ([
            'text2image',
            'text2img',
            'txt2img',
            'text-to-image',
            '-image',
            'image-',
            'image-generation',
            'z-image',
            'gpt-image',
            'dall-e',
            'imagen',
            'flux',
            'stable-diffusion',
            'sdxl',
            'seedream',
            'jimeng',
            'midjourney',
            'nano-banana',
        ] as $needle) {
            if (str_contains($modelCode, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function isGeminiImageModelCode(string $modelCode): bool
    {
        $modelCode = strtolower(trim($modelCode));
        return str_starts_with($modelCode, 'gemini') || str_starts_with($modelCode, 'gemeni');
    }

    /**
     * @return array{inline_data:array{mime_type:string,data:string}}
     */
    private function buildGeminiInlineImagePart(string $image): array
    {
        $mimeType = 'image/png';
        $data = trim($image);
        if (preg_match('#^data:([^;]+);base64,(.+)$#', $data, $matches)) {
            $mimeType = (string)$matches[1];
            $data = (string)$matches[2];
        }

        return [
            'inline_data' => [
                'mime_type' => $mimeType,
                'data' => $data,
            ],
        ];
    }

    private function isVisionModel(AiModel $model): bool
    {
        if ($model->hasCapability(AiModel::CAPABILITY_VISION)) {
            return true;
        }

        $modelCode = strtolower((string)$model->getModelCode());
        foreach ([
            'vision',
            '-vl',
            '_vl',
            '/vl',
            'vl-',
            'qwen-vl',
            'qwen2-vl',
            'qwen2.5-vl',
            'glm-4v',
            'gpt-4o',
            'gpt-4.1',
            'claude-3',
            'omni',
        ] as $needle) {
            if (str_contains($modelCode, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function getVisionTestImageDataUrl(): string
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=';
    }

    private function isEmbeddingModelCode(string $modelCode): bool
    {
        $modelCode = strtolower($modelCode);
        foreach (['embedding', 'embed', 'bge-', 'gte-', 'e5-', 'rerank', 'vector'] as $needle) {
            if (str_contains($modelCode, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function normalizeVectorOpenAiBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $baseUrl = str_replace('://api.vectorengine.ai', '://api.vectorengine.cn', $baseUrl);
        foreach (['/chat/completions', '/completions', '/embeddings', '/images/generations', '/models'] as $suffix) {
            if (str_ends_with($baseUrl, $suffix)) {
                $baseUrl = substr($baseUrl, 0, -strlen($suffix));
                break;
            }
        }
        if ($baseUrl !== '' && !preg_match('#/v\d+(?:beta)?$#', $baseUrl)) {
            $baseUrl .= '/v1';
        }
        return $baseUrl;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function resolveGeminiGenerateContentUrl(array $config, string $modelCode): string
    {
        $baseUrl = trim((string)($config['gemini_api_url'] ?? $config['image_api_url'] ?? $config['api_url'] ?? $config['base_url'] ?? ''));
        if ($baseUrl === '') {
            throw new Exception('VectorEngine API base URL is required.');
        }
        $baseUrl = rtrim(str_replace('://api.vectorengine.ai', '://api.vectorengine.cn', $baseUrl), '/');
        foreach (['/chat/completions', '/completions', '/embeddings', '/images/generations', '/models'] as $suffix) {
            if (str_ends_with($baseUrl, $suffix)) {
                $baseUrl = substr($baseUrl, 0, -strlen($suffix));
                break;
            }
        }
        if (preg_match('#/v\d+(?:beta)?$#', $baseUrl)) {
            $baseUrl = preg_replace('#/v\d+(?:beta)?$#', '/v1beta', $baseUrl) ?: $baseUrl;
        } else {
            $baseUrl .= '/v1beta';
        }

        return rtrim($baseUrl, '/') . '/models/' . rawurlencode($modelCode) . ':generateContent';
    }

    /**
     * @param array<string,mixed> $response
     * @param array<string,mixed> $requestData
     * @return array<string,mixed>
     */
    private function normalizeVectorImageResponse(array $response, string $modelCode, array $requestData, string $requestUrl): array
    {
        $images = [];
        foreach (is_array($response['data'] ?? null) ? $response['data'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $image = [];
            foreach (['url', 'b64_json', 'revised_prompt'] as $key) {
                if (isset($item[$key]) && is_scalar($item[$key]) && trim((string)$item[$key]) !== '') {
                    $image[$key] = (string)$item[$key];
                }
            }
            if ($image !== []) {
                $images[] = $image;
            }
        }

        return [
            'images' => $images,
            'usage' => is_array($response['usage'] ?? null) ? $response['usage'] : [],
            'model' => (string)($response['model'] ?? $modelCode),
            'finish_reason' => 'stop',
            'request_url' => $requestUrl,
            'request' => $requestData,
            'raw' => $response,
        ];
    }

    /**
     * @param array<string,mixed> $response
     * @param array<string,mixed> $requestData
     * @return array<string,mixed>
     */
    private function normalizeGeminiImageResponse(array $response, string $modelCode, array $requestData, string $requestUrl): array
    {
        $images = [];
        $textParts = [];
        foreach (is_array($response['candidates'] ?? null) ? $response['candidates'] : [] as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $content = is_array($candidate['content'] ?? null) ? $candidate['content'] : [];
            foreach (is_array($content['parts'] ?? null) ? $content['parts'] : [] as $part) {
                if (!is_array($part)) {
                    continue;
                }
                $inlineData = is_array($part['inlineData'] ?? null)
                    ? $part['inlineData']
                    : (is_array($part['inline_data'] ?? null) ? $part['inline_data'] : []);
                if ($inlineData !== []) {
                    $data = (string)($inlineData['data'] ?? '');
                    if ($data !== '') {
                        $images[] = [
                            'b64_json' => $data,
                            'mime_type' => (string)($inlineData['mimeType'] ?? $inlineData['mime_type'] ?? 'image/png'),
                        ];
                    }
                }
                if (isset($part['text']) && is_scalar($part['text']) && trim((string)$part['text']) !== '') {
                    $textParts[] = trim((string)$part['text']);
                }
            }
        }

        return [
            'images' => $images,
            'content' => implode("\n", $textParts),
            'usage' => is_array($response['usageMetadata'] ?? null) ? $response['usageMetadata'] : [],
            'model' => $modelCode,
            'finish_reason' => 'stop',
            'request_url' => $requestUrl,
            'request' => $requestData,
            'raw' => $response,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function postJson(string $url, string $apiKey, array $payload, int $timeout): array
    {
        if (function_exists('curl_init')) {
            return $this->postJsonWithCurl($url, $apiKey, $payload, $timeout);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $apiKey,
                    'User-Agent: Weline-Ai-VectorEngine/1.0',
                ]) . "\r\n",
                'content' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $statusCode = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string)$header, $matches)) {
                $statusCode = (int)$matches[1];
                break;
            }
        }
        if ($body === false) {
            throw new Exception('VectorEngine API failed (URL: ' . $url . '): ' . (string)(error_get_last()['message'] ?? 'empty response'));
        }

        $decoded = json_decode((string)$body, true);
        if ($statusCode < 200 || $statusCode >= 300) {
            $message = is_array($decoded)
                ? (string)($decoded['error']['message'] ?? $decoded['message'] ?? ('HTTP ' . $statusCode))
                : ('HTTP ' . $statusCode . ' ' . substr((string)$body, 0, 300));
            throw new Exception('VectorEngine API returned error (URL: ' . $url . '): ' . $message);
        }
        if (!is_array($decoded)) {
            throw new Exception('VectorEngine API returned non-JSON response');
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function postJsonWithCurl(string $url, string $apiKey, array $payload, int $timeout): array
    {
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            throw new Exception('VectorEngine API request payload JSON encode failed');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new Exception('VectorEngine API failed (URL: ' . $url . '): curl init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $apiKey,
                'User-Agent: Weline-Ai-VectorEngine/1.0',
            ],
            CURLOPT_TIMEOUT => max(1, $timeout),
            CURLOPT_CONNECTTIMEOUT => min(10, max(1, $timeout)),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $body = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new Exception('VectorEngine API failed (URL: ' . $url . ', HTTP: ' . $statusCode . '): ' . ($curlError !== '' ? $curlError : 'empty response'));
        }

        $decoded = json_decode((string)$body, true);
        if ($statusCode < 200 || $statusCode >= 300) {
            $message = is_array($decoded)
                ? (string)($decoded['error']['message'] ?? $decoded['message'] ?? ('HTTP ' . $statusCode))
                : ('HTTP ' . $statusCode . ' ' . substr((string)$body, 0, 300));
            throw new Exception('VectorEngine API returned error (URL: ' . $url . ', HTTP: ' . $statusCode . '): ' . $message);
        }
        if (!is_array($decoded)) {
            $preview = trim(substr((string)$body, 0, 300));
            throw new Exception('VectorEngine API returned non-JSON response' . ($preview !== '' ? (': ' . $preview) : ''));
        }

        return $decoded;
    }
}
