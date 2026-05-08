<?php
declare(strict_types=1);

namespace Weline\Ai\Service\Provider;

use Weline\Ai\Model\AiModel;
use Weline\Framework\App\Exception;

trait ModelListingProviderTrait
{
    public function supportsModelsApi(): bool
    {
        return true;
    }

    public function listRemoteModels(array $config, array $options = []): array
    {
        $modelsApi = is_array($options['models_api'] ?? null) ? $options['models_api'] : ($config['models_api'] ?? []);
        if (!is_array($modelsApi) || empty($modelsApi['path'])) {
            throw new Exception(__('该供应商未配置 models 接口，请手动输入模型代码。'));
        }

        $apiKey = $this->resolveModelsApiKey($config);
        if ($apiKey === '') {
            throw new Exception(__('请选择供应商账户，或在自配置模式填写 API Key 后再拉取模型。'));
        }

        $providerCode = (string)($options['provider_code'] ?? $config['provider_code'] ?? '');
        $baseUrl = $this->normalizeModelsBaseUrl(
            $providerCode,
            (string)($config['base_url'] ?? $config['api_url'] ?? $options['base_url'] ?? '')
        );
        if ($baseUrl === '') {
            throw new Exception(__('供应商 API 基础 URL 不能为空。'));
        }

        $url = rtrim($baseUrl, '/') . '/' . ltrim((string)$modelsApi['path'], '/');
        $headers = [
            'Accept: application/json',
            'User-Agent: Weline-Ai-Provider-Models/1.0',
        ];

        $authType = (string)($modelsApi['auth_type'] ?? 'bearer');
        if ($authType === 'query_key') {
            $param = (string)($modelsApi['auth_param'] ?? 'key');
            $url .= (str_contains($url, '?') ? '&' : '?') . rawurlencode($param) . '=' . rawurlencode($apiKey);
            if ($providerCode === 'google') {
                $headers[] = 'x-goog-api-key: ' . $apiKey;
            }
        } elseif ($authType === 'anthropic') {
            $headers[] = 'x-api-key: ' . $apiKey;
            $headers[] = 'anthropic-version: ' . (string)($modelsApi['version'] ?? '2023-06-01');
        } elseif ($authType === 'header_key') {
            $headerName = (string)($modelsApi['auth_header'] ?? 'X-Api-Key');
            $headers[] = $headerName . ': ' . $apiKey;
        } else {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        foreach (($modelsApi['headers'] ?? []) as $name => $value) {
            if (is_string($name) && $name !== '' && is_scalar($value)) {
                $headers[] = $name . ': ' . (string)$value;
            }
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => (int)($options['timeout'] ?? $config['timeout'] ?? 20),
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers) . "\r\n",
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
            throw new Exception('models endpoint failed (URL: ' . $url . '): ' . (string)(error_get_last()['message'] ?? 'empty response'));
        }

        $decoded = json_decode((string)$body, true);
        if ($statusCode < 200 || $statusCode >= 300) {
            $message = is_array($decoded)
                ? (string)($decoded['error']['message'] ?? $decoded['message'] ?? ('HTTP ' . $statusCode))
                : ('HTTP ' . $statusCode . ' ' . substr((string)$body, 0, 300));
            throw new Exception('models endpoint returned error (URL: ' . $url . '): ' . $message);
        }
        if (!is_array($decoded)) {
            throw new Exception(__('models 接口返回的不是 JSON'));
        }

        return $this->normalizeRemoteModels($decoded, $modelsApi);
    }

    private function resolveModelsApiKey(array $config): string
    {
        if (!empty($config['api_key_env'])) {
            $envKey = getenv((string)$config['api_key_env']);
            if ($envKey) {
                return (string)$envKey;
            }
        }

        return trim((string)($config['api_key'] ?? ''));
    }

    private function normalizeModelsBaseUrl(string $providerCode, string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($providerCode === 'google' && $baseUrl !== '' && !preg_match('#/v\d+(beta)?$#', $baseUrl)) {
            $baseUrl .= '/v1beta';
        }
        if ($providerCode === 'vectorengine' && $baseUrl !== '') {
            $baseUrl = str_replace('://api.vectorengine.ai', '://api.vectorengine.cn', $baseUrl);
            foreach (['/chat/completions', '/completions', '/embeddings', '/images/generations', '/models'] as $suffix) {
                if (str_ends_with($baseUrl, $suffix)) {
                    $baseUrl = substr($baseUrl, 0, -strlen($suffix));
                    break;
                }
            }
            if (!preg_match('#/v\d+(?:beta)?$#', $baseUrl)) {
                $baseUrl .= '/v1';
            }
        }
        return $baseUrl;
    }

