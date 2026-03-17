<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 内容生成场景适配器
 * 
 * 功能：
 * - 为PageBuilder模块的AI内容生成提供场景适配
 * - 优化提示词格式，确保AI返回JSON格式
 * - 处理响应，提取JSON内容
 * - 组件配置生成时遵循组件 meta 的默认格式与条数（见 AiGenerate::buildComponentConfigPrompt）
 */

namespace GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Adapter;

use Weline\Ai\Interface\ScenarioAdapterInterface;

/**
 * 内容生成场景适配器
 * 
 * 用于PageBuilder模块的AI内容生成功能，包括：
 * - 页面内容生成
 * - 模板配置生成
 * - 组件配置生成：能处理组件 meta 信息，按 meta 的默认格式（如多行竖线|分隔）、类型与默认条数生成；
 *   若用户补充提示词中指定条数或格式则按用户要求。列表类配置生成多行字符串而非 JSON 数组。
 */
class ContentGenerationAdapter implements ScenarioAdapterInterface
{
    /**
     * 获取适配器代码
     * 
     * @return string
     */
    public function getCode(): string
    {
        return 'pagebuilder_content_generation';
    }

    /**
     * 获取适配器名称
     * 
     * @return string
     */
    public function getName(): string
    {
        return '页面构建器内容生成适配器';
    }

    /**
     * 获取适配器描述
     * 
     * @return string
     */
    public function getDescription(): string
    {
        return 'PageBuilder模块专用的AI内容生成场景适配器，支持页面内容生成、模板配置生成和组件配置生成。'
            . '组件配置生成时遵循组件 meta 的默认格式与条数（有格式/类型则按 meta 生成）；用户可在提示词中指定条数或风格。'
            . '自动优化提示词格式，确保AI返回有效的JSON格式数据。';
    }

    /**
     * 获取适配器版本
     * 
     * @return string
     */
    public function getVersion(): string
    {
        return '1.0.0';
    }

    /**
     * 获取支持的模型类型
     * 
     * @return array
     */
    public function getSupportedModelTypes(): array
    {
        return ['*']; // 支持所有模型
    }

    /**
     * 适配提示词
     *
     * - 若传入组件 meta（component_meta_text_configs），则根据 meta 提取格式与条数，追加「要生成什么样的格式」的说明；
     *   存在列表/分隔的项：用户要几个给几个，未指定数目则用 meta 默认个数。
     * - 确保提示词包含 JSON 格式要求
     *
     * @param string $prompt 原始提示词
     * @param array $params 额外参数，可含 component_meta_text_configs（组件文字配置项，含 format/default_count/default_sample/is_list_like）
     * @return string 适配后的提示词
     */
    public function adaptPrompt(string $prompt, array $params = []): string
    {
        $textConfigs = $params['component_meta_text_configs'] ?? null;
        if (is_array($textConfigs) && count($textConfigs) > 0) {
            $metaBlock = $this->buildComponentMetaFormatBlock($textConfigs);
            if ($metaBlock !== '') {
                $prompt .= "\n\n" . $metaBlock;
            }
        }

        // 检查提示词是否已经包含JSON格式要求
        if (stripos($prompt, 'JSON') !== false || stripos($prompt, 'json') !== false) {
            return $prompt;
        }

        $jsonRequirement = "\n\n重要提示：\n";
        $jsonRequirement .= "1. 必须返回有效的JSON格式数据\n";
        $jsonRequirement .= "2. JSON必须是有效的格式，可以直接解析\n";
        $jsonRequirement .= "3. 只返回JSON，不要包含其他说明文字或markdown代码块标记\n";
        $jsonRequirement .= "4. 如果返回markdown代码块，请确保JSON在代码块内\n";

        return $prompt . $jsonRequirement;
    }

