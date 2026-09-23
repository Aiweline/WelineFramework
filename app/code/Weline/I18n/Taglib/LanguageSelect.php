<?php

declare(strict_types=1);

namespace Weline\I18n\Taglib;

use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Locales;
use Weline\Framework\App\State;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
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
            // Omitted multiple must stay single-select even if a prior tag leaked Taglib__multiple.
            if (!\array_key_exists('multiple', $attributes)) {
                $attributeCode .= "\n\$Taglib__multiple = false;";
            }

            // 静态属性：编译期吐出 buildMarkup；动态属性由 runtimeCallback 接管。
            $phpOpen = '<' . '?php ';
            $phpClose = '?' . '>';
            $echoOpen = '<' . '?= ';
            return $phpOpen . $attributeCode . ' ' . $phpClose . "\n"
                . $echoOpen . '\\' . self::class . '::buildMarkup(['
                . "'id' => (string)(\$Taglib__id ?? ''),"
                . "'name' => (string)(\$Taglib__name ?? ''),"
                . "'value' => \$Taglib__value ?? '',"
                . "'multiple' => \$Taglib__multiple ?? false,"
                . "'class' => (string)(\$Taglib__class ?? ''),"
                . "'required' => \$Taglib__required ?? false,"
                . "'allow-empty' => \$Taglib__allow_empty ?? '',"
                . "'display-only' => \$Taglib__display_only ?? false,"
                . "'readonly-values' => \$Taglib__readonly_values ?? [],"
                . "'disabled-values' => \$Taglib__disabled_values ?? [],"
                . "'exclude-site-languages' => \$Taglib__exclude_site_languages ?? false,"
                . "'allowed-values' => \$Taglib__allowed_values ?? (\$Taglib__option_values ?? (\$Taglib__options_values ?? (\$Taglib__locales ?? []))),"
                . "'option-values' => \$Taglib__option_values ?? [],"
                . "'options-values' => \$Taglib__options_values ?? [],"
                . "'locales' => \$Taglib__locales ?? [],"
                . "'display-locale' => (string)(\$Taglib__display_locale ?? ''),"
                . "'input-id' => (string)(\$Taglib__input_id ?? ''),"
                . "'empty-text' => (string)(\$Taglib__empty_text ?? ''),"
                . "'search-placeholder' => (string)(\$Taglib__search_placeholder ?? ''),"
                . "'show-reference' => \$Taglib__show_reference ?? true,"
                . "'catalog' => (string)(\$Taglib__catalog ?? 'installed'),"
                . "'data-w-width' => (string)(\$Taglib__data_w_width ?? ''),"
                . "'auto-submit' => \$Taglib__auto_submit ?? false,"
                . ']) ' . $phpClose;
        };
    }

    /**
     * 动态属性路径：renderRuntimeTag 必须直接返回 HTML，不可再吐 PHP 源码。
     */
    public static function runtimeCallback(): callable
    {
        return static function (
            \Weline\Framework\View\Template $template,
            string $tagKey,
            array $attributes,
            string $content,
        ): string {
            unset($template, $content);
            if ($tagKey !== 'tag-self-close' && $tagKey !== 'tag-self-close-with-attrs') {
                return '';
            }

            return self::buildMarkup(is_array($attributes) ? $attributes : []);
        };
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function buildMarkup(array $attributes): string
    {
        $decode = static fn($value): string => html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $bool = static function (mixed $value, bool $default = false) use ($decode): bool {
            if (\is_bool($value)) {
                return $value;
            }
            if ($value === null || $value === '') {
                return $default;
            }
            $value = \strtolower(\trim($decode($value)));
            if (\in_array($value, ['true', '1', 'yes', 'on'], true)) {
                return true;
            }
            if (\in_array($value, ['false', '0', 'no', 'off'], true)) {
                return false;
            }

            return $default;
        };
        $values = static function (mixed $raw) use ($decode): array {
            if (\is_array($raw)) {
                $list = $raw;
            } elseif ($raw === null || $raw === '') {
                $list = [];
            } else {
                $raw = \trim($decode($raw));
                $decoded = ($raw !== '' && ($raw[0] === '[' || $raw[0] === '{')) ? \json_decode($raw, true) : null;
                $list = \is_array($decoded)
                    ? $decoded
                    : (\preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            }
            $result = [];
            foreach ($list as $value) {
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
        $text = static function (mixed $value, string $default = '') use ($decode): string {
            $value = $value === null ? '' : \trim($decode($value));
            $value = \trim($value, "\"'");

            return $value !== '' ? $value : $default;
        };
        $id = static function (mixed $value, string $default) use ($decode): string {
            $value = \preg_replace('/[^A-Za-z0-9_:-]+/', '-', $value === null ? '' : \trim($decode($value)));
            $value = \trim((string)$value, '-');

            return $value !== '' ? $value : $default;
        };

        $__wls_multiple = $bool($attributes['multiple'] ?? false);
        $__wls_display_only = $bool($attributes['display-only'] ?? false);
        $__wls_required = $bool($attributes['required'] ?? false);
        $allowEmptyRaw = $attributes['allow-empty'] ?? '';
        $__wls_allow_empty = ($allowEmptyRaw === '' || $allowEmptyRaw === null)
            ? !$__wls_required
            : $bool($allowEmptyRaw, !$__wls_required);
        $__wls_show_reference = $bool($attributes['show-reference'] ?? true, true);
        $__wls_selected = $values($attributes['value'] ?? []);
        $__wls_readonly = $values($attributes['readonly-values'] ?? []);
        $__wls_disabled = $values($attributes['disabled-values'] ?? []);
        $__wls_exclude_site = $bool($attributes['exclude-site-languages'] ?? false);
        $__wls_site_codes = $__wls_exclude_site ? self::resolveSiteLanguageCodes() : [];
        foreach ($__wls_site_codes as $__wls_site_code) {
            if (!\in_array($__wls_site_code, $__wls_disabled, true)) {
                $__wls_disabled[] = $__wls_site_code;
            }
        }
        $__wls_site_disabled = \array_fill_keys($__wls_site_codes, true);
        $__wls_allowed = $attributes['allowed-values']
            ?? ($attributes['option-values'] ?? ($attributes['options-values'] ?? ($attributes['locales'] ?? [])));
        foreach ($__wls_readonly as $__wls_code) {
            if (!\in_array($__wls_code, $__wls_selected, true)) {
                $__wls_selected[] = $__wls_code;
            }
        }
        if (!$__wls_multiple && \count($__wls_selected) > 1) {
            $__wls_selected = [\reset($__wls_selected) ?: ''];
        }
        $__wls_component_id = $id($attributes['id'] ?? null, 'language-select');
        $__wls_field_id = $id($attributes['input-id'] ?? null, $__wls_component_id . '-field');
        $__wls_name = $text($attributes['name'] ?? '');
        $__wls_display_locale = $text(
            $attributes['display-locale'] ?? '',
            \Weline\Framework\App\State::getLang() ?: \Weline\Framework\App\State::getLangLocal() ?: 'zh_Hans_CN'
        );
        $__wls_catalog = \strtolower($text($attributes['catalog'] ?? '', 'installed'));
        if (!\in_array($__wls_catalog, ['installed', 'global'], true)) {
            $__wls_catalog = 'installed';
        }
        $__wls_items = self::resolveLanguageItems($__wls_display_locale, $__wls_catalog, $__wls_allowed);
        $__wls_empty = $text(
            $attributes['empty-text'] ?? '',
            $__wls_multiple ? (string)__('点击选择语言（可多选）') : (string)__('点击选择语言')
        );
        $__wls_search = $text($attributes['search-placeholder'] ?? '', (string)__('搜索国家、语言或代码...'));
        $__wls_classes = [];
        foreach (\preg_split('/\s+/', $text($attributes['class'] ?? '')) ?: [] as $__wls_class) {
            if (\preg_match('/^w-[a-z0-9_-]+$/', $__wls_class) === 1) {
                $__wls_classes[] = $__wls_class;
            }
        }
        $__wls_width = $text($attributes['data-w-width'] ?? '');
        $__wls_width = \in_array($__wls_width, ['auto', 'full'], true) ? $__wls_width : '';
        $__wls_auto_submit = $bool($attributes['auto-submit'] ?? false);
        $__wls_readonly_json = \json_encode($__wls_readonly, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]';

        \Weline\Framework\Runtime\FiberOutputBuffer::beginCapture();
        try {
            include dirname(__DIR__) . '/view/templates/taglib/language-select-markup.phtml';

            return \Weline\Framework\Runtime\FiberOutputBuffer::endCapture();
        } catch (\Throwable $e) {
            \Weline\Framework\Runtime\FiberOutputBuffer::discardCapture();
            throw $e;
        }
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
        $translationLocales = array_values(array_unique([
            $displayLocale,
            ...\Weline\Framework\Cache\StorefrontCacheKeyContext::currentOrRequestFence()->translationLocales,
        ]));
        $cacheKey = DictionaryCacheNamespace::cacheKey(
            $catalog . '|' . $displayLocale . '|' . implode(',', $translationLocales),
            $translationLocales,
        );
        if (isset(DictionaryCacheNamespace::localCache(self::$itemsCache, 128, $translationLocales)[$cacheKey])) {
            return DictionaryCacheNamespace::localCache(self::$itemsCache, 128, $translationLocales)[$cacheKey];
        }
        if ($catalog === 'global') {
            return DictionaryCacheNamespace::localCache(self::$itemsCache, 128, $translationLocales)[$cacheKey] = self::buildGlobalLanguageItems($displayLocale);
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
            // Storefront/catalog SSR never embeds flag SVG; clients hydrate via
            // i18n.getCountryFlags (CDN-cacheable binquery) + browser local cache.
            $flag = '';
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

        return DictionaryCacheNamespace::localCache(self::$itemsCache, 128, $translationLocales)[$cacheKey] = $items;
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
