<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Ai\Adapter;

use Weline\Ai\Interface\ScenarioAdapterInterface;

/**
 * 默认通用适配器
 * 
 * 这是一个最基础的适配器，不做任何处理，直接传递输入和输出。
 * 用于直接创建助手和模型的联系，无需场景特定的转换。
 * 
 * 特点：
 * - 不修改提示词
 * - 不处理响应
 * - 支持所有模型
 * - 无参数验证
 * - 零开销，直通模式
 */
class DefaultAdapter implements ScenarioAdapterInterface
{
    /**
     * 获取适配器代码
     * 
     * @return string
     */
    public function getCode(): string
    {
        return 'default';
    }

    /**
     * 获取适配器名称
     * 
     * @return string
     */
    public function getName(): string
    {
        return '默认通用适配器';
    }

    /**
     * 获取适配器描述
     * 
     * @return string
     */
    public function getDescription(): string
    {
        return '默认的通用适配器，不做任何处理，直接传递用户输入和AI响应。适用于所有模型和场景，是最基础、最灵活的适配器。';
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
     * 默认适配器支持所有模型
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
     * 默认适配器不做任何修改，直接返回原始提示词
     * 
     * @param string $prompt 原始提示词
     * @param array $params 额外参数（未使用）
     * @return string 原始提示词（未修改）
     */
    public function adaptPrompt(string $prompt, array $params = []): string
    {
        // 直接返回原始提示词，不做任何处理
        return $prompt;
    }

    /**
     * 处理响应
     * 
     * 默认适配器不做任何处理，直接返回原始响应
     * 
     * @param string $response 原始响应
     * @param array $params 额外参数（未使用）
     * @return string 原始响应（未修改）
     */
    public function processResponse(string $response, array $params = []): string
    {
        // 直接返回原始响应，不做任何处理
        return $response;
    }

    /**
     * 验证输入参数
     * 
     * 默认适配器不验证参数，总是返回空数组（验证通过）
     * 
     * @param array $params 参数
     * @return array 空数组（验证总是通过）
     */
    public function validateParams(array $params = []): array
    {
        // 无需验证，总是返回空数组表示验证通过
        return [];
    }

    /**
     * 获取参数模板
     * 
     * @return array
     */
    public function getParamTemplate(): array
    {
        return []; // 默认适配器无需参数
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
                'title' => '通用对话示例',
                'description' => '最简单的使用方式，直接发送用户消息',
                'input' => '你好，请介绍一下你自己。',
                'expected_output' => 'AI会直接回复，无需任何特殊格式或处理',
            ],
            [
                'title' => '自由提问',
                'description' => '用户可以自由提问任何问题',
                'input' => '如何学习编程？',
                'expected_output' => 'AI会根据问题给出相应的回答',
            ],
            [
                'title' => '多轮对话',
                'description' => '支持连续的多轮对话',
                'input' => '继续上一个话题...',
                'expected_output' => 'AI会基于上下文继续对话',
            ],
        ];
    }

    /**
     * 检查是否支持指定模型
     * 
     * 默认适配器支持所有模型
     * 
     * @param string $modelCode 模型代码
     * @return bool 总是返回true
     */
    public function supportsModel(string $modelCode): bool
    {
        // 支持所有模型
        return true;
    }

    /**
     * 获取适配器类型
     * 
     * @return string
     */
    public function getType(): string
    {
        return 'general';
    }

    /**
     * 获取适配器特性
     * 
     * @return array
     */
    public function getFeatures(): array
    {
        return [
            'zero_overhead' => true,          // 零开销
            'supports_all_models' => true,    // 支持所有模型
            'no_transformation' => true,       // 不做转换
            'streaming_compatible' => true,    // 流式兼容
            'general_purpose' => true,         // 通用目的
        ];
    }

    /**
     * 获取适配器配置模板
     * 
     * @return array
     */
    public function getConfigTemplate(): array
    {
        return [
            'description' => '默认适配器无需配置，开箱即用',
            'fields' => [], // 无配置字段
        ];
    }

    /**
     * 获取适配器元数据
     * 
     * @return array
     */
    public function getMetadata(): array
    {
        return [
            'code' => $this->getCode(),
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'version' => $this->getVersion(),
            'type' => $this->getType(),
            'features' => $this->getFeatures(),
            'supported_models' => $this->getSupportedModelTypes(),
            'examples' => $this->getExamples(),
        ];
    }
}

