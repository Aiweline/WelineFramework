<?php

declare(strict_types=1);

namespace Weline\Meta\Service;

use Weline\I18n\Service\AiTranslationConfig;
use Weline\I18n\Service\I18nAiTranslationAdapter;
use Weline\Meta\Helper\MetaTranslation;
use Weline\Meta\Model\MetaLocal;
use Weline\Meta\Service\MetaLocalWordParser;

/**
 * MetaLocal（w_meta_local）批量 AI 翻译；覆盖全部 config_key / scope / version_id。
 * 不走 dictionary_translate / 词典 meta 前缀。
 */
final class MetaLocalTranslationService
{
    private const BATCH_LIMIT = 50;

    public function __construct(
        private readonly I18nAiTranslationAdapter $translationAdapter,
        private readonly AiTranslationConfig $translationConfig,
        private readonly MetaLocal $metaLocal,
    ) {
    }

    /**
     * @return array{translated: int, skipped: int, errors: list<string>}
     */
    public function batchTranslateEnabledLocales(): array
    {
        if (!$this->translationConfig->isEnabled()) {
            return ['translated' => 0, 'skipped' => 0, 'errors' => []];
        }

        $sourceLocale = $this->translationConfig->getSourceLocale();
        $translated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($this->translationConfig->getEnabledLocaleCodes() as $targetLocale) {
            if ($targetLocale === $sourceLocale) {
                continue;
            }
            $result = $this->batchTranslateLocale($targetLocale, $sourceLocale);
            $translated += (int)($result['translated'] ?? 0);
            $skipped += (int)($result['skipped'] ?? 0);
            $errors = array_merge($errors, (array)($result['errors'] ?? []));
            foreach ((array)($result['errors'] ?? []) as $error) {
                if (str_contains((string)$error, 'AI_TRANSLATION_BUSY')) {
                    return ['translated' => $translated, 'skipped' => $skipped, 'errors' => $errors];
                }
            }
        }

        return ['translated' => $translated, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @return array{translated: int, skipped: int, errors: list<string>}
     */
    public function batchTranslateLocale(string $targetLocale, ?string $sourceLocale = null): array
    {
        $sourceLocale = $sourceLocale ?? $this->translationConfig->getSourceLocale();
        $targetLocale = trim(str_replace('-', '_', $targetLocale));
        $sourceLocale = trim(str_replace('-', '_', $sourceLocale));
        if ($targetLocale === '' || $targetLocale === $sourceLocale) {
            return ['translated' => 0, 'skipped' => 0, 'errors' => []];
        }

        $translated = 0;
        $skipped = 0;
        $errors = [];
        $processed = 0;

        $rows = $this->metaLocal->reset()
            ->where(MetaLocal::schema_fields_LOCALE_CODE, $sourceLocale)
            ->limit(self::BATCH_LIMIT * 4)
            ->select()
            ->fetchArray();

        foreach ($rows as $row) {
            if ($processed >= self::BATCH_LIMIT) {
                break;
            }

            $metaIdentify = trim((string)($row[MetaLocal::schema_fields_META_IDENTIFY] ?? ''));
            $configKey = trim((string)($row[MetaLocal::schema_fields_CONFIG_KEY] ?? ''));
            $scope = trim((string)($row[MetaLocal::schema_fields_SCOPE] ?? MetaLocal::DEFAULT_SCOPE));
            $versionId = (int)($row[MetaLocal::schema_fields_VERSION_ID] ?? MetaLocal::DEFAULT_VERSION_ID);
            $sourceText = trim((string)($row[MetaLocal::schema_fields_CONFIG_VALUE] ?? ''));
            if ($metaIdentify === '' || $configKey === '' || $sourceText === '') {
                $skipped++;
                continue;
            }
            if ($scope === '') {
                $scope = MetaLocal::DEFAULT_SCOPE;
            }
            if (MetaLocalWordParser::isPreviewScope($scope)) {
                $skipped++;
                continue;
            }

            $existing = MetaTranslation::getTranslatedValue(
                $metaIdentify,
                $configKey,
                $targetLocale,
                '',
                $scope,
                $versionId,
            );
            if ($existing !== '' && $existing !== $sourceText) {
                $skipped++;
                continue;
            }

            try {
                $batch = $this->translationAdapter->translateBatch(
                    [$sourceText],
                    $sourceLocale,
                    $targetLocale,
                    $this->translationConfig->getStrategy($targetLocale),
                    'meta',
                );
                if (!$batch['success']) {
                    $itemErrors = array_map('strval', (array)($batch['errors'] ?? []));
                    $errors = array_merge($errors, $itemErrors);
                    if ($this->errorsIndicateBusy($itemErrors)) {
                        break;
                    }
                    break;
                }
                $translation = trim((string)($batch['translations'][$sourceText] ?? ''));
                if ($translation === '') {
                    $skipped++;
                    continue;
                }
                if (MetaTranslation::setTranslatedValue(
                    $metaIdentify,
                    $configKey,
                    $targetLocale,
                    $translation,
                    $scope,
                    $versionId,
                )) {
                    $translated++;
                } else {
                    $errors[] = $metaIdentify . '.' . $configKey . '@' . $scope . '/v' . $versionId;
                }
            } catch (\Throwable $throwable) {
                $message = $throwable->getMessage();
                $errors[] = $metaIdentify . '.' . $configKey . ': ' . $message;
                if (str_contains($message, 'AI_TRANSLATION_BUSY')) {
                    break;
                }
                break;
            }

            $processed++;
        }

        return ['translated' => $translated, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @param list<string> $errors
     */
    private function errorsIndicateBusy(array $errors): bool
    {
        foreach ($errors as $error) {
            if (str_contains((string)$error, 'AI_TRANSLATION_BUSY')) {
                return true;
            }
        }

        return false;
    }
}
