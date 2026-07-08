<?php

declare(strict_types=1);

namespace Weline\MediaManager\Extends\Module\Weline_Ai\Adapter;

use Weline\Ai\Interface\AdapterModelBindingInterface;
use Weline\Ai\Interface\ScenarioAdapterInterface;
use Weline\Ai\Model\AiModel;

class MediaManagerAiDrawAdapter implements ScenarioAdapterInterface, AdapterModelBindingInterface
{
    public function getDefaultModelBindings(): array
    {
        // 运行时由 AiDrawModelBinder / 升级迁移绑定当前环境文生图模型，避免写死单一 model_code。
        return [];
    }

    public function getCode(): string
    {
        return 'media_manager_ai_draw';
    }

    public function getName(): string
    {
        return __('媒体管理 AI 作图适配器');
    }

    public function getDescription(): string
    {
        return __('为后台文件管理器提供文生图、图生图、多轮修图与批量生成的场景约束与模型绑定。');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getSupportedModelTypes(): array
    {
        return [AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE];
    }

    public function adaptPrompt(string $prompt, array $params = []): string
    {
        $normalized = \trim($prompt);
        if ($normalized === '') {
            return $prompt;
        }
        $mode = \strtolower((string)($params['mode'] ?? 'text2image'));
        $contract = $normalized . "\n\nMedia manager AI draw contract:\n";
        $contract .= match ($mode) {
            'image2image' => "1. Preserve the reference image subject structure and composition unless the prompt explicitly asks to replace it.\n"
                . "2. Apply only the requested visual edits; do not add watermarks, UI screenshots, or readable paragraph text.\n",
            'edit_turn' => "1. Treat the reference image as the current approved version and apply incremental edits only.\n"
                . "2. Do not unrelated full redraw; keep identity, layout, and camera unless the prompt asks to change them.\n",
            'batch' => "1. Generate one standalone production-ready raster image for this batch slot.\n"
                . "2. Keep series style coherent when batch_index and batch_total are provided.\n",
            default => "1. Generate one production-ready standalone raster image suitable for website or admin media usage.\n"
                . "2. No watermarks, design-tool UI, dashboards, wireframes, or placeholder gray blocks.\n",
        };
        $batchIndex = (int)($params['batch_index'] ?? 0);
        $batchTotal = (int)($params['batch_total'] ?? 0);
        if ($batchIndex > 0 && $batchTotal > 0) {
            $contract .= "3. This is batch item {$batchIndex}/{$batchTotal}.\n";
        }

        return $contract;
    }

    public function processResponse(string $response, array $params = []): string
    {
        return $response;
    }

    public function validateParams(array $params = []): array
    {
        $errors = [];
        $mode = \strtolower((string)($params['mode'] ?? 'text2image'));
        if (\in_array($mode, ['image2image', 'edit_turn'], true)) {
            $hasRef = \trim((string)($params['reference_image'] ?? '')) !== ''
                || \trim((string)($params['image'] ?? '')) !== ''
                || \trim((string)($params['source_file_hash'] ?? '')) !== ''
                || \trim((string)($params['parent_generation_id'] ?? '')) !== '';
            if (!$hasRef) {
                $errors[] = __('图生图/修图需要有效的参考图');
            }
        }
        $batchCount = (int)($params['batch_count'] ?? 0);
        if ($mode === 'batch' && ($batchCount < 1 || $batchCount > 8)) {
            $errors[] = __('批量数量必须在 1 到 8 之间');
        }

        return $errors;
    }

    public function getParamTemplate(): array
    {
        return [
            'description' => 'Media manager AI draw parameters',
            'fields' => [
                'mode' => ['type' => 'select', 'options' => ['text2image', 'image2image', 'edit_turn', 'batch']],
                'size' => ['type' => 'string'],
                'aspect_ratio' => ['type' => 'string'],
                'output_format' => ['type' => 'string'],
                'negative_prompt' => ['type' => 'string'],
                'source_file_hash' => ['type' => 'string'],
                'batch_count' => ['type' => 'integer'],
            ],
        ];
    }

    public function getExamples(): array
    {
        return [
            [
                'title' => __('生成 Banner'),
                'description' => __('在媒体目录中生成一张网站 Banner 背景图。'),
                'input' => __('为电商首页生成一张 16:9 的促销 Banner 背景'),
                'expected_output' => __('返回可保存的 PNG/WebP 图片'),
            ],
        ];
    }

    public function supportsModel(string $modelCode): bool
    {
        return $modelCode !== '';
    }
}
