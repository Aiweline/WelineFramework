<?php

declare(strict_types=1);

namespace Weline\Meta\Service;

use Weline\Framework\App\Env;
use Weline\I18n\Service\AiTranslationConfig;
use Weline\I18n\Service\I18nAiTranslationAdapter;
use Weline\Meta\Helper\MetaTranslation;
use Weline\Meta\Model\Meta;
use Weline\Meta\Model\MetaLocal;

/**
 * MetaLocal（w_meta_local）批量 AI 翻译；不走 dictionary_translate。
 */
final class MetaLocalTranslationService
{
    private const BATCH_LIMIT = 50;

    public function __construct(
        private readonly I18nAiTranslationAdapter $translationAdapter,
        private readonly AiTranslationConfig $translationConfig,
        private readonly Meta $meta,
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

        foreach ($this->meta->reset()->select()->fetchArray() as $row) {
            $metaIdentify = trim((string)($row[Meta::schema_fields_META_IDENTIFY] ?? ''));
            if ($metaIdentify === '') {
                continue;
            }

            foreach (['name', 'description'] as $configKey) {
                if ($processed >= self::BATCH_LIMIT) {
                    break 2;
                }

                $sourceText = MetaTranslation::getTranslatedValue($metaIdentify, $configKey, $sourceLocale, '');
                if ($sourceText === '') {
                    $skipped++;
                    continue;
                }

                $existing = MetaTranslation::getTranslatedValue($metaIdentify, $configKey, $targetLocale, '');
                if ($existing !== '' && $existing !== $sourceText) {
                    $skipped++;
                    continue;
                }

                try {
                    $translation = $this->translationAdapter->translate($sourceText, $targetLocale, $sourceLocale);
                    if (!is_string($translation) || trim($translation) === '') {
                        $skipped++;
                        continue;
                    }
                    if (MetaTranslation::setTranslatedValue($metaIdentify, $configKey, $targetLocale, trim($translation))) {
                        $translated++;
                    } else {
                        $errors[] = $metaIdentify . '.' . $configKey;
                    }
                } catch (\Throwable $throwable) {
                    $errors[] = $metaIdentify . '.' . $configKey . ': ' . $throwable->getMessage();
                }

                $processed++;
            }
        }

        return ['translated' => $translated, 'skipped' => $skipped, 'errors' => $errors];
    }
}
