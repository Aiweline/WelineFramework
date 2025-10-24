<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>

 */

namespace Weline\Ai\Adapter;

use Weline\Ai\Api\ScenarioAdapterInterface;

/**
 * 代码生成场景适配器
 * 
 * 功能：
 * - 专门优化AI代码生成任务
 * - 支持多种编程语言
 * - 提供代码规范和最佳实践
 * - 优化代码质量和可读性
 */
class CodeGenerationAdapter implements ScenarioAdapterInterface
{
    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'code_generation';
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return '代码生成适配器';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return '专门用于AI代码生成任务的场景适配器，支持多种编程语言和代码规范';
    }

    /**
     * @inheritDoc
     */
    public function getVersion(): string
    {
        return '1.0.0';
    }

    /**
     * @inheritDoc
     */
    public function getSupportedModelTypes(): array
    {
        return ['chat', 'completion'];
    }

    /**
     * @inheritDoc
     */
    public function adaptPrompt(string $prompt, array $params = []): string
    {
        $language = $params['language'] ?? 'php';
        $style = $params['style'] ?? 'standard';
        $framework = $params['framework'] ?? '';
        $includeComments = $params['include_comments'] ?? true;
        $includeTests = $params['include_tests'] ?? false;

        // 构建代码生成提示词
        $adaptedPrompt = $this->buildCodePrompt($prompt, $language, $style, $framework, $includeComments, $includeTests);

        return $adaptedPrompt;
    }

    /**
     * @inheritDoc
     */
    public function processResponse(string $response, array $params = []): string
    {
        // 提取代码块
        $code = $this->extractCodeBlocks($response);
        
        // 清理和格式化代码
        $code = $this->cleanCode($code, $params['language'] ?? 'php');
        
        return $code;
    }

    /**
     * @inheritDoc
     */
    public function validateParams(array $params = []): array
    {
        $errors = [];

        // 验证编程语言
        $supportedLanguages = $this->getSupportedLanguages();
        if (isset($params['language']) && !in_array($params['language'], array_keys($supportedLanguages))) {
            $errors[] = '不支持的编程语言';
        }

        // 验证代码风格
        $supportedStyles = ['standard', 'psr', 'google', 'airbnb'];
        if (isset($params['style']) && !in_array($params['style'], $supportedStyles)) {
            $errors[] = '不支持的代码风格';
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function getParamTemplate(): array
    {
        return [
            'language' => [
                'type' => 'string',
                'required' => false,
                'description' => '编程语言',
                'example' => 'php',
                'options' => array_keys($this->getSupportedLanguages()),
                'default' => 'php'
            ],
            'style' => [
                'type' => 'string',
                'required' => false,
                'description' => '代码风格',
                'example' => 'psr',
                'options' => ['standard', 'psr', 'google', 'airbnb'],
                'default' => 'standard'
            ],
            'framework' => [
                'type' => 'string',
                'required' => false,
                'description' => '框架或库',
                'example' => 'laravel'
            ],
            'include_comments' => [
                'type' => 'boolean',
                'required' => false,
                'description' => '包含注释',
                'example' => true,
                'default' => true
            ],
            'include_tests' => [
                'type' => 'boolean',
                'required' => false,
                'description' => '包含测试代码',
                'example' => false,
                'default' => false
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function getExamples(): array
    {
        return [
            [
                'title' => 'PHP类生成',
                'description' => '生成一个用户管理类',
                'input' => '创建一个用户管理类，包含增删改查方法',
                'params' => [
                    'language' => 'php',
                    'style' => 'psr',
                    'include_comments' => true
                ],
                'expected_output' => 'PHP类代码'
            ],
            [
                'title' => 'JavaScript函数',
                'description' => '生成数据处理函数',
                'input' => '创建一个函数来处理用户数据验证',
                'params' => [
                    'language' => 'javascript',
                    'style' => 'airbnb',
                    'include_tests' => true
                ],
                'expected_output' => 'JavaScript函数代码'
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function supportsModel(string $modelCode): bool
    {
        // 支持代码生成的模型
        $supportedModels = [
            'gpt-3.5-turbo', 'gpt-4', 'gpt-4-turbo',
            'claude-3', 'codex', 'code-davinci-002'
        ];

        return in_array($modelCode, $supportedModels) || 
               str_contains($modelCode, 'gpt') || 
               str_contains($modelCode, 'claude') ||
               str_contains($modelCode, 'code');
    }

    /**
     * 构建代码生成提示词
     * 
     * @param string $prompt
     * @param string $language
     * @param string $style
     * @param string $framework
     * @param bool $includeComments
     * @param bool $includeTests
     * @return string
     */
    private function buildCodePrompt(
        string $prompt, 
        string $language, 
        string $style, 
        string $framework, 
        bool $includeComments, 
        bool $includeTests
    ): string {
        $languageInfo = $this->getSupportedLanguages()[$language] ?? $language;
        
        $adaptedPrompt = "请用{$languageInfo}编写代码来实现以下需求：\n\n{$prompt}\n\n要求：\n";
        
        // 添加代码风格要求
        $adaptedPrompt .= "1. 遵循{$this->getStyleDescription($style)}代码规范\n";
        
        // 添加框架要求
        if ($framework) {
            $adaptedPrompt .= "2. 使用{$framework}框架\n";
        }
        
        // 添加注释要求
        if ($includeComments) {
            $adaptedPrompt .= ($framework ? "3" : "2") . ". 包含详细的代码注释和文档\n";
        }
        
        // 添加测试要求
        if ($includeTests) {
            $nextNum = $includeComments ? ($framework ? "4" : "3") : ($framework ? "3" : "2");
            $adaptedPrompt .= "{$nextNum}. 包含单元测试代码\n";
        }
        
        $adaptedPrompt .= "\n请只返回代码，不要包含其他解释文字。";
        
        return $adaptedPrompt;
    }

    /**
     * 提取代码块
     * 
     * @param string $response
     * @return string
     */
    private function extractCodeBlocks(string $response): string
    {
        // 提取markdown代码块
        if (preg_match_all('/```(?:\w+)?\n(.*?)\n```/s', $response, $matches)) {
            return implode("\n\n", $matches[1]);
        }
        
        // 如果没有代码块标记，返回原始响应
        return trim($response);
    }

    /**
     * 清理代码
     * 
     * @param string $code
     * @param string $language
     * @return string
     */
    private function cleanCode(string $code, string $language): string
    {
        // 移除多余的空行
        $code = preg_replace('/\n{3,}/', "\n\n", $code);
        
        // 根据语言进行特定清理
        switch ($language) {
            case 'php':
                // 确保PHP标签正确
                if (!str_starts_with(trim($code), '<?php')) {
                    $code = "<?php\n\n" . $code;
                }
                break;
            case 'javascript':
                // 移除可能的HTML标签
                $code = strip_tags($code);
                break;
        }
        
        return trim($code);
    }

    /**
     * 获取支持的编程语言
     * 
     * @return array
     */
    private function getSupportedLanguages(): array
    {
        return [
            'php' => 'PHP',
            'javascript' => 'JavaScript',
            'typescript' => 'TypeScript',
            'python' => 'Python',
            'java' => 'Java',
            'csharp' => 'C#',
            'cpp' => 'C++',
            'go' => 'Go',
            'rust' => 'Rust',
            'ruby' => 'Ruby',
            'swift' => 'Swift',
            'kotlin' => 'Kotlin'
        ];
    }

    /**
     * 获取代码风格描述
     * 
     * @param string $style
     * @return string
     */
    private function getStyleDescription(string $style): string
    {
        $descriptions = [
            'standard' => '标准',
            'psr' => 'PSR',
            'google' => 'Google',
            'airbnb' => 'Airbnb'
        ];
        
        return $descriptions[$style] ?? $style;
    }
}
