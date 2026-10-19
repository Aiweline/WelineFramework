<?php

declare(strict_types=1);

namespace Weline\Mail\Taglib;

/**
 * 企业邮箱域名可搜索单选渲染器（本地 options JSON，主题 w-language-select UI）。
 */
final class SearchableDomainSelect
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
        $notFound = (string) __('未找到匹配域名');

        $attrs = $attributes;
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
        $html[] = '<?php $__mds_id = ' . \var_export($idLiteral, true)
            . '; $__mds_name = ' . \var_export($nameLiteral, true)
            . '; $__mds_form = ' . \var_export($formAttr, true)
            . '; $__mds_default_empty = ' . \var_export($defaultEmpty, true)
            . '; $__mds_default_placeholder = ' . \var_export($defaultPlaceholder, true)
            . '; $__mds_not_found = ' . \var_export($notFound, true)
            . '; $__mds_on_change = ' . \var_export($onChange, true)
            . '; $__mds_allow_empty = ' . ($allowEmpty ? 'true' : 'false')
            . '; $__mds_auto_submit = ' . ($autoSubmit ? 'true' : 'false')
            . '; $__mds_class_extra = ' . \var_export($class, true)
            . '; $__mds_style_extra = ' . \var_export($style, true)
            . '; $__mds_disabled_flag = ' . ($disabled ? 'true' : 'false')
            . '; $__mds_required_flag = ' . ($required ? 'true' : 'false')
            . '; ?>';
        $html[] = <<<'PHP'
