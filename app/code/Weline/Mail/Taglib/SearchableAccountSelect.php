<?php

declare(strict_types=1);

namespace Weline\Mail\Taglib;

/**
 * 企业邮箱账号可搜索单选渲染器（本地 options JSON，主题 w-language-select UI）。
 */
final class SearchableAccountSelect
{
    /**
     * @param array{
     *   id:string,
     *   name:string,
     *   class:string,
     *   style:string,
     *   form:string,
     *   on-change:string,
     *   allow-empty:bool,
     *   clearable:bool,
     *   disabled:bool,
     *   required:bool,
     *   auto-submit:bool,
     *   default-empty-label:string,
     *   default-placeholder:string,
     *   attributes:array<string,mixed>
     * } $config
     */
    public static function render(array $config): string
    {
        $attributes = $config['attributes'];
        $idLiteral = (string) $config['id'];
        $nameLiteral = (string) $config['name'];
        $class = (string) $config['class'];
        $style = (string) $config['style'];
        $formAttr = (string) $config['form'];
        $onChange = (string) $config['on-change'];
        $allowEmpty = (bool) $config['allow-empty'];
        $disabled = (bool) $config['disabled'];
        $required = (bool) $config['required'];
        $autoSubmit = (bool) $config['auto-submit'];
        $defaultEmpty = (string) $config['default-empty-label'];
        $defaultPlaceholder = (string) $config['default-placeholder'];
        $notFound = (string) __('未找到匹配邮箱');

        $attrs = $attributes;
        // Keep value / display / options / placeholder / empty-label for AttributeCodeCompiler → Taglib__*.
        unset(
            $attrs['id'],
            $attrs['name'],
            $attrs['form'],
            $attrs['class'],
            $attrs['style'],
            $attrs['on-change'],
            $attrs['allow-empty'],
            $attrs['clearable'],
            $attrs['disabled'],
            $attrs['required'],
            $attrs['auto-submit'],
            $attrs['multiple'],
        );
        $attrs['id'] = $idLiteral;
        $code = \Weline\Framework\Taglib\AttributeCodeCompiler::attributes($attrs);

        $html = [];
        $html[] = self::inlineStyles();
        $html[] = '<?php ' . $code . ' ?>';
        $html[] = '<?php $__mas_id = ' . \var_export($idLiteral, true)
            . '; $__mas_name = ' . \var_export($nameLiteral, true)
            . '; $__mas_form = ' . \var_export($formAttr, true)
            . '; $__mas_default_empty = ' . \var_export($defaultEmpty, true)
            . '; $__mas_default_placeholder = ' . \var_export($defaultPlaceholder, true)
            . '; $__mas_not_found = ' . \var_export($notFound, true)
            . '; $__mas_on_change = ' . \var_export($onChange, true)
            . '; $__mas_allow_empty = ' . ($allowEmpty ? 'true' : 'false')
            . '; $__mas_auto_submit = ' . ($autoSubmit ? 'true' : 'false')
            . '; $__mas_class_extra = ' . \var_export($class, true)
            . '; $__mas_style_extra = ' . \var_export($style, true)
            . '; $__mas_disabled_flag = ' . ($disabled ? 'true' : 'false')
            . '; $__mas_required_flag = ' . ($required ? 'true' : 'false')
            . '; ?>';
        $html[] = <<<'PHP'
<?php
$__mas_value = trim((string)($Taglib__value ?? ''));
$__mas_display = trim((string)($Taglib__display ?? ''));
$__mas_empty_label = trim((string)($Taglib__empty_label ?? ''));
if ($__mas_empty_label === '') {
    $__mas_empty_label = (string)$__mas_default_empty;
}
$__mas_placeholder = trim((string)($Taglib__placeholder ?? ''));
if ($__mas_placeholder === '') {
    $__mas_placeholder = (string)$__mas_default_placeholder;
}
$__mas_options_raw = $Taglib__options ?? '[]';
if (is_array($__mas_options_raw)) {
    $__mas_options_json = json_encode($__mas_options_raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
} else {
    $__mas_options_json = trim((string)$__mas_options_raw);
    if ($__mas_options_json === '') {
        $__mas_options_json = '[]';
    }
}
$__mas_disabled = $__mas_disabled_flag
    || (isset($Taglib__disabled) && in_array(strtolower(trim((string)$Taglib__disabled)), ['true', '1', 'yes'], true));
$__mas_required = $__mas_required_flag
    || (isset($Taglib__required) && in_array(strtolower(trim((string)$Taglib__required)), ['true', '1', 'yes'], true));
$__mas_classes = [];
foreach (preg_split('/\s+/', trim((string)($Taglib__class ?? $__mas_class_extra))) ?: [] as $__mas_class) {
    if (preg_match('/^w-[a-z0-9_-]+$/', $__mas_class) === 1) {
        $__mas_classes[] = $__mas_class;
    }
}
$__mas_style = trim((string)($Taglib__style ?? $__mas_style_extra));
?>
PHP;

        $html[] = <<<'HTML'
<div
    class="w-language-select<?= $__mas_value !== '' ? ' has-value' : '' ?><?= $__mas_classes ? ' ' . htmlspecialchars(implode(' ', $__mas_classes), ENT_QUOTES) : '' ?>"
    style="<?= $__mas_style !== '' ? htmlspecialchars($__mas_style, ENT_QUOTES) : '' ?>"
    id="<?= htmlspecialchars($__mas_id, ENT_QUOTES) ?>_wrapper"
    data-w-component="mail-account-select"
    data-w-component-id="<?= htmlspecialchars($__mas_id, ENT_QUOTES) ?>"
    data-has-value="<?= $__mas_value !== '' ? 'true' : 'false' ?>"
    data-w-display-only="<?= $__mas_disabled ? 'true' : 'false' ?>"
    data-w-allow-empty="<?= $__mas_allow_empty ? 'true' : 'false' ?>"
    data-w-auto-submit="<?= $__mas_auto_submit ? 'true' : 'false' ?>"
    data-w-form="<?= htmlspecialchars($__mas_form, ENT_QUOTES) ?>"
    data-w-empty-text="<?= htmlspecialchars($__mas_empty_label, ENT_QUOTES) ?>"
    data-w-not-found="<?= htmlspecialchars($__mas_not_found, ENT_QUOTES) ?>"
    data-w-initial-display="<?= htmlspecialchars($__mas_display, ENT_QUOTES) ?>"
    data-w-options="<?= htmlspecialchars($__mas_options_json, ENT_QUOTES) ?>"
    data-w-width="full"
    data-w-on-change="<?= htmlspecialchars($__mas_on_change, ENT_QUOTES) ?>"
    data-testid="mail-account-select"
>
    <button
        type="button"
        class="w-language-select__trigger"
        id="<?= htmlspecialchars($__mas_id, ENT_QUOTES) ?>_trigger"
        aria-haspopup="listbox"
        aria-expanded="false"
        aria-controls="<?= htmlspecialchars($__mas_id, ENT_QUOTES) ?>_list"
        aria-label="<?= htmlspecialchars($__mas_empty_label, ENT_QUOTES) ?>"
        data-testid="mail-account-select-trigger"
        <?= $__mas_disabled ? 'disabled aria-disabled="true"' : '' ?>
    >
        <span class="w-language-select__tags" data-w-mail-account-tags>
            <span class="w-language-select__placeholder"><?= htmlspecialchars($__mas_empty_label, ENT_QUOTES) ?></span>
        </span>
        <?php if (!$__mas_disabled): ?>
            <w-icon name="chevron-down" size="sm"></w-icon>
        <?php endif; ?>
    </button>

    <input
        type="hidden"
        id="<?= htmlspecialchars($__mas_id, ENT_QUOTES) ?>"
        name="<?= htmlspecialchars($__mas_name, ENT_QUOTES) ?>"
        value="<?= htmlspecialchars($__mas_value, ENT_QUOTES) ?>"
        <?= $__mas_form !== '' ? ' form="' . htmlspecialchars($__mas_form, ENT_QUOTES) . '"' : '' ?>
        <?= $__mas_required ? ' required' : '' ?>
        data-w-mail-account-field
        data-testid="mail-account-select-value"
    >

    <?php if (!$__mas_disabled): ?>
        <div
            class="w-language-select__popover"
            id="<?= htmlspecialchars($__mas_id, ENT_QUOTES) ?>_popover"
            data-w-mail-account-select-panel
            data-w-placement="bottom-start"
            hidden
        >
            <input
                class="w-input w-language-select__search"
                type="search"
                id="<?= htmlspecialchars($__mas_id, ENT_QUOTES) ?>_search"
                placeholder="<?= htmlspecialchars($__mas_placeholder, ENT_QUOTES) ?>"
                autocomplete="off"
                aria-controls="<?= htmlspecialchars($__mas_id, ENT_QUOTES) ?>_list"
                data-w-mail-account-search
                data-testid="mail-account-select-search"
            >
            <div
                class="w-language-select__list"
                id="<?= htmlspecialchars($__mas_id, ENT_QUOTES) ?>_list"
                role="listbox"
                data-w-mail-account-list
            ></div>
        </div>
    <?php endif; ?>
</div>
HTML;

        $html[] = self::inlineScript();

        return \implode("\n", $html);
    }

    private static function inlineStyles(): string
    {
        $cssFile = \dirname(__DIR__) . '/view/statics/ui/components/weline-mail-account-select.css';
        if (!\is_readable($cssFile)) {
            return '<!-- Weline_Mail weline-mail-account-select.css missing -->';
        }

        return '<style>' . \file_get_contents($cssFile) . '</style>';
    }

    private static function inlineScript(): string
    {
        $jsFile = \dirname(__DIR__) . '/view/statics/ui/components/weline-mail-account-select.js';
        if (!\is_readable($jsFile)) {
            return '<!-- Weline_Mail weline-mail-account-select.js missing -->';
        }

        return '<script>' . \file_get_contents($jsFile) . '</script>';
    }
}
