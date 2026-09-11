<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale;
use Weline\I18n\Service\AiTranslationConfig;
use Weline\I18n\Service\I18nAiTranslationAdapter;

/**
 * 构建 local 标签翻译表单数据（供抽屉原生 UI 与兼容 GET 页共用）。
 */
final class TaglibLocalFormService
{
    public function __construct(
        private readonly I18n $i18n,
        private readonly I18nAiTranslationAdapter $translationAdapter,
        private readonly AiTranslationConfig $translationConfig,
    ) {
    }

    /**
     * @return array{success: bool, message: string, data: array<string, mixed>}
     */
    public function buildFormPayload(
        string $modelName,
        string $field,
        string $id,
        string $value,
        string $search = '',
        ?string $displayLocale = null,
    ): array {
        $modelName = trim($modelName);
        $field = trim($field);
        $id = trim($id);
        $value = trim($value);
        $search = trim($search);
        $displayLocale = trim((string)($displayLocale ?? Cookie::getLangLocal() ?? 'zh_Hans_CN'));

        if ($modelName === '') {
            return $this->error((string)__('请设置local标签model属性！'));
        }
        if ($field === '') {
            return $this->error((string)__('请选择一个字段！'));
        }
        if ($id === '') {
            return $this->error((string)__('请设置local标签id属性！'));
        }

        /** @var LocalModel $model */
        $model = ObjectManager::getInstance($modelName);
        $isConfigField = str_starts_with($field, 'config.');
        $configPath = $isConfigField ? substr($field, 7) : '';

        $installedLocales = $this->loadInstalledLocales($displayLocale, $search);
        if ($installedLocales === []) {
            return $this->error((string)__('当前站点未配置可编辑的关联语言。请先在网站管理中配置关联语言，并确保这些语言已在 I18n 中安装启用。'));
        }

        $localDescriptions = $model->reset()
            ->where($model::schema_fields_ID, $id)
            ->select()
            ->fetchArray();
        $localDescriptions = is_array($localDescriptions) ? $localDescriptions : [];

        if ($isConfigField) {
            foreach ($localDescriptions as &$localDescription) {
                $configData = isset($localDescription['config'])
                    ? json_decode((string)$localDescription['config'], true)
                    : [];
                $configData = is_array($configData) ? $configData : [];
                $localDescription[$field] = $this->getNestedValue($configData, $configPath) ?? $value;
            }
            unset($localDescription);
        }

        $rowsByCode = [];
        foreach ($localDescriptions as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string)($row[$model::schema_fields_local_code] ?? ''));
            if ($code !== '') {
                $rowsByCode[$code] = $row;
            }
        }

        $baseLocale = $this->resolveBaseLocale();
        $locales = [];
        foreach ($installedLocales as $locale) {
            $code = (string)($locale['code'] ?? '');
            if ($code === '') {
                continue;
            }

            $existing = $rowsByCode[$code] ?? [];
            $storedValue = trim((string)($existing[$field] ?? ''));
            $isFilled = $storedValue !== '' && !I18nCsvCodec::isJunkTranslation($storedValue);

            $locales[] = [
                'local_code' => $code,
                'value' => $storedValue,
                'filled' => $isFilled,
                'is_base' => $baseLocale !== '' && strcasecmp($code, $baseLocale) === 0,
                'local' => [
                    'code' => $code,
                    'name' => (string)($locale['name'] ?? $code),
                    'native_name' => (string)($locale['native_name'] ?? ''),
                    'flag' => (string)($locale['flag'] ?? ''),
                ],
            ];
        }

        $effective = $this->resolveEffectiveSource($locales, $baseLocale, $value);
        $sourceLabel = $effective['text'] !== '' ? $effective['text'] : $value;

        return [
            'success' => true,
            'message' => '',
            'data' => [
                'model' => $modelName,
                'field' => $field,
                'id' => $id,
                'value' => $value,
                'id_field' => $model::schema_fields_ID,
                'source_label' => $sourceLabel,
                'base_locale' => $baseLocale,
                'source_locale' => $effective['locale'],
                'source_fallback' => (bool)$effective['fallback'],
                'locales' => $locales,
                'locale_count' => count($locales),
                'filled_count' => count(array_filter($locales, static fn(array $row): bool => (bool)($row['filled'] ?? false))),
            ],
        ];
    }

    /**
     * @return array{success: bool, message: string, data: array<string, mixed>}
     */
    public function aiTranslate(
        string $modelName,
        string $field,
        string $id,
        string $value,
        bool $retranslateAll = false,
    ): array {
        $modelName = trim($modelName);
        $field = trim($field);
        $id = trim($id);
        $value = trim($value);

        if ($modelName === '') {
            return $this->error((string)__('请设置local标签model属性！'));
        }
        if ($field === '') {
            return $this->error((string)__('请选择一个字段！'));
        }
        if ($id === '') {
            return $this->error((string)__('请设置local标签id属性！'));
        }
        if (str_starts_with($field, 'config.')) {
            return $this->error((string)__('暂不支持 config 嵌套字段的 AI 翻译'));
        }

        /** @var LocalModel $model */
        $model = ObjectManager::getInstance($modelName);
        $recordId = (int)$id;
        if ($recordId <= 0) {
            return $this->error((string)__('请设置local标签id属性！'));
        }

        $baseLocale = $this->resolveBaseLocale();
        $existingByCode = $this->loadExistingLocalRows($model, $recordId);
        $siteLocaleCodes = $this->resolveSiteLocaleCodes();
        $localeRows = [];
        foreach ($siteLocaleCodes as $code) {
            $stored = trim((string)(($existingByCode[$code][$field] ?? '')));
            $localeRows[] = [
                'local_code' => $code,
                'value' => $stored,
                'filled' => $stored !== '' && !I18nCsvCodec::isJunkTranslation($stored),
            ];
        }
        $effective = $this->resolveEffectiveSource($localeRows, $baseLocale, $value);
        $sourceLocale = trim((string)$effective['locale']);
        $sourceText = trim((string)$effective['text']);
        if ($sourceText === '' || $sourceLocale === '') {
            return $this->error((string)__('源文本为空，无法翻译'));
        }

        $this->upsertFieldValue($model, $recordId, $sourceLocale, $field, $sourceText);

        $targetLocales = array_values(array_filter(
            $siteLocaleCodes,
            static fn(string $locale): bool => strcasecmp($locale, $sourceLocale) !== 0,
        ));
        if ($targetLocales === []) {
            return $this->error((string)__('当前站点没有可翻译的目标语言。请先在网站管理中配置关联语言。'));
        }

        $translated = 0;
        $skipped = 0;
        $locales = [];
        $errors = [];

        foreach ($targetLocales as $localeCode) {
            $existing = $existingByCode[$localeCode] ?? [];
            $stored = trim((string)($existing[$field] ?? ''));
            if (!$retranslateAll && $this->isRealTranslation($stored, $sourceText)) {
                $skipped++;
                continue;
            }

            $result = $this->translationAdapter->translateBatch(
                [$sourceText],
                $sourceLocale,
                $localeCode,
                $this->translationConfig->getStrategy($localeCode),
                'taglib',
            );
            if (!$result['success']) {
                $errors = array_merge($errors, $result['errors']);
                continue;
            }

            $translation = trim((string)($result['translations'][$sourceText] ?? ''));
            if ($translation === '' || I18nCsvCodec::isJunkTranslation($translation)) {
                continue;
            }

            $this->upsertFieldValue($model, $recordId, $localeCode, $field, $translation);
            $translated++;
            $locales[] = $localeCode;
        }

        if ($translated === 0) {
            if (!$retranslateAll && $skipped > 0) {
                return [
                    'success' => true,
                    'message' => (string)__('所有语言均已翻译，无需补充'),
                    'data' => [
                        'translated' => 0,
                        'skipped' => $skipped,
                        'locales' => [],
                        'errors' => array_values(array_unique($errors)),
                    ],
                ];
            }

            $message = $errors !== []
                ? (string)__('AI翻译调用失败')
                : (string)__('AI翻译未返回目标语言结果');
            return $this->error($message);
        }

        return [
            'success' => true,
            'message' => (string)__(
                'AI 翻译完成：%{1} 个语言',
                [(string)$translated],
            ),
            'data' => [
                'translated' => $translated,
                'skipped' => $skipped,
                'locales' => $locales,
                'errors' => array_values(array_unique($errors)),
            ],
        ];
    }

    /**
     * 批量 AI 翻译多个 LocalModel 字段；空值与已译字段可跳过。
     *
     * @param array<string, string> $fieldValues
     * @return array{success: bool, message: string, data: array<string, mixed>}
     */
    public function aiTranslateFieldsBulk(
        string $modelName,
        string $id,
        array $fieldValues,
        bool $retranslateAll = false,
    ): array {
        $modelName = trim($modelName);
        $id = trim($id);
        if ($modelName === '') {
            return $this->error((string)__('请设置local标签model属性！'));
        }
        if ($id === '' || (int)$id <= 0) {
            return $this->error((string)__('请设置local标签id属性！'));
        }

        $fieldsSkippedEmpty = 0;
        $fieldsSkippedDone = 0;
        $fieldsTranslated = 0;
        $totalTranslated = 0;
        $totalSkippedLocales = 0;
        $fieldResults = [];
        $errors = [];

        foreach ($fieldValues as $field => $value) {
            if (!is_string($field)) {
                continue;
            }
            $field = trim($field);
            $value = trim(is_scalar($value) ? (string)$value : '');
            if ($field === '' || str_starts_with($field, 'config.')) {
                continue;
            }
            if ($value === '') {
                $fieldsSkippedEmpty++;
                $fieldResults[] = [
                    'field' => $field,
                    'status' => 'skipped_empty',
                ];
                continue;
            }

            if (!$retranslateAll && $this->isFieldFullyTranslated($modelName, (int)$id, $field, $value)) {
                $fieldsSkippedDone++;
                $fieldResults[] = [
                    'field' => $field,
                    'status' => 'skipped_done',
                ];
                continue;
            }

            $result = $this->aiTranslate($modelName, $field, $id, $value, $retranslateAll);
            if (($result['success'] ?? false) !== true) {
                $errors[] = $field . ': ' . (string)($result['message'] ?? '');
                $fieldResults[] = [
                    'field' => $field,
                    'status' => 'failed',
                    'message' => (string)($result['message'] ?? ''),
                ];
                continue;
            }

            $translated = (int)($result['data']['translated'] ?? 0);
            $skipped = (int)($result['data']['skipped'] ?? 0);
            if ($translated > 0) {
                $fieldsTranslated++;
            } elseif (!$retranslateAll && $skipped > 0) {
                $fieldsSkippedDone++;
            }
            $totalTranslated += $translated;
            $totalSkippedLocales += $skipped;
            $fieldResults[] = [
                'field' => $field,
                'status' => $translated > 0 ? 'translated' : 'skipped_done',
                'translated' => $translated,
                'skipped' => $skipped,
            ];
            $errors = array_merge(
                $errors,
                is_array($result['data']['errors'] ?? null) ? $result['data']['errors'] : [],
            );
        }

        $errors = array_values(array_unique(array_filter($errors, static fn(mixed $item): bool => trim((string)$item) !== '')));

        if ($fieldsTranslated === 0) {
            if ($fieldsSkippedEmpty > 0 || $fieldsSkippedDone > 0) {
                return [
                    'success' => true,
                    'message' => (string)__('所有字段均已翻译或为空，无需补充'),
                    'data' => [
                        'fields_translated' => 0,
                        'fields_skipped_empty' => $fieldsSkippedEmpty,
                        'fields_skipped_done' => $fieldsSkippedDone,
                        'translated' => 0,
                        'skipped' => $totalSkippedLocales,
                        'fields' => $fieldResults,
                        'errors' => $errors,
                    ],
                ];
            }

            $message = $errors !== []
                ? (string)__('AI翻译调用失败')
                : (string)__('AI翻译未返回目标语言结果');
            return $this->error($message);
        }

        return [
            'success' => true,
            'message' => (string)__(
                'AI 批量翻译完成：%{1} 个字段，%{2} 个语言条目',
                [(string)$fieldsTranslated, (string)$totalTranslated],
            ),
            'data' => [
                'fields_translated' => $fieldsTranslated,
                'fields_skipped_empty' => $fieldsSkippedEmpty,
                'fields_skipped_done' => $fieldsSkippedDone,
                'translated' => $totalTranslated,
                'skipped' => $totalSkippedLocales,
                'fields' => $fieldResults,
                'errors' => $errors,
            ],
        ];
    }

    /**
     * 构建 local 标签一键批量翻译步骤（每组对应一个字段进度）。
     *
     * @param array<int|string, mixed> $fieldDefinitions
     * @return list<array{field:string,label:string,value:string,status:string}>
     */
    public function buildBulkTranslationSteps(
        string $modelName,
        int $recordId,
        array $fieldDefinitions,
        bool $retranslateAll = false,
    ): array {
        $modelName = trim($modelName);
        if ($modelName === '' || $recordId <= 0) {
            return [];
        }

        $steps = [];
        foreach ($this->normalizeBulkFieldDefinitions($fieldDefinitions) as $definition) {
            $field = trim((string)($definition['field'] ?? ''));
            $label = trim((string)($definition['label'] ?? $field));
            $value = trim((string)($definition['value'] ?? ''));
            if ($field === '' || str_starts_with($field, 'config.')) {
                continue;
            }

            if ($value === '') {
                $steps[] = [
                    'field' => $field,
                    'label' => $label !== '' ? $label : $field,
                    'value' => '',
                    'status' => 'skipped_empty',
                ];
                continue;
            }

            if (!$retranslateAll && $this->isFieldFullyTranslated($modelName, $recordId, $field, $value)) {
                $steps[] = [
                    'field' => $field,
                    'label' => $label !== '' ? $label : $field,
                    'value' => $value,
                    'status' => 'skipped_done',
                ];
                continue;
            }

            $steps[] = [
                'field' => $field,
                'label' => $label !== '' ? $label : $field,
                'value' => $value,
                'status' => 'pending',
            ];
        }

        return $steps;
    }

    /**
     * @param array<int|string, mixed> $fieldDefinitions
     * @return list<array{field:string,label:string,value:string}>
     */
    private function normalizeBulkFieldDefinitions(array $fieldDefinitions): array
    {
        $normalized = [];
        $isList = array_is_list($fieldDefinitions);
        if ($isList) {
            foreach ($fieldDefinitions as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $normalized[] = [
                    'field' => trim((string)($item['field'] ?? '')),
                    'label' => trim((string)($item['label'] ?? $item['field'] ?? '')),
                    'value' => trim(is_scalar($item['value'] ?? null) ? (string)$item['value'] : ''),
                ];
            }
            return $normalized;
        }

        foreach ($fieldDefinitions as $field => $value) {
            if (!is_string($field)) {
                continue;
            }
            $normalized[] = [
                'field' => trim($field),
                'label' => trim($field),
                'value' => trim(is_scalar($value) ? (string)$value : ''),
            ];
        }

        return $normalized;
    }

    private function isFieldFullyTranslated(string $modelName, int $recordId, string $field, string $sourceValue): bool
    {
        $sourceValue = trim($sourceValue);
        if ($sourceValue === '' || $recordId <= 0) {
            return true;
        }

        /** @var LocalModel $model */
        $model = ObjectManager::getInstance($modelName);
        $existingByCode = $this->loadExistingLocalRows($model, $recordId);
        $baseLocale = $this->resolveBaseLocale();
        $siteLocaleCodes = $this->resolveSiteLocaleCodes();
        $localeRows = [];
        foreach ($siteLocaleCodes as $code) {
            $stored = trim((string)(($existingByCode[$code][$field] ?? '')));
            $localeRows[] = [
                'local_code' => $code,
                'value' => $stored,
                'filled' => $stored !== '' && !I18nCsvCodec::isJunkTranslation($stored),
            ];
        }
        $effective = $this->resolveEffectiveSource($localeRows, $baseLocale, $sourceValue);
        $sourceLocale = trim((string)$effective['locale']);
        $effectiveText = trim((string)$effective['text']);
        if ($sourceLocale === '' || $effectiveText === '') {
            return false;
        }

        $targetLocales = array_values(array_filter(
            $siteLocaleCodes,
            static fn(string $locale): bool => strcasecmp($locale, $sourceLocale) !== 0,
        ));
        if ($targetLocales === []) {
            return true;
        }

        foreach ($targetLocales as $localeCode) {
            $existing = $existingByCode[$localeCode] ?? [];
            $stored = trim((string)($existing[$field] ?? ''));
            if (!$this->isRealTranslation($stored, $effectiveText)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param LocalModel $model
     * @return array<string, array<string, mixed>>
     */
    private function loadExistingLocalRows(LocalModel $model, int $recordId): array
    {
        if ($recordId <= 0) {
            return [];
        }

        $rows = $model->reset()
            ->where($model::schema_fields_ID, $recordId)
            ->select()
            ->fetchArray();
        if (!is_array($rows)) {
            return [];
        }

        $byCode = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string)($row[$model::schema_fields_local_code] ?? ''));
            if ($code !== '') {
                $byCode[$code] = $row;
            }
        }

        return $byCode;
    }

    private function isRealTranslation(string $stored, string $sourceText): bool
    {
        $stored = trim($stored);
        if ($stored === '' || I18nCsvCodec::isJunkTranslation($stored)) {
            return false;
        }

        $sourceText = trim($sourceText);

        return $sourceText === '' || $stored !== $sourceText;
    }

    /**
     * @param LocalModel $model
     */
    private function upsertFieldValue(
        LocalModel $model,
        int $recordId,
        string $localeCode,
        string $field,
        string $fieldValue,
    ): void {
        $localeCode = trim($localeCode);
        if ($localeCode === '' || $recordId <= 0 || $field === '') {
            return;
        }

        $model->reset()->insert([
            [
                $model::schema_fields_ID => $recordId,
                $model::schema_fields_local_code => $localeCode,
                $field => $fieldValue,
            ],
        ], $model::schema_fields_ID . ',local_code', $field)->fetch();
    }

    /**
     * 仅返回当前站点 WebsiteLanguage ∩ 已安装激活 Locale。
     * 禁止回退到全量已安装目录。
     *
     * @return list<array{code: string, name: string, native_name: string, flag: string}>
     */
    private function loadInstalledLocales(string $displayLocale, string $search): array
    {
        $siteCodes = $this->resolveSiteLocaleCodes();
        if ($siteCodes === []) {
            return [];
        }

        $allowedKeys = [];
        foreach ($siteCodes as $code) {
            $allowedKeys[strtolower($code)] = $code;
        }

        /** @var Locale $localeModel */
        $localeModel = ObjectManager::getInstance(Locale::class);
        $rows = $localeModel->reset()
            ->where(Locale::schema_fields_IS_ACTIVE, 1)
            ->where(Locale::schema_fields_IS_INSTALL, 1)
            ->order(Locale::schema_fields_CODE, 'ASC')
            ->select()
            ->fetchArray();
        if (!is_array($rows)) {
            return [];
        }

        $needle = mb_strtolower($search);
        $byCode = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string)($row[Locale::schema_fields_CODE] ?? ''));
            if ($code === '') {
                continue;
            }
            $key = strtolower(str_replace('-', '_', $code));
            if (!isset($allowedKeys[$key])) {
                continue;
            }

            $countryCode = trim((string)($row[Locale::schema_fields_COUNTRY_CODE] ?? ''));
            $flag = $countryCode !== ''
                ? (string)$this->i18n->getCountryFlag($countryCode)
                : trim((string)($row[Locale::schema_fields_FLAG] ?? ''));
            $name = trim((string)$this->i18n->getLocaleName($code, $displayLocale));
            $nativeName = trim((string)$this->i18n->getLocaleLanguageSelfName($code));

            if ($needle !== '') {
                $haystack = mb_strtolower(implode(' ', array_filter([$code, $name, $nativeName])));
                if (!str_contains($haystack, $needle)) {
                    continue;
                }
            }

            $byCode[$key] = [
                'code' => $allowedKeys[$key],
                'name' => $name !== '' ? $name : $code,
                'native_name' => $nativeName,
                'flag' => $flag,
            ];
        }

        $list = [];
        foreach ($siteCodes as $code) {
            $key = strtolower($code);
            if (isset($byCode[$key])) {
                $list[] = $byCode[$key];
            }
        }

        return $list;
    }

    /**
     * 网站默认语言作为指定母本；若不在站点关联语言中则回退 AI 配置 / 首个站点语言。
     */
    private function resolveBaseLocale(): string
    {
        $siteCodes = $this->resolveSiteLocaleCodes();
        $websiteDefault = $this->normalizeLocaleCode(
            $this->fetchWebsiteDefaultLanguage($this->resolveCurrentWebsiteId()),
        );
        if ($websiteDefault !== '') {
            foreach ($siteCodes as $code) {
                if (strcasecmp($code, $websiteDefault) === 0) {
                    return $code;
                }
            }
        }

        $aiSource = $this->normalizeLocaleCode($this->translationConfig->getSourceLocale());
        if ($aiSource !== '') {
            foreach ($siteCodes as $code) {
                if (strcasecmp($code, $aiSource) === 0) {
                    return $code;
                }
            }
        }

        return $siteCodes[0] ?? $aiSource;
    }

    /**
     * 有效翻译源：母本有内容优先；母本为空则取站点顺序中第一个已填语言（反哺）；再否则用表单传入值。
     *
     * @param list<array<string, mixed>> $locales
     * @return array{locale: string, text: string, fallback: bool}
     */
    private function resolveEffectiveSource(array $locales, string $baseLocale, string $fallbackValue): array
    {
        $fallbackValue = trim($fallbackValue);
        $baseLocale = trim($baseLocale);

        if ($baseLocale !== '') {
            foreach ($locales as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = trim((string)($row['local_code'] ?? ''));
                $text = trim((string)($row['value'] ?? ''));
                if ($code !== '' && strcasecmp($code, $baseLocale) === 0 && $text !== '' && !I18nCsvCodec::isJunkTranslation($text)) {
                    return ['locale' => $code, 'text' => $text, 'fallback' => false];
                }
            }
        }

        foreach ($locales as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string)($row['local_code'] ?? ''));
            $text = trim((string)($row['value'] ?? ''));
            if ($code !== '' && $text !== '' && !I18nCsvCodec::isJunkTranslation($text)) {
                $isFallback = $baseLocale === '' || strcasecmp($code, $baseLocale) !== 0;

                return ['locale' => $code, 'text' => $text, 'fallback' => $isFallback];
            }
        }

        if ($fallbackValue !== '') {
            $locale = $baseLocale;
            if ($locale === '') {
                foreach ($locales as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $code = trim((string)($row['local_code'] ?? ''));
                    if ($code !== '') {
                        $locale = $code;
                        break;
                    }
                }
            }

            return ['locale' => $locale, 'text' => $fallbackValue, 'fallback' => false];
        }

        return ['locale' => $baseLocale, 'text' => '', 'fallback' => false];
    }

    private function normalizeLocaleCode(string $code): string
    {
        return trim(str_replace('-', '_', $code));
    }

    private function fetchWebsiteDefaultLanguage(int $websiteId): string
    {
        try {
            $website = \w_query('websites', 'getWebsiteById', ['website_id' => $websiteId]);
            if (is_array($website)) {
                return (string)($website['default_language'] ?? '');
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /**
     * 当前站点支持且已安装激活的语言代码（保持网站关联顺序）。
     *
     * @return list<string>
     */
    private function resolveSiteLocaleCodes(): array
    {
        $websiteCodes = $this->normalizeLocaleCodeList(
            $this->fetchWebsiteLanguageCodes($this->resolveCurrentWebsiteId()),
        );
        if ($websiteCodes === []) {
            return [];
        }

        $installedMap = [];
        foreach ($this->translationConfig->getInstalledActiveLocaleCodes() as $code) {
            $normalized = trim(str_replace('-', '_', (string)$code));
            if ($normalized === '') {
                continue;
            }
            $installedMap[strtolower($normalized)] = $normalized;
        }

        $out = [];
        foreach ($websiteCodes as $code) {
            $key = strtolower($code);
            if (isset($installedMap[$key])) {
                $out[] = $installedMap[$key];
            }
        }

        return $out;
    }

    private function resolveCurrentWebsiteId(): int
    {
        try {
            $contextWebsiteId = \Weline\Framework\Runtime\RequestContext::getWelineWebsiteId();
            if ($contextWebsiteId !== null) {
                return max(0, (int)$contextWebsiteId);
            }
        } catch (\Throwable) {
        }

        try {
            /** @var \Weline\Framework\Http\Request $request */
            $request = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
            $raw = $request->getData('website_id');
            if ($raw !== null && $raw !== '') {
                return max(0, (int)$raw);
            }
        } catch (\Throwable) {
        }

        // 默认站 Website::ID_DEFAULT = 0
        return 0;
    }

    /**
     * @return list<string>
     */
    private function fetchWebsiteLanguageCodes(int $websiteId): array
    {
        try {
            $result = \w_query('websites', 'getWebsiteLanguageCodes', ['website_id' => $websiteId]);
            return is_array($result) ? $result : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param iterable<mixed> $codes
     * @return list<string>
     */
    private function normalizeLocaleCodeList(iterable $codes): array
    {
        $result = [];
        $seen = [];
        foreach ($codes as $code) {
            if (is_array($code) && isset($code['code'])) {
                $code = $code['code'];
            }
            if (!is_scalar($code)) {
                continue;
            }
            $normalized = trim(str_replace('-', '_', (string)$code));
            if ($normalized === '') {
                continue;
            }
            $key = strtolower($normalized);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $normalized;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function getNestedValue(array $data, string $path): mixed
    {
        if ($path === '') {
            return null;
        }
        $value = $data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return array{success: false, message: string, data: array<string, mixed>}
     */
    private function error(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
            'data' => [],
        ];
    }
}
