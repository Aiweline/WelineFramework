<?php

declare(strict_types=1);

namespace Weline\Currency\Taglib;

use Weline\Currency\Api\CurrencyCatalogInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\TaglibInterface;

class CurrencySelect implements TaglibInterface
{
    private static array $itemsCache = [];

    public static function clearProcessCaches(): void
    {
        self::$itemsCache = [];
    }

    public static function name(): string
    {
        return 'currency:select';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [
            'id' => true,
            'name' => false,
            'value' => false,
            'multiple' => false,
            'class' => false,
            'required' => false,
            'allow-empty' => false,
            'display-only' => false,
            'readonly-values' => false,
            'disabled-values' => false,
            'allowed-values' => false,
            'option-values' => false,
            'options-values' => false,
            'currencies' => false,
            'input-id' => false,
            'empty-text' => false,
            'search-placeholder' => false,
            'data-w-width' => false,
            'auto-submit' => false,
        ];
    }

    public static function callback(): callable
    {
        return static function ($tagKey, $config, $tagData, $attributes): string {
            if (empty($attributes['id'])) {
                throw new \InvalidArgumentException(__('id属性不能为空'));
            }

            $attributeCode = \Weline\Framework\Taglib\AttributeCodeCompiler::attributes($attributes);
            if (isset($attributes['name']) && trim((string)$attributes['name']) !== '') {
                $attributeCode .= "\n\$Taglib__name = "
                    . var_export(trim((string)$attributes['name']), true)
                    . ';';
            }
            if (!\array_key_exists('multiple', $attributes)) {
                $attributeCode .= "\n\$Taglib__multiple = false;";
            }

            $html = ['<?php ' . $attributeCode . ' ?>'];
            $html[] = <<<'PHP'
<?php
$__wcs_bool = static function ($value, bool $default = false): bool {
    if (\is_bool($value)) {
        return $value;
    }
    if ($value === null || $value === '') {
        return $default;
    }
    $value = \strtolower(\trim((string)$value));
    if (\in_array($value, ['true', '1', 'yes', 'on'], true)) {
        return true;
    }
    if (\in_array($value, ['false', '0', 'no', 'off'], true)) {
        return false;
    }
    return $default;
};
$__wcs_values = static function ($raw): array {
    if (\is_array($raw)) {
        $values = $raw;
    } elseif ($raw === null || $raw === '') {
        $values = [];
    } else {
        $raw = \trim((string)$raw);
        $decoded = ($raw !== '' && ($raw[0] === '[' || $raw[0] === '{')) ? \json_decode($raw, true) : null;
        $values = \is_array($decoded)
            ? $decoded
            : (\preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
    $result = [];
    foreach ($values as $value) {
        if (\is_array($value) && isset($value['code'])) {
            $value = $value['code'];
        }
        if (!\is_scalar($value)) {
            continue;
        }
        $value = \strtoupper(\trim((string)$value));
        if ($value !== '' && !\in_array($value, $result, true)) {
            $result[] = $value;
        }
    }
    return $result;
};
$__wcs_text = static function ($value, string $default = ''): string {
    $value = $value === null ? '' : \trim((string)$value);
    $value = \trim($value, "\"'");
    return $value !== '' ? $value : $default;
};
$__wcs_id = static function ($value, string $default): string {
    $value = \preg_replace('/[^A-Za-z0-9_:-]+/', '-', $value === null ? '' : \trim((string)$value));
    $value = \trim((string)$value, '-');
    return $value !== '' ? $value : $default;
};
$__wcs_multiple = $__wcs_bool($Taglib__multiple ?? false);
$__wcs_display_only = $__wcs_bool($Taglib__display_only ?? false);
$__wcs_required = $__wcs_bool($Taglib__required ?? false);
$__wcs_allow_empty = $__wcs_bool($Taglib__allow_empty ?? !$__wcs_required, !$__wcs_required);
$__wcs_selected = $__wcs_values($Taglib__value ?? []);
$__wcs_readonly = $__wcs_values($Taglib__readonly_values ?? []);
$__wcs_disabled = $__wcs_values($Taglib__disabled_values ?? []);
$__wcs_allowed = $Taglib__allowed_values ?? ($Taglib__option_values ?? ($Taglib__options_values ?? ($Taglib__currencies ?? [])));
foreach ($__wcs_readonly as $__wcs_code) {
    if (!\in_array($__wcs_code, $__wcs_selected, true)) {
        $__wcs_selected[] = $__wcs_code;
    }
}
if (!$__wcs_multiple && \count($__wcs_selected) > 1) {
    $__wcs_selected = [\reset($__wcs_selected) ?: ''];
}
$__wcs_component_id = $__wcs_id($Taglib__id ?? null, 'currency-select');
$__wcs_field_id = $__wcs_id($Taglib__input_id ?? null, $__wcs_component_id . '-field');
$__wcs_name = $__wcs_text($Taglib__name ?? '');
$__wcs_items = \Weline\Currency\Taglib\CurrencySelect::resolveCurrencyItems($__wcs_allowed);
$__wcs_empty = $__wcs_text(
    $Taglib__empty_text ?? '',
    $__wcs_multiple ? __('点击选择货币（可多选）') : __('点击选择货币')
);
$__wcs_search = $__wcs_text($Taglib__search_placeholder ?? '', __('搜索货币代码、名称或符号'));
$__wcs_classes = [];
foreach (\preg_split('/\s+/', $__wcs_text($Taglib__class ?? '')) ?: [] as $__wcs_class) {
    if (\preg_match('/^w-[a-z0-9_-]+$/', $__wcs_class) === 1) {
        $__wcs_classes[] = $__wcs_class;
    }
}
$__wcs_width = $__wcs_text($Taglib__data_w_width ?? '');
$__wcs_width = \in_array($__wcs_width, ['auto', 'full'], true) ? $__wcs_width : '';
$__wcs_auto_submit = $__wcs_bool($Taglib__auto_submit ?? false);
$__wcs_readonly_json = \json_encode($__wcs_readonly, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]';
?>
PHP;
            $html[] = <<<'HTML'
<div
    class="w-currency-select<?= $__wcs_classes ? ' ' . htmlspecialchars(implode(' ', $__wcs_classes), ENT_QUOTES, 'UTF-8') : '' ?>"
    id="<?= htmlspecialchars($__wcs_component_id, ENT_QUOTES, 'UTF-8') ?>_wrapper"
    data-w-component="currency-select"
    data-w-component-id="<?= htmlspecialchars($__wcs_component_id, ENT_QUOTES, 'UTF-8') ?>"
    data-w-multiple="<?= $__wcs_multiple ? 'true' : 'false' ?>"
    data-w-display-only="<?= $__wcs_display_only ? 'true' : 'false' ?>"
    data-w-allow-empty="<?= $__wcs_allow_empty ? 'true' : 'false' ?>"
    data-w-readonly-values="<?= htmlspecialchars($__wcs_readonly_json, ENT_QUOTES, 'UTF-8') ?>"
    data-w-empty-text="<?= htmlspecialchars($__wcs_empty, ENT_QUOTES, 'UTF-8') ?>"
    data-w-width="<?= htmlspecialchars($__wcs_width, ENT_QUOTES, 'UTF-8') ?>"
    data-w-auto-submit="<?= $__wcs_auto_submit ? 'true' : 'false' ?>"
>
    <button
        class="w-currency-select__trigger"
        id="<?= htmlspecialchars($__wcs_component_id, ENT_QUOTES, 'UTF-8') ?>_trigger"
        type="button"
        aria-haspopup="listbox"
        aria-expanded="false"
        aria-controls="<?= htmlspecialchars($__wcs_component_id, ENT_QUOTES, 'UTF-8') ?>_list"
        aria-label="<?= htmlspecialchars($__wcs_empty, ENT_QUOTES, 'UTF-8') ?>"
        <?= $__wcs_display_only ? 'disabled aria-disabled="true"' : '' ?>
    >
        <span class="w-currency-select__tags" data-w-currency-tags>
            <span class="w-currency-select__placeholder"><?= htmlspecialchars($__wcs_empty, ENT_QUOTES, 'UTF-8') ?></span>
        </span>
        <?php if (!$__wcs_display_only): ?>
            <w-icon name="chevron-down" size="sm"></w-icon>
        <?php endif; ?>
    </button>

    <select
        class="w-visually-hidden"
        id="<?= htmlspecialchars($__wcs_field_id, ENT_QUOTES, 'UTF-8') ?>"
        name="<?= htmlspecialchars($__wcs_name, ENT_QUOTES, 'UTF-8') ?>"
        <?= $__wcs_multiple ? 'multiple' : '' ?>
        <?= $__wcs_required ? 'required' : '' ?>
        tabindex="-1"
        aria-label="<?= htmlspecialchars($__wcs_empty, ENT_QUOTES, 'UTF-8') ?>"
        data-w-currency-field
    >
        <?php if (!$__wcs_multiple && $__wcs_allow_empty): ?>
            <option value="" <?= $__wcs_selected === [] ? 'selected' : '' ?>><?= htmlspecialchars((string)__('清空选择'), ENT_QUOTES, 'UTF-8') ?></option>
        <?php endif; ?>
        <?php foreach ($__wcs_items as $__wcs_item): ?>
            <?php
            $__wcs_code = \strtoupper(\trim((string)($__wcs_item['code'] ?? '')));
            if ($__wcs_code === '') {
                continue;
            }
            $__wcs_label = (string)($__wcs_item['label'] ?? $__wcs_item['name'] ?? $__wcs_code);
            $__wcs_tag_label = (string)($__wcs_item['tag_label'] ?? $__wcs_code);
            ?>
            <option
                value="<?= htmlspecialchars($__wcs_code, ENT_QUOTES, 'UTF-8') ?>"
                data-w-label="<?= htmlspecialchars($__wcs_label, ENT_QUOTES, 'UTF-8') ?>"
                data-w-tag-label="<?= htmlspecialchars($__wcs_tag_label, ENT_QUOTES, 'UTF-8') ?>"
                data-w-meta="<?= htmlspecialchars((string)($__wcs_item['meta'] ?? $__wcs_item['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                data-w-search="<?= htmlspecialchars((string)($__wcs_item['search'] ?? $__wcs_label), ENT_QUOTES, 'UTF-8') ?>"
                <?= \in_array($__wcs_code, $__wcs_selected, true) ? 'selected' : '' ?>
                <?= \in_array($__wcs_code, $__wcs_disabled, true) ? 'disabled' : '' ?>
            ><?= htmlspecialchars($__wcs_label, ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
    </select>

    <?php if (!$__wcs_display_only): ?>
        <div
            class="w-currency-select__popover"
            id="<?= htmlspecialchars($__wcs_component_id, ENT_QUOTES, 'UTF-8') ?>_popover"
            data-w-placement="bottom-start"
            hidden
        >
            <input
                class="w-input w-currency-select__search"
                type="search"
                placeholder="<?= htmlspecialchars($__wcs_search, ENT_QUOTES, 'UTF-8') ?>"
                autocomplete="off"
                aria-controls="<?= htmlspecialchars($__wcs_component_id, ENT_QUOTES, 'UTF-8') ?>_list"
                data-w-currency-search
            >
            <div
                class="w-currency-select__list"
                id="<?= htmlspecialchars($__wcs_component_id, ENT_QUOTES, 'UTF-8') ?>_list"
                role="listbox"
                aria-multiselectable="<?= $__wcs_multiple ? 'true' : 'false' ?>"
                data-w-currency-list
            ></div>
        </div>
    <?php endif; ?>
</div>
HTML;

            return \implode("\n", $html);
        };
    }

    public static function tag_self_close(): bool
    {
        return true;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    public static function parent(): ?string
    {
        return null;
    }

    public static function document(): string
    {
        $doc = <<<'DOC'
<h3><code>&lt;w:currency:select&gt;</code> 使用文档</h3>
<p>Weline UI 2.0 原生货币选择组件。支持搜索、单选、多选、只读值和标准表单提交；通过 <code>Weline.UI.get(element, 'currency-select')</code> 访问实例。</p>
DOC;

        return \htmlspecialchars($doc, ENT_NOQUOTES);
    }

    /**
     * @return list<array<string, string>>
     */
    public static function resolveCurrencyItems(mixed $allowedValues = null): array
    {
        $allowedCodes = self::normalizeInjectCodes($allowedValues);
        if ($allowedCodes === []) {
            return self::getCurrencyItems();
        }

        $base = self::getCurrencyItems();
        $byCode = [];
        foreach ($base as $item) {
            $code = \strtoupper(\trim((string)($item['code'] ?? '')));
            if ($code !== '') {
                $byCode[$code] = $item;
            }
        }

        $items = [];
        foreach ($allowedCodes as $code) {
            $key = \strtoupper(\trim($code));
            $items[] = $byCode[$key] ?? self::synthesizeCurrencyItem($key);
        }

        return $items;
    }

    /**
     * @return list<array<string, string>>
     */
    public static function getCurrencyItems(): array
    {
        if (isset(self::$itemsCache['catalog'])) {
            return self::$itemsCache['catalog'];
        }

        try {
            $catalog = ObjectManager::getInstance(CurrencyCatalogInterface::class);
            $records = $catalog->active();
        } catch (\Throwable) {
            $records = [];
        }

        $items = [];
        foreach ($records as $record) {
            $code = \strtoupper(\trim($record->code));
            if ($code === '') {
                continue;
            }
            $items[] = self::buildCurrencyItem(
                $code,
                \trim($record->name) !== '' ? \trim($record->name) : $code,
                \trim($record->symbol),
            );
        }

        if ($items === []) {
            $items = self::fallbackCurrencyItems();
        }

        return self::$itemsCache['catalog'] = $items;
    }

    /**
     * @return list<array<string, string>>
     */
    private static function fallbackCurrencyItems(): array
    {
        $defaults = [
            ['CNY', '人民币', '￥'],
            ['USD', '美元', '$'],
            ['EUR', '欧元', '€'],
            ['GBP', '英镑', '£'],
            ['JPY', '日元', '¥'],
        ];
        $items = [];
        foreach ($defaults as [$code, $name, $symbol]) {
            $items[] = self::buildCurrencyItem($code, $name, $symbol);
        }

        return $items;
    }

    /** @return list<string> */
    private static function normalizeInjectCodes(mixed $raw): array
    {
        if (\is_array($raw)) {
            $values = $raw;
        } elseif ($raw === null || $raw === '') {
            return [];
        } else {
            $raw = \trim((string)$raw);
            $decoded = ($raw !== '' && ($raw[0] === '[' || $raw[0] === '{')) ? \json_decode($raw, true) : null;
            $values = \is_array($decoded)
                ? $decoded
                : (\preg_split('/[\s,]+/', $raw, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        }

        $result = [];
        foreach ($values as $value) {
            if (\is_array($value)) {
                $value = $value['code'] ?? $value['value'] ?? '';
            }
            if (!\is_scalar($value)) {
                continue;
            }
            $value = \strtoupper(\trim((string)$value));
            if ($value !== '' && !\in_array($value, $result, true)) {
                $result[] = $value;
            }
        }

        return $result;
    }

    /** @return array<string, string> */
    private static function synthesizeCurrencyItem(string $code): array
    {
        $code = \strtoupper(\trim($code));
        return self::buildCurrencyItem($code, $code, '');
    }

    /** @return array<string, string> */
    private static function buildCurrencyItem(string $code, string $name, string $symbol): array
    {
        $label = $symbol !== '' ? $code . ' - ' . $name . ' (' . $symbol . ')' : $code . ' - ' . $name;
        $meta = $symbol !== '' ? $name . ' · ' . $symbol : $name;

        return [
            'code' => $code,
            'name' => $name,
            'symbol' => $symbol,
            'label' => $label,
            'tag_label' => $code,
            'meta' => $meta,
            'search' => \implode(' ', \array_filter([$code, $name, $symbol], static fn (string $part): bool => $part !== '')),
        ];
    }
}
