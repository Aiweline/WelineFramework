<?php

declare(strict_types=1);

namespace Weline\Customer\Taglib;

/**
 * 后台客户可搜索单选渲染器（远程 customer_admin Resource，主题 w-language-select UI）。
 */
final class SearchableCustomerSelect
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
     *   limit:int,
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
        $limit = max(1, min(50, (int) $config['limit']));
        $defaultEmpty = (string) $config['default-empty-label'];
        $defaultPlaceholder = (string) $config['default-placeholder'];
        $notFound = (string) __('未找到匹配客户');
        $loadingLabel = (string) __('搜索中...');

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
            $attrs['limit'],
            $attrs['display'],
            $attrs['placeholder'],
            $attrs['empty-label'],
        );
        $attrs['id'] = $idLiteral;
        $code = \Weline\Framework\Taglib\AttributeCodeCompiler::attributes($attrs);

        $html = [];
        $html[] = self::inlineStyles();
        $html[] = '<?php ' . $code . ' ?>';
        $html[] = '<?php $__scs_id = ' . \var_export($idLiteral, true)
            . '; $__scs_name = ' . \var_export($nameLiteral, true)
            . '; $__scs_form = ' . \var_export($formAttr, true)
            . '; $__scs_default_empty = ' . \var_export($defaultEmpty, true)
            . '; $__scs_default_placeholder = ' . \var_export($defaultPlaceholder, true)
            . '; $__scs_limit = ' . $limit
            . '; $__scs_not_found = ' . \var_export($notFound, true)
            . '; $__scs_loading = ' . \var_export($loadingLabel, true)
            . '; $__scs_on_change = ' . \var_export($onChange, true)
            . '; $__scs_allow_empty = ' . ($allowEmpty ? 'true' : 'false')
            . '; ?>';
        $html[] = <<<'PHP'
<?php
$__scs_value = trim((string)($Taglib__value ?? ''));
$__scs_display = trim((string)($Taglib__display ?? ''));
$__scs_empty_label = trim((string)($Taglib__empty_label ?? ''));
if ($__scs_empty_label === '') {
    $__scs_empty_label = (string)$__scs_default_empty;
}
$__scs_placeholder = trim((string)($Taglib__placeholder ?? ''));
if ($__scs_placeholder === '') {
    $__scs_placeholder = (string)$__scs_default_placeholder;
}
$__scs_disabled = isset($Taglib__disabled) && in_array(strtolower(trim((string)$Taglib__disabled)), ['true', '1', 'yes'], true);
$__scs_required = isset($Taglib__required) && in_array(strtolower(trim((string)$Taglib__required)), ['true', '1', 'yes'], true);
$__scs_classes = [];
foreach (preg_split('/\s+/', trim((string)($Taglib__class ?? ''))) ?: [] as $__scs_class) {
    if (preg_match('/^w-[a-z0-9_-]+$/', $__scs_class) === 1) {
        $__scs_classes[] = $__scs_class;
    }
}
$__scs_style = trim((string)($Taglib__style ?? ''));
?>
PHP;

        $html[] = <<<'HTML'
<div
    class="w-language-select<?= $__scs_classes ? ' ' . htmlspecialchars(implode(' ', $__scs_classes), ENT_QUOTES) : '' ?>"
    style="<?= $__scs_style !== '' ? htmlspecialchars($__scs_style, ENT_QUOTES) : '' ?>"
    id="<?= htmlspecialchars($__scs_id, ENT_QUOTES) ?>_wrapper"
    data-w-component="customer-select"
    data-w-component-id="<?= htmlspecialchars($__scs_id, ENT_QUOTES) ?>"
    data-w-display-only="<?= $__scs_disabled ? 'true' : 'false' ?>"
    data-w-allow-empty="<?= $__scs_allow_empty ? 'true' : 'false' ?>"
    data-w-empty-text="<?= htmlspecialchars($__scs_empty_label, ENT_QUOTES) ?>"
    data-w-not-found="<?= htmlspecialchars($__scs_not_found, ENT_QUOTES) ?>"
    data-w-limit="<?= (int)$__scs_limit ?>"
    data-w-initial-display="<?= htmlspecialchars($__scs_display, ENT_QUOTES) ?>"
    data-w-width="full"
    data-w-on-change="<?= htmlspecialchars($__scs_on_change, ENT_QUOTES) ?>"
>
    <button
        type="button"
        class="w-language-select__trigger"
        id="<?= htmlspecialchars($__scs_id, ENT_QUOTES) ?>_trigger"
        aria-haspopup="listbox"
        aria-expanded="false"
        aria-controls="<?= htmlspecialchars($__scs_id, ENT_QUOTES) ?>_list"
        aria-label="<?= htmlspecialchars($__scs_empty_label, ENT_QUOTES) ?>"
        <?= $__scs_disabled ? 'disabled aria-disabled="true"' : '' ?>
    >
        <span class="w-language-select__tags" data-w-customer-tags>
            <span class="w-language-select__placeholder"><?= htmlspecialchars($__scs_empty_label, ENT_QUOTES) ?></span>
        </span>
        <?php if (!$__scs_disabled): ?>
            <w-icon name="chevron-down" size="sm"></w-icon>
        <?php endif; ?>
    </button>

    <input
        type="hidden"
        id="<?= htmlspecialchars($__scs_id, ENT_QUOTES) ?>"
        name="<?= htmlspecialchars($__scs_name, ENT_QUOTES) ?>"
        value="<?= htmlspecialchars($__scs_value, ENT_QUOTES) ?>"
        <?= $__scs_form !== '' ? ' form="' . htmlspecialchars($__scs_form, ENT_QUOTES) . '"' : '' ?>
        <?= $__scs_required ? ' required' : '' ?>
        data-w-customer-field
    >

    <?php if (!$__scs_disabled): ?>
        <div
            class="w-language-select__popover"
            id="<?= htmlspecialchars($__scs_id, ENT_QUOTES) ?>_popover"
            data-w-customer-select-panel
            data-w-placement="bottom-start"
            hidden
        >
            <input
                class="w-input w-language-select__search"
                type="search"
                id="<?= htmlspecialchars($__scs_id, ENT_QUOTES) ?>_search"
                placeholder="<?= htmlspecialchars($__scs_placeholder, ENT_QUOTES) ?>"
                autocomplete="off"
                aria-controls="<?= htmlspecialchars($__scs_id, ENT_QUOTES) ?>_list"
                data-w-customer-search
            >
            <div class="w-language-select__empty" data-w-customer-loading hidden><?= htmlspecialchars($__scs_loading, ENT_QUOTES) ?></div>
            <div
                class="w-language-select__list"
                id="<?= htmlspecialchars($__scs_id, ENT_QUOTES) ?>_list"
                role="listbox"
                data-w-customer-list
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
        $cssFile = \dirname(__DIR__) . '/view/statics/ui/components/weline-customer-select.css';
        if (!\is_readable($cssFile)) {
            return '<!-- Weline_Customer weline-customer-select.css missing -->';
        }

        return '<style>' . \file_get_contents($cssFile) . '</style>';
    }

    private static function inlineScript(): string
    {
        $jsFile = \dirname(__DIR__) . '/view/statics/ui/components/weline-customer-select.js';
        if (!\is_readable($jsFile)) {
            return '<!-- Weline_Customer weline-customer-select.js missing -->';
        }

        return '<script>' . \file_get_contents($jsFile) . '</script>';
    }
}
