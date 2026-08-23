<?php

declare(strict_types=1);

namespace Weline\I18n\Taglib;

use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Locales;
use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale;
use Weline\I18n\Model\Locals;

class LanguageSelect implements TaglibInterface
{
    private static array $itemsCache = [];

    public static function clearProcessCaches(): void
    {
        self::$itemsCache = [];
    }

    public static function name(): string
    {
        return 'i18n:language:select';
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
            'exclude-site-languages' => false,
            'allowed-values' => false,
            'option-values' => false,
            'options-values' => false,
            'locales' => false,
            'display-locale' => false,
            'input-id' => false,
            'empty-text' => false,
            'search-placeholder' => false,
            'show-reference' => false,
            'catalog' => false,
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
            // A form field name is an HTML identifier, not a template value.
            // AttributeCodeCompiler resolves bare words against template scope;
            // without this literal boundary `name="locale_code"` becomes the
            // current locale value whenever a `locale_code` variable exists.
            if (isset($attributes['name']) && trim((string)$attributes['name']) !== '') {
                $attributeCode .= "\n\$Taglib__name = "
                    . var_export(trim((string)$attributes['name']), true)
                    . ';';
            }
            $html = ['<?php ' . $attributeCode . ' ?>'];
            $html[] = <<<'PHP'
<?php
$__wls_bool = static function ($value, bool $default = false): bool {
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
$__wls_values = static function ($raw): array {
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
        $value = \trim((string)$value);
        if ($value !== '' && !\in_array($value, $result, true)) {
            $result[] = $value;
        }
    }
    return $result;
};
$__wls_text = static function ($value, string $default = ''): string {
    $value = $value === null ? '' : \trim((string)$value);
    $value = \trim($value, "\"'");
    return $value !== '' ? $value : $default;
};
$__wls_id = static function ($value, string $default): string {
    $value = \preg_replace('/[^A-Za-z0-9_:-]+/', '-', $value === null ? '' : \trim((string)$value));
    $value = \trim((string)$value, '-');
    return $value !== '' ? $value : $default;
};
$__wls_multiple = $__wls_bool($Taglib__multiple ?? false);
$__wls_display_only = $__wls_bool($Taglib__display_only ?? false);
$__wls_required = $__wls_bool($Taglib__required ?? false);
$__wls_allow_empty = $__wls_bool($Taglib__allow_empty ?? !$__wls_required, !$__wls_required);
$__wls_show_reference = $__wls_bool($Taglib__show_reference ?? true, true);
$__wls_selected = $__wls_values($Taglib__value ?? []);
$__wls_readonly = $__wls_values($Taglib__readonly_values ?? []);
$__wls_disabled = $__wls_values($Taglib__disabled_values ?? []);
$__wls_exclude_site = $__wls_bool($Taglib__exclude_site_languages ?? false);
$__wls_site_codes = $__wls_exclude_site
    ? \Weline\I18n\Taglib\LanguageSelect::resolveSiteLanguageCodes()
    : [];
foreach ($__wls_site_codes as $__wls_site_code) {
    if (!\in_array($__wls_site_code, $__wls_disabled, true)) {
        $__wls_disabled[] = $__wls_site_code;
    }
}
$__wls_site_disabled = \array_fill_keys($__wls_site_codes, true);
$__wls_allowed = $Taglib__allowed_values ?? ($Taglib__option_values ?? ($Taglib__options_values ?? ($Taglib__locales ?? [])));
foreach ($__wls_readonly as $__wls_code) {
    if (!\in_array($__wls_code, $__wls_selected, true)) {
        $__wls_selected[] = $__wls_code;
    }
}
if (!$__wls_multiple && \count($__wls_selected) > 1) {
    $__wls_selected = [\reset($__wls_selected) ?: ''];
}
$__wls_component_id = $__wls_id($Taglib__id ?? null, 'language-select');
$__wls_field_id = $__wls_id($Taglib__input_id ?? null, $__wls_component_id . '-field');
$__wls_name = $__wls_text($Taglib__name ?? '');
$__wls_display_locale = $__wls_text(
    $Taglib__display_locale ?? '',
    \Weline\Framework\App\State::getLang() ?: \Weline\Framework\App\State::getLangLocal() ?: 'zh_Hans_CN'
);
$__wls_catalog = \strtolower($__wls_text($Taglib__catalog ?? '', 'installed'));
if (!\in_array($__wls_catalog, ['installed', 'global'], true)) {
    $__wls_catalog = 'installed';
}
$__wls_items = \Weline\I18n\Taglib\LanguageSelect::resolveLanguageItems(
    $__wls_display_locale,
    $__wls_catalog,
    $__wls_allowed
);
$__wls_empty = $__wls_text(
    $Taglib__empty_text ?? '',
    $__wls_multiple ? __('点击选择语言（可多选）') : __('点击选择语言')
);
$__wls_search = $__wls_text($Taglib__search_placeholder ?? '', __('搜索国家、语言或代码...'));
$__wls_classes = [];
foreach (\preg_split('/\s+/', $__wls_text($Taglib__class ?? '')) ?: [] as $__wls_class) {
    if (\preg_match('/^w-[a-z0-9_-]+$/', $__wls_class) === 1) {
        $__wls_classes[] = $__wls_class;
    }
}
$__wls_width = $__wls_text($Taglib__data_w_width ?? '');
$__wls_width = \in_array($__wls_width, ['auto', 'full'], true) ? $__wls_width : '';
$__wls_auto_submit = $__wls_bool($Taglib__auto_submit ?? false);
$__wls_readonly_json = \json_encode($__wls_readonly, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]';
?>
PHP;
            $html[] = <<<'HTML'
<div
    class="w-language-select<?= $__wls_classes ? ' ' . htmlspecialchars(implode(' ', $__wls_classes), ENT_QUOTES, 'UTF-8') : '' ?>"
    id="<?= htmlspecialchars($__wls_component_id, ENT_QUOTES, 'UTF-8') ?>_wrapper"
    data-w-component="language-select"
    data-w-component-id="<?= htmlspecialchars($__wls_component_id, ENT_QUOTES, 'UTF-8') ?>"
    data-w-multiple="<?= $__wls_multiple ? 'true' : 'false' ?>"
    data-w-display-only="<?= $__wls_display_only ? 'true' : 'false' ?>"
    data-w-allow-empty="<?= $__wls_allow_empty ? 'true' : 'false' ?>"
    data-w-show-reference="<?= $__wls_show_reference ? 'true' : 'false' ?>"
    data-w-readonly-values="<?= htmlspecialchars($__wls_readonly_json, ENT_QUOTES, 'UTF-8') ?>"
    data-w-exclude-site-languages="<?= $__wls_exclude_site ? 'true' : 'false' ?>"
    data-w-excluded-label="<?= htmlspecialchars((string)__('已支持'), ENT_QUOTES, 'UTF-8') ?>"
    data-w-empty-text="<?= htmlspecialchars($__wls_empty, ENT_QUOTES, 'UTF-8') ?>"
    data-w-width="<?= htmlspecialchars($__wls_width, ENT_QUOTES, 'UTF-8') ?>"
    data-w-auto-submit="<?= $__wls_auto_submit ? 'true' : 'false' ?>"
>
    <button
        class="w-language-select__trigger"
        id="<?= htmlspecialchars($__wls_component_id, ENT_QUOTES, 'UTF-8') ?>_trigger"
        type="button"
        aria-haspopup="listbox"
        aria-expanded="false"
        aria-controls="<?= htmlspecialchars($__wls_component_id, ENT_QUOTES, 'UTF-8') ?>_list"
        aria-label="<?= htmlspecialchars($__wls_empty, ENT_QUOTES, 'UTF-8') ?>"
        <?= $__wls_display_only ? 'disabled aria-disabled="true"' : '' ?>
    >
        <span class="w-language-select__tags" data-w-language-tags>
            <span class="w-language-select__placeholder"><?= htmlspecialchars($__wls_empty, ENT_QUOTES, 'UTF-8') ?></span>
        </span>
        <?php if (!$__wls_display_only): ?>
            <w-icon name="chevron-down" size="sm"></w-icon>
        <?php endif; ?>
    </button>

    <select
        class="w-visually-hidden"
        id="<?= htmlspecialchars($__wls_field_id, ENT_QUOTES, 'UTF-8') ?>"
        name="<?= htmlspecialchars($__wls_name, ENT_QUOTES, 'UTF-8') ?>"
        <?= $__wls_multiple ? 'multiple' : '' ?>
        <?= $__wls_required ? 'required' : '' ?>
        tabindex="-1"
        aria-label="<?= htmlspecialchars($__wls_empty, ENT_QUOTES, 'UTF-8') ?>"
        data-w-language-field
    >
        <?php if (!$__wls_multiple && $__wls_allow_empty): ?>
            <option value="" <?= $__wls_selected === [] ? 'selected' : '' ?>><?= htmlspecialchars((string)__('清空选择'), ENT_QUOTES, 'UTF-8') ?></option>
        <?php endif; ?>
        <?php foreach ($__wls_items as $__wls_item): ?>
            <?php
            $__wls_code = trim((string)($__wls_item['code'] ?? ''));
            if ($__wls_code === '') {
                continue;
            }
            $__wls_label = $__wls_show_reference
                ? (string)($__wls_item['display_name'] ?? $__wls_item['tag_label'] ?? $__wls_item['name'] ?? $__wls_code)
                : (string)($__wls_item['name'] ?? $__wls_item['reference_name'] ?? $__wls_code);
            ?>
            <option
                value="<?= htmlspecialchars($__wls_code, ENT_QUOTES, 'UTF-8') ?>"
                data-w-label="<?= htmlspecialchars($__wls_label, ENT_QUOTES, 'UTF-8') ?>"
                data-w-tag-label="<?= htmlspecialchars((string)($__wls_item['tag_label'] ?? $__wls_label), ENT_QUOTES, 'UTF-8') ?>"
                data-w-country-code="<?= htmlspecialchars((string)($__wls_item['country_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                data-w-country-name="<?= htmlspecialchars((string)($__wls_item['country_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                data-w-meta="<?= htmlspecialchars(trim($__wls_code . ' · ' . (string)($__wls_item['self_name'] ?? $__wls_item['reference_name'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                data-w-search="<?= htmlspecialchars((string)($__wls_item['search'] ?? $__wls_label . ' ' . $__wls_code), ENT_QUOTES, 'UTF-8') ?>"
                <?= isset($__wls_site_disabled[$__wls_code]) ? 'data-w-site-language="true"' : '' ?>
                <?= \in_array($__wls_code, $__wls_selected, true) ? 'selected' : '' ?>
                <?= \in_array($__wls_code, $__wls_disabled, true) ? 'disabled' : '' ?>
            ><?= htmlspecialchars($__wls_label, ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
    </select>

    <?php if (!$__wls_display_only): ?>
        <div
            class="w-language-select__popover"
            id="<?= htmlspecialchars($__wls_component_id, ENT_QUOTES, 'UTF-8') ?>_popover"
            data-w-placement="bottom-start"
            hidden
        >
            <input
                class="w-input w-language-select__search"
                type="search"
                placeholder="<?= htmlspecialchars($__wls_search, ENT_QUOTES, 'UTF-8') ?>"
                autocomplete="off"
                aria-controls="<?= htmlspecialchars($__wls_component_id, ENT_QUOTES, 'UTF-8') ?>_list"
                data-w-language-search
            >
            <div
                class="w-language-select__list"
                id="<?= htmlspecialchars($__wls_component_id, ENT_QUOTES, 'UTF-8') ?>_list"
                role="listbox"
                aria-multiselectable="<?= $__wls_multiple ? 'true' : 'false' ?>"
                data-w-language-list
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
<h3><code>&lt;w:i18n:language:select&gt;</code> 使用文档</h3>
<p>Weline UI 2.0 原生语言选择组件。支持国家分组搜索、单选、多选、只读值和标准表单提交；通过 <code>Weline.UI.get(element, 'language-select')</code> 访问实例。</p>
<p><code>exclude-site-languages="true"</code> 会把当前站点已关联语言标为禁用（灰色不可选），适合「申请支持其他语言」等场景。</p>
DOC;

        return \htmlspecialchars($doc, ENT_NOQUOTES);
    }

    /**
     * Current website language codes via QueryProvider (no hard Websites class coupling).
     *
     * @return list<string>
     */
    public static function resolveSiteLanguageCodes(): array
    {
        try {
            $websiteId = (int)\Weline\Framework\Runtime\RequestContext::getWelineWebsiteId();
            $result = \w_query('websites', 'getWebsiteLanguageCodes', [
                'website_id' => $websiteId,
            ]);
            $codes = \is_array($result) ? ($result['languages'] ?? $result['data'] ?? $result) : [];
            if (!\is_array($codes)) {
                return [];
            }
            $out = [];
            foreach ($codes as $code) {
                if (\is_array($code) && isset($code['code'])) {
                    $code = $code['code'];
                }
                if (!\is_scalar($code)) {
                    continue;
                }
                $code = \trim((string)$code);
                if ($code !== '' && !\in_array($code, $out, true)) {
                    $out[] = $code;
                }
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, string>>
     */
    public static function getLanguageItems(string $displayLocale, string $catalog = 'installed'): array
    {
        $catalog = \strtolower(\trim($catalog));
        if (!\in_array($catalog, ['installed', 'global'], true)) {
            $catalog = 'installed';
        }
        $cacheKey = $catalog . '|' . $displayLocale;
        if (isset(self::$itemsCache[$cacheKey])) {
            return self::$itemsCache[$cacheKey];
        }
        if ($catalog === 'global') {
            return self::$itemsCache[$cacheKey] = self::buildGlobalLanguageItems($displayLocale);
        }

        /** @var I18n $i18n */
        $i18n = ObjectManager::getInstance(I18n::class);
        /** @var Locals $localsModel */
        $localsModel = ObjectManager::getInstance(Locals::class);
        /** @var Locale $localeModel */
        $localeModel = ObjectManager::getInstance(Locale::class);

        $localsRows = $localsModel
            ->clearQuery()
            ->where(Locals::schema_fields_IS_ACTIVE, 1)
            ->where(Locals::schema_fields_IS_INSTALL, 1)
            ->select()
            ->fetchArray();

        $rowsByCode = [];
        foreach ($localsRows as $row) {
            $code = (string)($row[Locals::schema_fields_CODE] ?? '');
            if ($code !== '') {
                $rowsByCode[$code][] = $row;
            }
        }

        $localeRows = $localeModel
            ->clearQuery()
            ->where(Locale::schema_fields_IS_ACTIVE, 1)
            ->where(Locale::schema_fields_IS_INSTALL, 1)
            ->select()
            ->fetchArray();

        foreach ($localeRows as $row) {
            $code = (string)($row[Locale::schema_fields_CODE] ?? '');
            if ($code === '' || isset($rowsByCode[$code])) {
                continue;
            }
            $rowsByCode[$code][] = [
                Locals::schema_fields_CODE => $code,
                Locals::schema_fields_TARGET_CODE => $displayLocale,
                Locals::schema_fields_NAME => $i18n->getLocaleName($code, $displayLocale),
            ];
        }

        $localeMetaRows = $localeModel
            ->clearQuery()
            ->where(Locale::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetchArray();
        $localeMeta = [];
        foreach ($localeMetaRows as $row) {
            $code = (string)($row[Locale::schema_fields_CODE] ?? '');
            if ($code !== '') {
                $localeMeta[$code] = $row;
            }
        }

        $countryNames = [];
        foreach (Countries::getNames(\extension_loaded('intl') ? $displayLocale : 'en') as $code => $name) {
            $countryNames[\strtoupper((string)$code)] = (string)$name;
        }

        $items = [];
        foreach ($rowsByCode as $code => $rows) {
            $preferred = $rows[0];
            foreach ($rows as $row) {
                if ((string)($row[Locals::schema_fields_TARGET_CODE] ?? '') === $displayLocale) {
                    $preferred = $row;
                    break;
                }
            }

            $name = \trim((string)($preferred[Locals::schema_fields_NAME] ?? ''));
            if ($name === '' || (string)($preferred[Locals::schema_fields_TARGET_CODE] ?? '') !== $displayLocale) {
                $name = $i18n->getLocaleName($code, $displayLocale);
            }
            $meta = $localeMeta[$code] ?? [];
            $countryCode = \strtoupper((string)($meta[Locale::schema_fields_COUNTRY_CODE] ?? self::extractCountryCode($code)));
            $countryName = $countryCode !== ''
                ? (string)($countryNames[$countryCode] ?? $countryCode)
                : (string)__('未分组国家');
            $flag = (string)($meta[Locale::schema_fields_FLAG] ?? '');
            if ($flag === '' && $countryCode !== '') {
                $flag = (string)$i18n->getCountryFlag($countryCode, 20, 15, true);
            }
            $shortCode = (string)($meta[Locale::schema_fields_SHORT_CODE] ?? Locale::extractShortCode($code));
            $iso2 = (string)($meta[Locale::schema_fields_ISO2] ?? '');
            $iso3 = (string)($meta[Locale::schema_fields_ISO3] ?? '');
            $selfName = $i18n->getLocaleName($code, $code);
            $referenceName = $i18n->getLocaleName($code, 'en');
            $displayName = self::buildDisplayName($name, $referenceName, $selfName, $code);
            $tagLabel = self::buildTagLabel($name, $selfName, $referenceName, $code);
            $items[] = [
                'code' => $code,
                'name' => $name,
                'self_name' => $selfName,
                'english_name' => $referenceName,
                'reference_name' => $referenceName,
                'display_name' => $displayName,
                'tag_label' => $tagLabel,
                'country_code' => $countryCode,
                'country_name' => $countryName,
                'flag' => $flag,
                'short_code' => $shortCode,
                'iso2' => $iso2,
                'iso3' => $iso3,
                'search' => \implode(' ', self::buildSearchTerms([
                    $code, $name, $selfName, $referenceName, $displayName, $tagLabel,
                    $countryCode, $countryName, $shortCode, $iso2, $iso3,
                ])),
            ];
        }

        \usort($items, static function (array $a, array $b): int {
            $country = \strnatcasecmp((string)($a['country_code'] ?? ''), (string)($b['country_code'] ?? ''));
            if ($country !== 0) {
                return $country;
            }
            $name = \strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
            return $name !== 0 ? $name : \strnatcasecmp((string)($a['code'] ?? ''), (string)($b['code'] ?? ''));
        });

        return self::$itemsCache[$cacheKey] = $items;
    }

    public static function getLanguageItemsJson(
        string $displayLocale,
        string $catalog = 'installed',
        mixed $allowedValues = null,
    ): string {
        $displayLocale = trim($displayLocale) !== ''
            ? trim($displayLocale)
            : (State::getLang() ?: State::getLangLocal() ?: 'zh_Hans_CN');
        $json = \json_encode(
            self::resolveLanguageItems($displayLocale, $catalog, $allowedValues),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );
        return $json === false ? '[]' : $json;
    }

    /**
     * @return list<array<string, string>>
     */
    public static function resolveLanguageItems(
        string $displayLocale,
        string $catalog = 'installed',
        mixed $allowedValues = null,
    ): array {
        $allowedCodes = self::normalizeInjectCodes($allowedValues);
        if ($allowedCodes === []) {
            return self::getLanguageItems($displayLocale, $catalog);
        }

        $base = self::getLanguageItems($displayLocale, $catalog === 'installed' ? 'global' : $catalog);
        $installed = $catalog === 'installed' ? self::getLanguageItems($displayLocale, 'installed') : [];
        $byCode = [];
        foreach ([...$base, ...$installed] as $item) {
            $code = \trim((string)($item['code'] ?? ''));
            if ($code !== '') {
                $byCode[\strtolower(\str_replace('-', '_', $code))] = $item;
            }
        }

        $items = [];
        foreach ($allowedCodes as $code) {
            $key = \strtolower(\str_replace('-', '_', $code));
            $items[] = $byCode[$key] ?? self::synthesizeLanguageItem($code, $displayLocale);
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
            if (\is_array($value) && isset($value['code'])) {
                $value = $value['code'];
            }
            if (!\is_scalar($value)) {
                continue;
            }
            $value = \trim((string)$value);
            if ($value !== '' && !\in_array($value, $result, true)) {
                $result[] = $value;
            }
        }
        return $result;
    }

    /** @return array<string, string> */
    private static function synthesizeLanguageItem(string $code, string $displayLocale): array
    {
        /** @var I18n $i18n */
        $i18n = ObjectManager::getInstance(I18n::class);
        try {
            $name = \trim((string)$i18n->getLocaleName($code, $displayLocale));
        } catch (\Throwable) {
            $name = '';
        }
        try {
            $selfName = \trim((string)$i18n->getLocaleName($code, $code));
        } catch (\Throwable) {
            $selfName = '';
        }
        try {
            $referenceName = \trim((string)$i18n->getLocaleName($code, 'en'));
        } catch (\Throwable) {
            $referenceName = '';
        }
        $countryCode = self::extractCountryCode($code);
        $displayName = self::buildDisplayName($name, $referenceName, $selfName, $code);
        $tagLabel = self::buildTagLabel($name, $selfName, $referenceName, $code);
        return [
            'code' => $code,
            'name' => $name !== '' ? $name : $code,
            'self_name' => $selfName,
            'english_name' => $referenceName,
            'reference_name' => $referenceName,
            'display_name' => $displayName,
            'tag_label' => $tagLabel,
            'country_code' => $countryCode,
            'country_name' => $countryCode !== '' ? $countryCode : (string)__('未分组国家'),
            'flag' => '',
            'short_code' => Locale::extractShortCode($code),
            'iso2' => '',
            'iso3' => '',
            'search' => \implode(' ', self::buildSearchTerms([
                $code, $name, $selfName, $referenceName, $displayName, $tagLabel, $countryCode,
            ])),
        ];
    }

    /** @return list<array<string, string>> */
    private static function buildGlobalLanguageItems(string $displayLocale): array
    {
        /** @var I18n $i18n */
        $i18n = ObjectManager::getInstance(I18n::class);
        try {
            $countryNames = Countries::getNames(\extension_loaded('intl') ? $displayLocale : 'en');
        } catch (\Throwable) {
            $countryNames = [];
        }
        $items = [];
        foreach (Locales::getLocales() as $rawCode) {
            $code = \str_replace('-', '_', \trim((string)$rawCode));
            if ($code === '' || \preg_match('/\A[A-Za-z]{2,3}(?:_[A-Za-z]{4})?(?:_[A-Z]{2}|_[0-9]{3})?\z/D', $code) !== 1) {
                continue;
            }
            $name = \trim($i18n->getLocaleName($code, $displayLocale));
            $selfName = \trim($i18n->getLocaleName($code, $code));
            $referenceName = \trim($i18n->getLocaleName($code, 'en'));
            $countryCode = self::extractCountryCode($code);
            $countryName = $countryCode !== ''
                ? (string)($countryNames[$countryCode] ?? $countryCode)
                : (string)__('全球语言');
            $displayName = self::buildDisplayName($name, $referenceName, $selfName, $code);
            $tagLabel = self::buildTagLabel($name, $selfName, $referenceName, $code);
            $items[$code] = [
                'code' => $code,
                'name' => $name !== '' ? $name : $code,
                'self_name' => $selfName,
                'english_name' => $referenceName,
                'reference_name' => $referenceName,
                'display_name' => $displayName,
                'tag_label' => $tagLabel,
                'country_code' => $countryCode,
                'country_name' => $countryName,
                'flag' => '',
                'short_code' => Locale::extractShortCode($code),
                'iso2' => '',
                'iso3' => '',
                'search' => \implode(' ', self::buildSearchTerms([
                    $code, $name, $selfName, $referenceName, $displayName, $tagLabel, $countryCode, $countryName,
                ])),
            ];
        }
        $items = \array_values($items);
        \usort($items, static function (array $left, array $right): int {
            $country = \strnatcasecmp((string)$left['country_code'], (string)$right['country_code']);
            return $country !== 0
                ? $country
                : \strnatcasecmp((string)$left['display_name'], (string)$right['display_name']);
        });
        return $items;
    }

    public static function buildDisplayName(string $localizedName, string $referenceName, string $selfName, string $code): string
    {
        $localizedName = \trim($localizedName);
        $referenceName = \trim($referenceName);
        $selfName = \trim($selfName);
        if ($localizedName === '') {
            $localizedName = $selfName !== '' ? $selfName : ($referenceName !== '' ? $referenceName : $code);
        }
        $locatorName = '';
        if ($selfName !== '' && $selfName !== $localizedName) {
            $locatorName = $selfName;
        } elseif ($selfName === '' && $referenceName !== '' && $referenceName !== $localizedName) {
            $locatorName = $referenceName;
        } elseif ($selfName === '' && $code !== $localizedName) {
            $locatorName = $code;
        }
        return $locatorName !== '' ? $localizedName . ' (' . $locatorName . ')' : $localizedName;
    }

    public static function buildTagLabel(string $localizedName, string $selfName, string $referenceName, string $code): string
    {
        foreach ([$selfName, $localizedName, $referenceName] as $candidate) {
            $candidate = \trim((string)$candidate);
            if ($candidate === '') {
                continue;
            }
            $compact = \trim((string)\preg_replace('/\s*[\(（][^）\)]+[）\)]\s*$/u', '', $candidate));
            return $compact !== '' ? $compact : $candidate;
        }
        return \trim($code) !== '' ? \trim($code) : $code;
    }

    /** @param array<int, mixed> $values @return list<string> */
    private static function buildSearchTerms(array $values): array
    {
        $terms = [];
        foreach ($values as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $value = \trim((string)$value);
            if ($value === '') {
                continue;
            }
            $normalized = \mb_strtolower($value, 'UTF-8');
            $terms[$normalized] ??= $value;
        }
        return \array_values($terms);
    }

    private static function extractCountryCode(string $localeCode): string
    {
        $parts = \explode('_', $localeCode);
        $last = \end($parts);
        return \is_string($last) && \strlen($last) === 2 ? \strtoupper($last) : '';
    }
}