    /**
     * 根据组件 meta 的 textConfigs 构建「格式与条数」说明块
     * 告知要生成什么样的格式；存在列表/分隔的项：用户指定几个就生成几个，未指定则用 meta 默认个数
     *
     * @param array<int, array{key: string, label: string, format?: string, default_count?: int, default_sample?: string, is_list_like?: bool}> $textConfigs
     * @return string
     */
    private function buildComponentMetaFormatBlock(array $textConfigs): string
    {
        $listLike = [];
        foreach ($textConfigs as $c) {
            if (empty($c['is_list_like'])) {
                continue;
            }
            $listLike[] = $c;
        }
        if (count($listLike) === 0) {
            return '';
        }

        $lines = [
            '【组件 meta 格式要求】',
            '生成时按 meta 默认格式与条数生成。存在列表/分隔的项时：用户要几个给几个，未指定数目则用 meta 默认个数。',
            '',
        ];
        foreach ($listLike as $c) {
            $key = $c['key'] ?? '';
            $label = $c['label'] ?? $key;
            $format = $c['format'] ?? '';
            $count = $c['default_count'] ?? null;
            $sample = isset($c['default_sample']) && (string) $c['default_sample'] !== '' ? mb_substr((string) $c['default_sample'], 0, 280) : '';
            $parts = [];
            $parts[] = "配置项 {$key}（{$label}）";
            if ($format !== '') {
                $parts[] = "格式：{$format}";
            }
            if ($count !== null && $count > 0) {
                $parts[] = "默认条数：{$count} 条";
            }
            if ($sample !== '') {
                $parts[] = "示例结构：{$sample}";
            }
            $lines[] = '- ' . implode('；', $parts);
        }
        $lines[] = '';
        $lines[] = '上述列表项的值必须生成多行字符串（每行用竖线|分隔各列），不要返回 JSON 数组。';
        return implode("\n", $lines);
    }

    /**
     * 处理响应
     * 
     * 提取JSON内容，移除可能的markdown代码块标记
     * 
     * @param string $response 原始响应
     * @param array $params 额外参数
     * @return string 处理后的响应
     */
    public function processResponse(string $response, array $params = []): string
    {
        // 尝试提取JSON（可能包含markdown代码块）
        $json = $response;

        // 移除markdown代码块标记
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $response, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/(\{.*\})/s', $response, $matches)) {
            $json = $matches[1];
        }

        // 验证JSON格式
        $decoded = json_decode($json, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // JSON有效，返回清理后的JSON字符串
            return $json;
        }

        // 如果解析失败，尝试修复常见的JSON问题
        $json = preg_replace('/,\s*}/', '}', $json); // 移除尾随逗号
        $json = preg_replace('/,\s*]/', ']', $json);
        
        // 再次验证
        $decoded = json_decode($json, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $json;
        }

        // 如果仍然无效，返回原始响应（让调用方处理）
        return $response;
    }

    /**
     * 验证输入参数
     * 
     * @param array $params 参数
     * @return array 验证错误列表，空数组表示验证通过
     */
    public function validateParams(array $params = []): array
    {
        $errors = [];

        // 可以在这里添加参数验证逻辑
        // 例如：检查必需的参数是否存在

        return $errors;
    }

    /**
     * 获取参数模板
     * 
     * @return array
     */
    public function getParamTemplate(): array
    {
        return [
            'description' => '内容生成场景适配器参数',
            'fields' => [
                // 可以定义参数字段
            ],
        ];
    }

    /**
     * 获取使用示例
     * 
     * @return array
     */
    public function getExamples(): array
    {
        return [
            [
                'title' => '页面内容生成',
                'description' => '根据页面描述生成页面标题、SEO信息和内容',
                'input' => '创建一个关于我们页面，介绍公司历史、团队和使命',
                'expected_output' => '{"title": "关于我们", "meta_title": "...", "meta_description": "...", "content": "..."}',
            ],
            [
                'title' => '模板配置生成',
                'description' => '根据页面信息生成模板所需的所有文字配置项',
                'input' => '页面标题：Teen Patti Master，页面类型：page',
                'expected_output' => '{"texts.nav_home": "Home", "texts.nav_about": "About", ...}',
            ],
            [
                'title' => '组件配置生成（遵循 meta）',
                'description' => '根据组件 meta 的默认格式与条数生成配置；用户可指定条数。列表类为多行竖线分隔字符串。',
                'input' => 'meta 示例：优势列表 6 条，格式 标题|图标|描述|颜色；用户补充「生成 4 条」',
                'expected_output' => '{"advantages.items": "标题1|图标1|描述1|#色1\\n标题2|图标2|描述2|#色2\\n...共4行"}',
            ],
        ];
    }

    /**
     * 检查是否支持指定模型
     * 
     * @param string $modelCode 模型代码
     * @return bool
     */
    public function supportsModel(string $modelCode): bool
    {
        // 支持所有模型
        return true;
    }
}
