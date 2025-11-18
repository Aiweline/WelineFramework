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
 * 代码生成适配器
 * 
 * 专门用于代码生成场景的适配器
 * 特点：
 * - 针对编程语言优化提示词
 * - 提取代码块
 * - 支持多种编程语言
 * - 可配置代码风格和注释要求
 */
class CodeGenerationAdapter implements ScenarioAdapterInterface
{
    public function getCode(): string
    {
        return 'code_generation';
    }

    public function getName(): string
    {
        return '代码生成适配器';
    }

    public function getDescription(): string
    {
        return '专门用于代码生成的场景适配器。自动为提示词添加编程相关的指令，并从响应中提取代码块。支持多种编程语言和代码风格配置。';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getSupportedModelTypes(): array
    {
        return ['*']; // 支持所有模型
    }

    public function adaptPrompt(string $prompt, array $params = []): string
    {
        $language = $params['language'] ?? 'Python';
        $includeComments = $params['include_comments'] ?? true;
        $codeStyle = $params['code_style'] ?? 'clean';
        
        $instructions = "Generate {$language} code for the following requirement:\n\n{$prompt}\n\n";
        $instructions .= "Requirements:\n";
        $instructions .= "- Use {$language} best practices\n";
        
        if ($includeComments) {
            $instructions .= "- Include clear comments explaining the code\n";
        }
        
        switch ($codeStyle) {
            case 'clean':
                $instructions .= "- Follow clean code principles\n";
                $instructions .= "- Use descriptive variable names\n";
                break;
            case 'verbose':
                $instructions .= "- Add detailed comments for each step\n";
                $instructions .= "- Include docstrings/documentation\n";
                break;
            case 'minimal':
                $instructions .= "- Keep the code concise\n";
                $instructions .= "- Minimal comments\n";
                break;
        }
        
        $instructions .= "\nProvide ONLY the code, wrapped in a code block.";
        
        return $instructions;
    }

    public function processResponse(string $response, array $params = []): string
    {
        // 提取代码块
        $pattern = '/```(?:\w+)?\s*([\s\S]*?)```/';
        if (preg_match($pattern, $response, $matches)) {
            return trim($matches[1]);
        }
        
        // 如果没有代码块标记，返回原始内容
        return $response;
    }

    public function validateParams(array $params = []): array
    {
        $errors = [];
        
        // 验证编程语言
        if (isset($params['language'])) {
            $supportedLanguages = ['Python', 'JavaScript', 'Java', 'PHP', 'C++', 'C#', 'Go', 'Rust', 'TypeScript', 'Ruby'];
            if (!in_array($params['language'], $supportedLanguages)) {
                $errors[] = '不支持的编程语言：' . $params['language'];
            }
        }
        
        return $errors;
    }

    public function getParamTemplate(): array
    {
        return [
            'description' => '配置代码生成的语言、风格和注释要求',
            'fields' => [
                [
                    'name' => 'language',
                    'label' => '编程语言',
                    'type' => 'select',
                    'required' => true,
                    'default' => 'Python',
                    'options' => [
                        ['value' => 'Python', 'label' => 'Python'],
                        ['value' => 'JavaScript', 'label' => 'JavaScript'],
                        ['value' => 'TypeScript', 'label' => 'TypeScript'],
                        ['value' => 'Java', 'label' => 'Java'],
                        ['value' => 'PHP', 'label' => 'PHP'],
                        ['value' => 'C++', 'label' => 'C++'],
                        ['value' => 'C#', 'label' => 'C#'],
                        ['value' => 'Go', 'label' => 'Go'],
                        ['value' => 'Rust', 'label' => 'Rust'],
                        ['value' => 'Ruby', 'label' => 'Ruby'],
                    ],
                    'description' => '选择要生成的编程语言'
                ],
                [
                    'name' => 'code_style',
                    'label' => '代码风格',
                    'type' => 'select',
                    'required' => false,
                    'default' => 'clean',
                    'options' => [
                        ['value' => 'clean', 'label' => '简洁代码 (Clean Code)'],
                        ['value' => 'verbose', 'label' => '详细注释 (Verbose)'],
                        ['value' => 'minimal', 'label' => '极简风格 (Minimal)'],
                    ],
                    'description' => '选择代码的注释和文档风格'
                ],
                [
                    'name' => 'include_comments',
                    'label' => '包含注释',
                    'type' => 'checkbox',
                    'required' => false,
                    'default' => true,
                    'description' => '是否在生成的代码中包含注释'
                ],
                [
                    'name' => 'include_tests',
                    'label' => '包含测试',
                    'type' => 'checkbox',
                    'required' => false,
                    'default' => false,
                    'description' => '是否生成单元测试代码'
                ],
            ]
        ];
    }

    public function getExamples(): array
    {
        return [
            [
                'title' => 'Python快速排序',
                'description' => '生成Python的快速排序算法实现',
                'input' => '实现快速排序算法',
                'expected_output' => '带注释的Python快速排序代码',
            ],
            [
                'title' => 'JavaScript API客户端',
                'description' => '生成REST API客户端代码',
                'input' => '创建一个REST API客户端类',
                'expected_output' => '完整的JavaScript API客户端实现',
            ],
            [
                'title' => 'Java数据结构',
                'description' => '实现自定义数据结构',
                'input' => '实现一个二叉搜索树',
                'expected_output' => 'Java二叉搜索树完整实现',
            ],
        ];
    }

    public function supportsModel(string $modelCode): bool
    {
        return true; // 支持所有模型
    }
}
