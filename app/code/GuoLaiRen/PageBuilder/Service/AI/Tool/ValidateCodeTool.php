<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Tool;

use Weline\Ai\Interface\ToolInterface;
use GuoLaiRen\PageBuilder\Service\AI\CodeValidator;
use GuoLaiRen\PageBuilder\Service\AI\FrameworkBuilder;
use Weline\Framework\Manager\ObjectManager;

/**
 * 浠ｇ爜楠岃瘉宸ュ叿
 * 
 * 楠岃瘉 AI 鐢熸垚鐨勭粍浠朵唬鐮佹槸鍚︾鍚堣鑼冿紙HTML/CSS/JS/PHP 璇硶銆佺姝㈡ā寮忕瓑锛?
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

        // AI 鏅鸿兘浣撲紶鍏ョ殑鏄?JSON 瀛楁锛坔tml_content銆乧ss_content 绛夛級锛?
        // 涓嶆槸瀹屾暣鐨?phtml 鏂囦欢锛屼笉闇€瑕?@component_start 绛夊厓鏁版嵁鍧椼€?
        // 浠呭仛瀛楁绾ч獙璇侊細绂佹妯″紡銆佹嫭鍙峰尮閰嶃€丆SS 瑙勮寖绛夈€?

        // 楠岃瘉 HTML锛堜粎妫€鏌ユ嫭鍙峰尮閰嶅拰绂佹妯″紡锛屼笉妫€鏌?@component_start 绛夌粨鏋勶級
        if (!empty($args['html_content'])) {
            $balanceResult = $validator->checkBalancedTokens($args['html_content']);
            if (!empty($balanceResult['errors'])) {
                $errors['html'] = $balanceResult['errors'];
            }
        }

        // 楠岃瘉 CSS
        if (!empty($args['css_content'])) {
            $cssResult = $validator->validateCss($args['css_content']);
            if (!empty($cssResult['errors'])) {
                $errors['css'] = $cssResult['errors'];
            }
            if (!empty($cssResult['warnings'])) {
                $warnings['css'] = $cssResult['warnings'];
            }
        }

        // 楠岃瘉 PHP 鍙橀噺澹版槑
        if (!empty($args['php_variables'])) {
            $phpResult = $validator->validatePhpCode($args['php_variables']);
            if (!empty($phpResult['errors'])) {
                $errors['php'] = $phpResult['errors'];
            }
        }

        // 妫€鏌?html_content 涓娇鐢ㄧ殑鍙橀噺鏄惁鍦?php_variables 涓０鏄?
        $htmlContent = $args['html_content'] ?? '';
        if (!empty($htmlContent)) {
            preg_match_all('/\$[a-zA-Z_][a-zA-Z0-9_]*/', $htmlContent, $matches);
            $usedVars = array_unique($matches[0] ?? []);
            $declaredVars = [];
            if (!empty($args['php_variables'])) {
                preg_match_all('/^\\s*\$([a-zA-Z_][a-zA-Z0-9_]*)\\s*=\\s*/m', (string)$args['php_variables'], $declared);
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
                    __('PHP 鍙橀噺鏈０鏄庯細%{1}', [implode(', ', $missing)]),
                ]);
            }
        }

        // 鍚勫瓧娈电殑绂佹妯″紡妫€鏌?
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
