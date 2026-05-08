<?php
declare(strict_types=1);

namespace Weline\Ai\Service\Provider;

use Weline\Ai\Model\AiModel;
use Weline\Framework\App\Exception;

trait ProviderConnectionTestTrait
{
    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function testConnection(AiModel $model, array $params = []): array
    {
        $started = microtime(true);
        $modelCode = (string)$model->getModelCode();

        if ($model->supportsPrimaryModality(AiModel::PRIMARY_MODALITY_EMBEDDING)) {
            if (!method_exists($this, 'testEmbeddingConnection')) {
                throw new Exception(__('该供应商尚未实现向量模型连接测试'));
            }
            return $this->testEmbeddingConnection($model, $params);
        }

        if ($model->supportsPrimaryModality(AiModel::PRIMARY_MODALITY_TEXT_TO_VIDEO)) {
            if (!method_exists($this, 'testVideoConnection')) {
                throw new Exception(__('该供应商尚未实现文生视频模型连接测试'));
            }
            return $this->testVideoConnection($model, $params);
        }

        if ($model->supportsPrimaryModality(AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE)) {
            if (!method_exists($this, 'generateImage')) {
                throw new Exception(__('该供应商尚未实现文生图模型连接测试'));
            }
            $result = $this->generateImage($model, 'Create a simple 1:1 test image with the word OK on a clean background.', array_replace([
                'test_mode' => true,
                'timeout' => 30,
                'response_modalities' => ['TEXT', 'IMAGE'],
            ], $params));

            $images = is_array($result['images'] ?? null) ? $result['images'] : [];
            if ($images === []) {
                throw new Exception(__('文生图模型测试未返回图片'));
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

        if ($this->isVisionConnectionTestModel($model)) {
            if (!method_exists($this, 'buildVisionConnectionTestParams')) {
                throw new Exception(__('该供应商尚未实现图文模型连接测试'));
            }
            $visionTest = $this->buildVisionConnectionTestParams($model, $params);
            $result = $this->generate(
                $model,
                (string)($visionTest['prompt'] ?? ''),
                array_replace(is_array($visionTest['params'] ?? null) ? $visionTest['params'] : [], [
                    'temperature' => 0,
                    'test_mode' => true,
                    'timeout' => 20,
                ], $params)
            );

            $content = trim((string)($result['content'] ?? ''));
            if ($content === '') {
                throw new Exception(__('图文模型测试响应为空'));
            }

            return [
                'success' => true,
                'content' => $content,
                'response' => $content,
                'duration' => round((microtime(true) - $started) * 1000, 2),
                'model' => (string)($result['model'] ?? $modelCode),
                'request_url' => (string)($result['request_url'] ?? ''),
                'raw' => $result['raw'] ?? $result,
            ];
        }

        $result = $this->generate($model, 'Hello, this is a connection test. Please respond with "OK".', array_replace([
            'temperature' => 0,
            'test_mode' => true,
            'timeout' => 12,
        ], $params));

        $content = trim((string)($result['content'] ?? ''));
        if ($content === '') {
            throw new Exception(__('API响应为空'));
        }

        return [
            'success' => true,
            'content' => $content,
            'response' => $content,
            'duration' => round((microtime(true) - $started) * 1000, 2),
            'model' => (string)($result['model'] ?? $modelCode),
            'request_url' => (string)($result['request_url'] ?? ''),
            'raw' => $result['raw'] ?? $result,
        ];
    }

    protected function isVisionConnectionTestModel(AiModel $model): bool
    {
        if ($model->hasCapability(AiModel::CAPABILITY_VISION)) {
            return true;
        }

        $modelCode = strtolower((string)$model->getModelCode());
        foreach (['vision', 'multimodal', 'multi-modal', 'image-to-text', 'image2text', '-vl', '_vl', '/vl', 'qwen-vl', 'qwen2-vl', 'qwen2.5-vl', 'glm-4v', 'gpt-4o', 'gpt-4.1', 'claude-3', 'omni'] as $needle) {
            if (str_contains($modelCode, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{prompt:string,params:array<string,mixed>}
     */
    protected function buildVisionConnectionTestParams(AiModel $model, array $params): array
    {
        return [
            'prompt' => '',
            'params' => [
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Describe this image briefly and reply OK if you can read it.'],
                        ['type' => 'image_url', 'image_url' => ['url' => $this->getConnectionTestImageDataUrl()]],
                    ],
                ]],
            ],
        ];
    }

    protected function getConnectionTestImageDataUrl(): string
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=';
    }

    protected function getConnectionTestImageBase64(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=';
    }
}
