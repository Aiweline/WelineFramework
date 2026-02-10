<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Tool;

use Weline\Ai\Interface\ToolInterface;
use GuoLaiRen\PageBuilder\Service\AI\CodeValidator;
use GuoLaiRen\PageBuilder\Service\AI\FrameworkBuilder;
use Weline\Framework\Manager\ObjectManager;

/**
 * 代码验证工具
 * 
 * 验证 AI 生成的组件代码是否符合规范（HTML/CSS/JS/PHP 语法、禁止模式等）
 */
class ValidateCodeTool implements ToolInterface
{
    public function getName(): string
    {
        return 'validate_code';
    }

    public function getDescription(): string
    {
        return 'Validate generated component code against PageBuilder conventions. Checks HTML, CSS, JS, PHP syntax and prohibited patterns. Use this before returning final output to catch errors.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'html_content' => [
                    'type' => 'string',
                    'description' => 'HTML template content to validate',
                ],
                'css_content' => [
                    'type' => 'string',
                    'description' => 'CSS styles to validate',
                ],
                'js_content' => [
                    'type' => 'string',
                    'description' => 'JavaScript code to validate (optional)',
                ],
                'php_variables' => [
                    'type' => 'string',
                    'description' => 'PHP variable declarations to validate (optional)',
                ],
                'category' => [
                    'type' => 'string',
                    'description' => 'Component category for context-specific validation',
                    'enum' => ['header', 'footer', 'content'],
                ],
            ],
            'required' => ['html_content', 'css_content'],
        ];
    }

    public function execute(array $args): mixed
    {
        /** @var CodeValidator $validator */
        $validator = ObjectManager::getInstance(CodeValidator::class);

        $errors = [];
        $warnings = [];

        // AI 智能体传入的是 JSON 字段（html_content、css_content 等），
        // 不是完整的 phtml 文件，不需要 @component_start 等元数据块。
        // 仅做字段级验证：禁止模式、括号匹配、CSS 规范等。

        // 验证 HTML（仅检查括号匹配和禁止模式，不检查 @component_start 等结构）
        if (!empty($args['html_content'])) {
            $balanceResult = $validator->checkBalancedTokens($args['html_content']);
            if (!empty($balanceResult['errors'])) {
                $errors['html'] = $balanceResult['errors'];
            }
        }

        // 验证 CSS
        if (!empty($args['css_content'])) {
            $cssResult = $validator->validateCss($args['css_content']);
            if (!empty($cssResult['errors'])) {
                $errors['css'] = $cssResult['errors'];
            }
            if (!empty($cssResult['warnings'])) {
                $warnings['css'] = $cssResult['warnings'];
            }
        }

        // 验证 PHP 变量声明
        if (!empty($args['php_variables'])) {
            $phpResult = $validator->validatePhpCode($args['php_variables']);
            if (!empty($phpResult['errors'])) {
                $errors['php'] = $phpResult['errors'];
            }
        }

        // 检查 html_content 中使用的变量是否在 php_variables 中声明
        $htmlContent = $args['html_content'] ?? '';
        if (!empty($htmlContent)) {
            preg_match_all('/\\$[a-zA-Z_][a-zA-Z0-9_]*/', $htmlContent, $matches);
            $usedVars = array_unique($matches[0] ?? []);
            $declaredVars = [];
            if (!empty($args['php_variables'])) {
                preg_match_all('/^\\s*\\$([a-zA-Z_][a-zA-Z0-9_]*)\\s*=\\s*/m', (string)$args['php_variables'], $declared);
                $declaredVars = array_map(fn($v) => '$' . $v, $declared[1] ?? []);
            }
            $baseAllowed = [
                '$componentId', '$component_config', '$getConfig',
                '$this', '$page', '$style', '$style_settings', '$colors',
                '$template_code', '$component_code', '$component_instance_id',
                '$is_preview', '$children',
            ];
            $category = strtolower((string)($args['category'] ?? 'content'));
            /** @var FrameworkBuilder $frameworkBuilder */
            $frameworkBuilder = ObjectManager::getInstance(FrameworkBuilder::class);
            $frameworkVars = $frameworkBuilder->getFrameworkProvidedVariables($category);
            $allowedVars = array_values(array_unique(array_merge($baseAllowed, $frameworkVars)));
            $missing = [];
            foreach ($usedVars as $var) {
                if (in_array($var, $allowedVars, true)) {
                    continue;
                }
                if (!in_array($var, $declaredVars, true)) {
                    $missing[] = $var;
                }
            }
            if (!empty($missing)) {
                $errors['php'] = array_merge($errors['php'] ?? [], [
                    __('PHP 变量未声明：%{1}', [implode(', ', $missing)]),
                ]);
            }
        }

        // 各字段的禁止模式检查
        $aiData = [
            'html_content' => $args['html_content'] ?? '',
            'css_content' => $args['css_content'] ?? '',
            'js_content' => $args['js_content'] ?? '',
            'php_variables' => $args['php_variables'] ?? '',
        ];
        $category = $args['category'] ?? 'content';
        $aiDataResult = $validator->validateAiData($aiData, $category);

        $isValid = empty($errors) && empty($aiDataResult['errors'] ?? []);

        return [
            'valid' => $isValid,
            'errors' => !empty($errors) ? $errors : ($aiDataResult['errors'] ?? []),
            'warnings' => $warnings,
            'summary' => $isValid
                ? 'All code passes validation.'
                : 'Code has issues that need to be fixed. See errors for details.',
        ];
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
