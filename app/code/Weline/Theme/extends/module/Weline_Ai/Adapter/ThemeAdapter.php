<?php

declare(strict_types=1);

namespace Weline\Theme\Extends\Module\Weline_Ai\Adapter;

use Weline\Ai\Interface\AdapterModelBindingInterface;
use Weline\Ai\Interface\ScenarioAdapterInterface;

class ThemeAdapter implements ScenarioAdapterInterface, AdapterModelBindingInterface
{
    private const OPERATION_CONFIG_I18N_TRANSLATE = 'config_i18n_translate';
    private const VIRTUAL_LAYOUT_OPERATION_PREFIX = 'virtual_layout_';

    public function getDefaultModelBindings(): array
    {
        return [
            'text2text' => 'deepseek-v4-pro',
            'text2image' => 'gemini-3.1-flash-image-preview',
        ];
    }

    public function getCode(): string
    {
        return 'theme';
    }

    public function getName(): string
    {
        return __('主题 AI 适配器');
    }

    public function getDescription(): string
    {
        return __('用于主题可视化编辑器的配置翻译、虚拟主题布局源码生成、文案生成和主题图片生成。');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getSupportedModelTypes(): array
    {
        return ['*'];
    }

    public function adaptPrompt(string $prompt, array $params = []): string
    {
        $normalized = trim($prompt);
        $operation = (string)($params['operation'] ?? '');
        if (str_starts_with($operation, self::VIRTUAL_LAYOUT_OPERATION_PREFIX)) {
            return $this->adaptVirtualLayoutPrompt($normalized !== '' ? $normalized : $prompt, $params);
        }
        if ($operation !== self::OPERATION_CONFIG_I18N_TRANSLATE || $normalized === '') {
            return $prompt;
        }

        $targetLocales = $params['target_locales'] ?? [];
        if (!is_array($targetLocales)) {
            $targetLocales = [];
        }
        $targetJson = json_encode(array_values($targetLocales), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $normalized . "\n\n"
            . "Theme adapter output contract:\n"
            . "1. Output exactly one JSON object and no markdown, explanation, or code fences.\n"
            . "2. JSON keys must exactly match the requested target locale codes: {$targetJson}.\n"
            . "3. Preserve HTML tags, attributes, URLs, ids, class names, numbers, placeholders, and template expressions such as %{name}, {{name}}, <%= name %>, and <?= ... ?>.\n"
            . "4. Translate only human-readable text values.\n";
    }

    public function processResponse(string $response, array $params = []): string
    {
        $operation = (string)($params['operation'] ?? '');
        if (str_starts_with($operation, self::VIRTUAL_LAYOUT_OPERATION_PREFIX)) {
            return $this->extractJsonObject($response);
        }
        if ($operation !== self::OPERATION_CONFIG_I18N_TRANSLATE) {
            return $response;
        }

        $json = $this->extractJsonObject($response);
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $response;
        }

        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $response;
    }

    public function validateParams(array $params = []): array
    {
        $operation = (string)($params['operation'] ?? '');
        if (str_starts_with($operation, self::VIRTUAL_LAYOUT_OPERATION_PREFIX)) {
            $errors = [];
            if (trim((string)($params['layout_type'] ?? '')) === '') {
                $errors[] = __('缺少布局类型。');
            }
            if (trim((string)($params['layout_option'] ?? '')) === '') {
                $errors[] = __('缺少布局选项。');
            }
            return $errors;
        }
        if ($operation !== self::OPERATION_CONFIG_I18N_TRANSLATE) {
            return [];
        }

        $errors = [];
        if (trim((string)($params['source_locale'] ?? '')) === '') {
            $errors[] = __('缺少源语言。');
        }
        if (empty($params['target_locales']) || !is_array($params['target_locales'])) {
            $errors[] = __('缺少目标语言。');
        }

        return $errors;
    }

    public function getParamTemplate(): array
    {
        return [
            'description' => 'Theme editor AI adapter parameters',
            'fields' => [
                'operation' => [
                    'type' => 'select',
                    'options' => [
                        self::OPERATION_CONFIG_I18N_TRANSLATE,
                        'virtual_layout_create',
                        'virtual_layout_edit',
                        'virtual_layout_rebuild',
                        'virtual_layout_regenerate_images',
                    ],
                ],
                'source_locale' => ['type' => 'string'],
                'target_locales' => ['type' => 'array'],
                'field_key' => ['type' => 'string'],
                'layout_type' => ['type' => 'string'],
                'layout_option' => ['type' => 'string'],
                'selected_skill_codes' => ['type' => 'array'],
                'design_direction_snapshot' => ['type' => 'object'],
            ],
        ];
    }

    public function getExamples(): array
    {
        return [
            [
                'title' => 'Translate layout title',
                'description' => 'Translate a theme layout configuration value into installed locales.',
                'input' => 'Translate 首页 from zh_Hans_CN to en_US.',
                'expected_output' => '{"en_US":"Home"}',
            ],
        ];
    }

    public function supportsModel(string $modelCode): bool
    {
        return $modelCode !== '';
    }

    private function adaptVirtualLayoutPrompt(string $prompt, array $params = []): string
    {
        $operation = (string)($params['operation'] ?? '');
        $layoutType = (string)($params['layout_type'] ?? '');
        $layoutOption = (string)($params['layout_option'] ?? '');

        return trim($prompt) . "\n\n"
            . "Theme adapter virtual layout contract:\n"
            . "1. Operation: {$operation}; layout_type: {$layoutType}; layout_option: {$layoutOption}.\n"
            . "2. Output exactly one JSON object and no markdown, explanation, comments, or code fences.\n"
            . "3. JSON must include `source_code` as a complete Weline .phtml layout source, plus `name`, `description`, `asset_rewrites`, and `notes`.\n"
            . "4. The source_code must preserve default theme metadata comments, slots, hooks, head/body structure, data attributes, template variables, and theme CSS variables unless the user explicitly asks to change them.\n"
            . "5. Do not remove default-theme capabilities. If you change a section, keep an equivalent slot/hook/render path so existing widgets and runtime rendering keep working.\n"
            . "6. Do not add browser-side business logic, network calls, event listeners, external scripts, or asset rewrites for non virtual-theme-owned assets.\n"
            . "7. For image regeneration, rewrite only assets explicitly marked as virtual-theme-owned by the caller and leave inherited/default theme assets untouched.\n";
    }

    private function extractJsonObject(string $response): string
    {
        $text = trim($response);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z0-9_-]*\s*/', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
            $text = trim($text);
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end >= $start) {
            return substr($text, $start, $end - $start + 1);
        }

        return $text;
    }
}
