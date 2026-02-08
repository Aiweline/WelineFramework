<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Tool;

use Weline\Ai\Interface\ToolInterface;
use GuoLaiRen\PageBuilder\Service\AI\CodeValidator;
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

        // 验证 HTML
        if (!empty($args['html_content'])) {
            $htmlResult = $validator->validate($args['html_content']);
            if (!empty($htmlResult['errors'])) {
                $errors['html'] = $htmlResult['errors'];
            }
            if (!empty($htmlResult['warnings'])) {
                $warnings['html'] = $htmlResult['warnings'];
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

        // 验证 PHP
        if (!empty($args['php_variables'])) {
            $phpResult = $validator->validatePhpCode($args['php_variables']);
            if (!empty($phpResult['errors'])) {
                $errors['php'] = $phpResult['errors'];
            }
            if (!empty($phpResult['warnings'])) {
                $warnings['php'] = $phpResult['warnings'];
            }
        }

        // 验证禁止模式
        $allCode = ($args['html_content'] ?? '') . ($args['css_content'] ?? '') . ($args['js_content'] ?? '');
        $prohibited = $validator->checkProhibitedPatterns($allCode, $args['category'] ?? 'content');
        if (!empty($prohibited['errors'])) {
            $errors['prohibited'] = $prohibited['errors'];
        }

        // 构建 AI 可读的验证结果（限制数组大小）
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