    /**
     * @param array<string,mixed> $decoded
     * @param array<string,mixed> $modelsApi
     * @return array<int,array<string,mixed>>
     */
    private function normalizeRemoteModels(array $decoded, array $modelsApi): array
    {
        $dataKey = (string)($modelsApi['data_key'] ?? 'data');
        $items = $decoded[$dataKey] ?? null;
        if (!is_array($items)) {
            foreach (['data', 'models', 'model_list', 'items', 'result'] as $candidateKey) {
                if (is_array($decoded[$candidateKey] ?? null)) {
                    $items = $decoded[$candidateKey];
                    break;
                }
            }
        }
        if (!is_array($items)) {
            $items = $decoded;
        }
        if ($this->isAssociativeRemoteModelEnvelope($items)) {
            foreach (['models', 'data', 'items', 'list'] as $nestedKey) {
                if (is_array($items[$nestedKey] ?? null)) {
                    $items = $items[$nestedKey];
                    break;
                }
            }
        }
        if (!is_array($items)) {
            return [];
        }

        $idKey = (string)($modelsApi['id_key'] ?? 'id');
        $nameKey = (string)($modelsApi['name_key'] ?? $idKey);
        $descKey = (string)($modelsApi['desc_key'] ?? 'description');
        $contextKey = (string)($modelsApi['context_key'] ?? '');
        $maxTokensKey = (string)($modelsApi['max_tokens_key'] ?? '');
        $trimPrefix = (string)($modelsApi['id_prefix_trim'] ?? '');
        $defaultPrimaryModality = (string)($modelsApi['primary_modality_default'] ?? $modelsApi['primary_modality'] ?? '');

        $models = [];
        foreach ($items as $item) {
            if (is_scalar($item)) {
                $item = [$idKey => (string)$item, $nameKey => (string)$item];
            }
            if (!is_array($item)) {
                continue;
            }
            $id = trim((string)($item[$idKey] ?? $item['model'] ?? $item['code'] ?? $item['name'] ?? $item['value'] ?? ''));
            if ($id === '') {
                continue;
            }
            if ($trimPrefix !== '' && str_starts_with($id, $trimPrefix)) {
                $id = substr($id, strlen($trimPrefix));
            }
            $name = trim((string)($item[$nameKey] ?? $id));
            $description = (string)($item[$descKey] ?? '');
            $models[] = [
                'value' => $id,
                'label' => $name !== $id ? ($name . ' (' . $id . ')') : $id,
                'code' => $id,
                'name' => $name !== '' ? $name : $id,
                'description' => $description,
                'context_window' => $contextKey !== '' ? (int)($item[$contextKey] ?? 0) : 0,
                'max_tokens' => $maxTokensKey !== '' ? (int)($item[$maxTokensKey] ?? 0) : 0,
                'capabilities' => $this->resolveRemoteModelCapabilities($item, $id, $name !== '' ? $name : $id, $description),
                'primary_modality' => $this->resolveRemoteModelPrimaryModality(
                    $item,
                    $id,
                    $name !== '' ? $name : $id,
                    $description,
                    $defaultPrimaryModality
                ),
            ];
        }

        usort($models, static fn(array $a, array $b): int => strcmp((string)$a['code'], (string)$b['code']));
        return $models;
    }

    /**
     * @param array<mixed> $items
     */
    private function isAssociativeRemoteModelEnvelope(array $items): bool
    {
        if ($items === []) {
            return false;
        }
        return array_keys($items) !== range(0, count($items) - 1);
    }

