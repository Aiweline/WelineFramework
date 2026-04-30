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
 */

namespace GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Adapter;

use Weline\Ai\Interface\ScenarioAdapterInterface;

/**
 * 内容生成场景适配器
 * 
 * 用于PageBuilder模块的AI内容生成功能，包括：
 * - 页面内容生成
 * - 模板配置生成
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
        return 'PageBuilder模块专用的AI内容生成场景适配器，支持页面内容生成和模板配置生成。自动优化提示词格式，确保AI返回有效的JSON格式数据，专为页面构建器的内容生成需求优化。';
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
     * 确保提示词包含JSON格式要求
     * 
     * @param string $prompt 原始提示词
     * @param array $params 额外参数
     * @return string 适配后的提示词
     */
    public function adaptPrompt(string $prompt, array $params = []): string
    {
        // 检查提示词是否已经包含JSON格式要求
        if (stripos($prompt, 'JSON') !== false || stripos($prompt, 'json') !== false) {
            // 已经包含JSON要求，直接返回
            return $prompt;
        }

        // 如果提示词中没有明确要求JSON格式，添加JSON格式要求
        $jsonRequirement = "\n\n重要提示：\n";
        $jsonRequirement .= "1. 必须返回有效的JSON格式数据\n";
        $jsonRequirement .= "2. JSON必须是有效的格式，可以直接解析\n";
        $jsonRequirement .= "3. 只返回JSON，不要包含其他说明文字或markdown代码块标记\n";
        $jsonRequirement .= "4. 如果返回markdown代码块，请确保JSON在代码块内\n";

        return $prompt . $jsonRequirement;
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