<?php
$__mds_value = trim((string)($Taglib__value ?? ''));
$__mds_display = trim((string)($Taglib__display ?? ''));
$__mds_empty_label = trim((string)($Taglib__empty_label ?? ''));
if ($__mds_empty_label === '') {
    $__mds_empty_label = (string)$__mds_default_empty;
}
$__mds_placeholder = trim((string)($Taglib__placeholder ?? ''));
if ($__mds_placeholder === '') {
    $__mds_placeholder = (string)$__mds_default_placeholder;
}
$__mds_options_raw = $Taglib__options ?? '[]';
if (is_array($__mds_options_raw)) {
    $__mds_options_json = json_encode($__mds_options_raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
} else {
    $__mds_options_json = trim((string)$__mds_options_raw);
    if ($__mds_options_json === '') {
        $__mds_options_json = '[]';
    }
}
$__mds_disabled = $__mds_disabled_flag
    || (isset($Taglib__disabled) && in_array(strtolower(trim((string)$Taglib__disabled)), ['true', '1', 'yes'], true));
$__mds_required = $__mds_required_flag
    || (isset($Taglib__required) && in_array(strtolower(trim((string)$Taglib__required)), ['true', '1', 'yes'], true));
$__mds_classes = [];
foreach (preg_split('/\s+/', trim((string)($Taglib__class ?? $__mds_class_extra))) ?: [] as $__mds_class) {
    if (preg_match('/^w-[a-z0-9_-]+$/', $__mds_class) === 1) {
        $__mds_classes[] = $__mds_class;
    }
}
$__mds_style = trim((string)($Taglib__style ?? $__mds_style_extra));
?>
PHP;

        $html[] = <<<'HTML'
<div
    class="w-language-select<?= $__mds_value !== '' ? ' has-value' : '' ?><?= $__mds_classes ? ' ' . htmlspecialchars(implode(' ', $__mds_classes), ENT_QUOTES) : '' ?>"
    style="<?= $__mds_style !== '' ? htmlspecialchars($__mds_style, ENT_QUOTES) : '' ?>"
    id="<?= htmlspecialchars($__mds_id, ENT_QUOTES) ?>_wrapper"
    data-w-component="mail-domain-select"
    data-w-component-id="<?= htmlspecialchars($__mds_id, ENT_QUOTES) ?>"
    data-has-value="<?= $__mds_value !== '' ? 'true' : 'false' ?>"
    data-w-display-only="<?= $__mds_disabled ? 'true' : 'false' ?>"
    data-w-allow-empty="<?= $__mds_allow_empty ? 'true' : 'false' ?>"
    data-w-auto-submit="<?= $__mds_auto_submit ? 'true' : 'false' ?>"
    data-w-form="<?= htmlspecialchars($__mds_form, ENT_QUOTES) ?>"
    data-w-empty-text="<?= htmlspecialchars($__mds_empty_label, ENT_QUOTES) ?>"
    data-w-not-found="<?= htmlspecialchars($__mds_not_found, ENT_QUOTES) ?>"
    data-w-initial-display="<?= htmlspecialchars($__mds_display, ENT_QUOTES) ?>"
    data-w-options="<?= htmlspecialchars($__mds_options_json, ENT_QUOTES) ?>"
    data-w-width="full"
    data-w-on-change="<?= htmlspecialchars($__mds_on_change, ENT_QUOTES) ?>"
    data-testid="mail-domain-select"
>
    <button
        type="button"
        class="w-language-select__trigger"
        id="<?= htmlspecialchars($__mds_id, ENT_QUOTES) ?>_trigger"
        aria-haspopup="listbox"
        aria-expanded="false"
        aria-controls="<?= htmlspecialchars($__mds_id, ENT_QUOTES) ?>_list"
        aria-label="<?= htmlspecialchars($__mds_empty_label, ENT_QUOTES) ?>"
        data-testid="mail-domain-select-trigger"
        <?= $__mds_disabled ? 'disabled aria-disabled="true"' : '' ?>
    >
        <span class="w-language-select__tags" data-w-mail-domain-tags>
            <span class="w-language-select__placeholder"><?= htmlspecialchars($__mds_empty_label, ENT_QUOTES) ?></span>
        </span>
        <?php if (!$__mds_disabled): ?>
            <w-icon name="chevron-down" size="sm"></w-icon>
        <?php endif; ?>
    </button>

    <input
        type="hidden"
        id="<?= htmlspecialchars($__mds_id, ENT_QUOTES) ?>"
        name="<?= htmlspecialchars($__mds_name, ENT_QUOTES) ?>"
        value="<?= htmlspecialchars($__mds_value, ENT_QUOTES) ?>"
        <?= $__mds_form !== '' ? ' form="' . htmlspecialchars($__mds_form, ENT_QUOTES) . '"' : '' ?>
        <?= $__mds_required ? ' required' : '' ?>
        data-account-field="<?= htmlspecialchars($__mds_name, ENT_QUOTES) ?>"
        data-w-mail-domain-field
        data-testid="mail-account-create-domain"
    >

    <?php if (!$__mds_disabled): ?>
        <div
            class="w-language-select__popover"
            id="<?= htmlspecialchars($__mds_id, ENT_QUOTES) ?>_popover"
            data-w-mail-domain-select-panel
            data-w-placement="bottom-start"
            hidden
        >
            <input
                class="w-input w-language-select__search"
                type="search"
                id="<?= htmlspecialchars($__mds_id, ENT_QUOTES) ?>_search"
                placeholder="<?= htmlspecialchars($__mds_placeholder, ENT_QUOTES) ?>"
                autocomplete="off"
                aria-controls="<?= htmlspecialchars($__mds_id, ENT_QUOTES) ?>_list"
                data-w-mail-domain-search
                data-testid="mail-domain-select-search"
            >
            <div
                class="w-language-select__list"
                id="<?= htmlspecialchars($__mds_id, ENT_QUOTES) ?>_list"
                role="listbox"
                data-w-mail-domain-list
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
        $cssFile = \dirname(__DIR__) . '/view/statics/ui/components/weline-mail-domain-select.css';
        if (!\is_readable($cssFile)) {
            return '<!-- Weline_Mail weline-mail-domain-select.css missing -->';
        }

        return '<style>' . \file_get_contents($cssFile) . '</style>';
    }

    private static function inlineScript(): string
    {
        $jsFile = \dirname(__DIR__) . '/view/statics/ui/components/weline-mail-domain-select.js';
        if (!\is_readable($jsFile)) {
            return '<!-- Weline_Mail weline-mail-domain-select.js missing -->';
        }

        return '<script>' . \file_get_contents($jsFile) . '</script>';
    }
}