    /**
     * @param array<string,mixed> $item
     */
    private function resolveRemoteModelPrimaryModality(
        array $item,
        string $id,
        string $name,
        string $description,
        string $defaultPrimaryModality
    ): string {
        foreach (['primary_modality', 'modality', 'type', 'category'] as $key) {
            $modality = $this->normalizeRemoteModelModalityValue($item[$key] ?? null);
            if ($modality !== '') {
                return $modality;
            }
        }

        foreach (['capabilities', 'features', 'supported_features'] as $key) {
            $modality = $this->normalizeRemoteModelCapabilityValue($item[$key] ?? null);
            if ($modality !== '') {
                return $modality;
            }
        }

        $searchText = strtolower($id . ' ' . $name . ' ' . $description);
        if ($this->modelTextContainsAny($searchText, [
            'vision',
            'multimodal',
            'multi-modal',
            'image-to-text',
            'image2text',
            'qwen-vl',
            'qwen2-vl',
            'qwen2.5-vl',
            'glm-4v',
            '-vl',
            '_vl',
            '/vl',
        ])) {
            return AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT;
        }
        if ($this->modelTextContainsAny($searchText, [
            'embedding',
            'embeddings',
            'embed',
            'bge-',
            'gte-',
            'e5-',
            'rerank',
            'vector',
        ])) {
            return AiModel::PRIMARY_MODALITY_EMBEDDING;
        }
        if ($this->modelTextContainsAny($searchText, [
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
            'black-forest-labs',
            'kontext',
            'stable-diffusion',
            'sdxl',
            'seedream',
            'jimeng',
            'nano-banana',
        ])) {
            return AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE;
        }
        if ($this->modelTextContainsAny($searchText, [
            'text2video',
            'text-to-video',
            'video-generation',
            'sora',
            'veo',
            'kling',
            'wan-',
        ])) {
            return AiModel::PRIMARY_MODALITY_TEXT_TO_VIDEO;
        }

        return AiModel::normalizePrimaryModality($defaultPrimaryModality);
    }

    /**
     * @param array<string,mixed> $item
     * @return array<int,string>
     */
    private function resolveRemoteModelCapabilities(array $item, string $id, string $name, string $description): array
    {
        $capabilities = [];
        foreach (['capabilities', 'features', 'supported_features'] as $key) {
            $value = $item[$key] ?? null;
            if (is_array($value)) {
                array_walk_recursive($value, static function (mixed $item) use (&$capabilities): void {
                    if (is_scalar($item)) {
                        $capabilities[] = strtolower(trim((string)$item));
                    }
                });
            } elseif (is_scalar($value)) {
                $capabilities[] = strtolower(trim((string)$value));
            }
        }

        $searchText = strtolower($id . ' ' . $name . ' ' . $description . ' ' . implode(' ', $capabilities));
        if ($this->modelTextContainsAny($searchText, [
            'vision',
            'multimodal',
            'multi-modal',
            'image-to-text',
            'image2text',
            'qwen-vl',
            'qwen2-vl',
            'qwen2.5-vl',
            'glm-4v',
            '-vl',
            '_vl',
            '/vl',
        ])) {
            $capabilities[] = AiModel::CAPABILITY_VISION;
        }

        if ($this->modelTextContainsAny($searchText, [
            'image-generation',
            'text-to-image',
            'text2image',
            'flux',
            'seedream',
            'stable-diffusion',
        ])) {
            $capabilities[] = AiModel::CAPABILITY_IMAGE_OUTPUT;
        }

        return array_values(array_unique(array_filter($capabilities, static fn(string $item): bool => $item !== '')));
    }

    private function normalizeRemoteModelCapabilityValue(mixed $value): string
    {
        if (is_array($value)) {
            $flat = [];
            array_walk_recursive($value, static function (mixed $item) use (&$flat): void {
                if (is_scalar($item)) {
                    $flat[] = (string)$item;
                }
            });
            $value = implode(' ', $flat);
        }
        return $this->normalizeRemoteModelModalityValue($value);
    }

    private function normalizeRemoteModelModalityValue(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = strtolower(trim((string)$value));
        if ($value === '') {
            return '';
        }
        if (in_array($value, ['text2image', 'text_to_image', 'text-to-image', 'image', 'image_generation', 'image-generation'], true)) {
            return AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE;
        }
        if (in_array($value, ['text2video', 'text_to_video', 'text-to-video', 'video', 'video_generation', 'video-generation'], true)) {
            return AiModel::PRIMARY_MODALITY_TEXT_TO_VIDEO;
        }
        if (in_array($value, ['embedding', 'embeddings', 'embed', 'vector', 'rerank', 'reranker'], true)) {
            return AiModel::PRIMARY_MODALITY_EMBEDDING;
        }
        if (in_array($value, ['vision', 'multimodal', 'multi-modal', 'image2text', 'image_to_text', 'image-to-text'], true)) {
            return AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT;
        }
        if (in_array($value, ['text2text', 'text_to_text', 'text-to-text', 'chat', 'completion', 'llm', 'language', 'text'], true)) {
            return AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT;
        }

        return '';
    }

    /**
     * @param array<int,string> $needles
     */
    private function modelTextContainsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($text, $needle)) {
                return true;
            }
        }
        return false;
    }
}
