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
                    $batch = $this->translationAdapter->translateBatch(
                        [$sourceText],
                        $sourceLocale,
                        $targetLocale,
                        $this->translationConfig->getStrategy($targetLocale),
                    );
                    if (!$batch['success']) {
                        $itemErrors = array_map('strval', (array)($batch['errors'] ?? []));
                        $errors = array_merge($errors, $itemErrors);
                        // Busy/hard AI error: stop this round; next cron continues.
                        if ($this->errorsIndicateBusy($itemErrors)) {
                            break 2;
                        }
                        break 2;
                    }
                    $translation = trim((string)($batch['translations'][$sourceText] ?? ''));
                    if ($translation === '') {
                        $skipped++;
                        continue;
                    }
                    if (MetaTranslation::setTranslatedValue($metaIdentify, $configKey, $targetLocale, $translation)) {
                        $translated++;
                    } else {
                        $errors[] = $metaIdentify . '.' . $configKey;
                    }
                } catch (\Throwable $throwable) {
                    $message = $throwable->getMessage();
                    $errors[] = $metaIdentify . '.' . $configKey . ': ' . $message;
                    if (str_contains($message, 'AI_TRANSLATION_BUSY')) {
                        break 2;
                    }
                    break 2;
                }

                $processed++;
            }
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
