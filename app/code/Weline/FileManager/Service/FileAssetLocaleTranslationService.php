<?php

declare(strict_types=1);

namespace Weline\FileManager\Service;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLocaleTranslationInterface;
use Weline\FileManager\Model\FileAsset;
use Weline\FileManager\Model\FileAssetLocale;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;

class FileAssetLocaleTranslationService implements FileAssetLocaleTranslationInterface
{
    private const TRANSLATE_FIELDS = ['display_name', 'default_alt', 'description', 'default_caption'];

    public function __construct(
        private readonly FileAsset $assets,
        private readonly FileAssetLocale $locales,
        private readonly FileAssetManager $assetManager,
        private readonly FileAccessPolicy $accessPolicy,
        private readonly FileAssetUploadService $uploads,
        private readonly FileAssetLocaleTranslationConfig $config,
        private readonly FileAssetLocaleTranslationQueueService $queueService,
        private readonly EventsManager $eventsManager,
    ) {
    }

    public function isAutoTranslationEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    public function setAutoTranslationEnabled(bool $enabled): void
    {
        $this->config->setEnabled($enabled);
    }

    public function listInstalledLocaleCodes(): array
    {
        return $this->installedActiveLocaleCodes();
    }

    public function listLocales(string $assetId, FileAccessContext $access): array
    {
        $asset = $this->assetManager->get($assetId);
        $this->accessPolicy->assertCanManage($asset, $access);
        $rows = clone $this->locales;
        $items = array_values($rows->clearData()->reset()
            ->where(FileAssetLocale::schema_fields_ASSET_ID, $asset->getAssetId())
            ->select()
            ->fetch()
            ->getItems());
        $out = [];
        foreach ($items as $row) {
            if (!$row instanceof FileAssetLocale) {
                continue;
            }
            $displayName = trim((string)$row->getData(FileAssetLocale::schema_fields_DISPLAY_NAME));
            $defaultAlt = trim((string)$row->getData(FileAssetLocale::schema_fields_DEFAULT_ALT));
            $description = trim((string)$row->getData(FileAssetLocale::schema_fields_DESCRIPTION));
            $caption = $row->getData(FileAssetLocale::schema_fields_DEFAULT_CAPTION);
            $caption = $caption === null ? null : trim((string)$caption);
            $out[] = [
                'locale_code' => (string)$row->getData(FileAssetLocale::schema_fields_LOCALE_CODE),
                'display_name' => $displayName,
                'default_alt' => $defaultAlt,
                'description' => $description,
                'default_caption' => $caption === '' ? null : $caption,
                'translation_state' => (string)$row->getData(FileAssetLocale::schema_fields_TRANSLATION_STATE),
                'translation_origin' => (string)$row->getData(FileAssetLocale::schema_fields_TRANSLATION_ORIGIN),
                'has_content' => $this->hasContent($displayName, $defaultAlt, $description),
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['locale_code'], $b['locale_code']));

        return $out;
    }

    public function translateMissing(
        string $assetId,
        string $sourceLocale,
        FileAccessContext $access,
        ?array $targetLocales = null,
    ): array {
        $asset = $this->assetManager->get($assetId);
        $this->accessPolicy->assertCanManage($asset, $access);
        $sourceLocale = FileAssetManager::normalizeLocale($sourceLocale);
        $source = $this->findLocale($assetId, $sourceLocale);
        if ($source === null || !$this->localeHasContent($source)) {
            return [
                'filled' => [],
                'skipped' => [],
                'errors' => [(string)__('源语言元数据缺失，无法补缺翻译。')],
            ];
        }

        $sourceTexts = [
            'display_name' => trim((string)$source->getData(FileAssetLocale::schema_fields_DISPLAY_NAME)),
            'default_alt' => trim((string)$source->getData(FileAssetLocale::schema_fields_DEFAULT_ALT)),
            'description' => trim((string)$source->getData(FileAssetLocale::schema_fields_DESCRIPTION)),
            'default_caption' => trim((string)($source->getData(FileAssetLocale::schema_fields_DEFAULT_CAPTION) ?? '')),
        ];
        $targets = $targetLocales ?? $this->resolveTargetLocales($sourceLocale);
        $filled = [];
        $skipped = [];
        $errors = [];

        foreach ($targets as $targetLocale) {
            $targetLocale = trim((string)$targetLocale);
            if ($targetLocale === '' || $targetLocale === $sourceLocale) {
                continue;
            }
            try {
                $targetLocale = FileAssetManager::normalizeLocale($targetLocale);
            } catch (\Throwable $throwable) {
                $errors[] = $targetLocale . ': ' . $throwable->getMessage();
                continue;
            }
            $existing = $this->findLocale($assetId, $targetLocale);
            if ($existing !== null && $this->localeHasContent($existing)) {
                $skipped[] = $targetLocale;
                continue;
            }
            $translated = $this->translateFieldMap($sourceTexts, $sourceLocale, $targetLocale);
            if ($translated === null) {
                $errors[] = (string)__('语言 %{1} 翻译失败或 AI 翻译不可用。', [$targetLocale]);
                continue;
            }
            if (!$this->hasContent(
                $translated['display_name'],
                $translated['default_alt'],
                $translated['description'],
            )) {
                $errors[] = (string)__('语言 %{1} 翻译结果为空。', [$targetLocale]);
                continue;
            }
            $this->uploads->saveLocale($asset, $targetLocale, [
                'display_name' => $translated['display_name'],
                'default_alt' => $translated['default_alt'],
                'description' => $translated['description'],
                'default_caption' => $translated['default_caption'] !== '' ? $translated['default_caption'] : null,
                'translation_state' => FileAssetLocale::STATE_DRAFT,
                'translation_origin' => FileAssetLocale::ORIGIN_MACHINE,
            ]);
            $filled[] = $targetLocale;
        }

        return [
            'filled' => array_values(array_unique($filled)),
            'skipped' => array_values(array_unique($skipped)),
            'errors' => array_values(array_unique($errors)),
        ];
    }

    public function enqueueAutoFill(string $requestedBy = 'cron', bool $force = false): int
    {
        if (!$this->config->isEnabled()) {
            return 0;
        }

        if (!$force) {
            $activeId = $this->queueService->findActiveFamilyQueueId();
            if ($activeId > 0) {
                return $activeId;
            }
            // No AI calls here: only metadata gap probe. Avoid hourly empty scans.
            if (!$this->hasGapFillWork()) {
                return 0;
            }
        }

        return $this->queueService->enqueue($requestedBy, $force);
    }

    /**
     * True when at least one READY asset has a missing (empty) target locale row.
     * Does not call the AI translator.
     */
    public function hasGapFillWork(int $pageSize = 50): bool
    {
        $pageSize = max(1, min(100, $pageSize));
        $offset = 0;
        while (true) {
            $query = clone $this->assets;
            $items = array_values($query->clearData()->reset()
                ->where(FileAsset::schema_fields_LIFECYCLE_STATE, FileAsset::STATE_READY)
                ->order(FileAsset::schema_fields_UPDATED_AT, 'ASC')
                ->limit($pageSize, $offset)
                ->select()
                ->fetch()
                ->getItems());
            if ($items === []) {
                return false;
            }
            foreach ($items as $asset) {
                if (!$asset instanceof FileAsset || $asset->getAssetId() === '' || $asset->isDeleted()) {
                    continue;
                }
                if ($this->assetNeedsGapFill($asset)) {
                    return true;
                }
            }
            if (count($items) < $pageSize) {
                return false;
            }
            $offset += $pageSize;
        }
    }

    private function assetNeedsGapFill(FileAsset $asset): bool
    {
        try {
            $sourceLocale = FileAssetManager::normalizeLocale($asset->getDefaultLocale());
        } catch (\Throwable) {
            return false;
        }
        $source = $this->findLocale($asset->getAssetId(), $sourceLocale);
        if ($source === null || !$this->localeHasContent($source)) {
            return false;
        }
        foreach ($this->resolveTargetLocales($sourceLocale) as $targetLocale) {
            $existing = $this->findLocale($asset->getAssetId(), $targetLocale);
            if ($existing === null || !$this->localeHasContent($existing)) {
                return true;
            }
        }

        return false;
    }

    public function processPendingBatch(int $offset = 0, int $limit = 20): array
    {
        $offset = max(0, $offset);
        $limit = max(1, min(100, $limit));
        // Walk past already-complete assets; only AI-call gap assets up to $limit.
        // Otherwise early pages are all skipped (filled=0) while real gaps sit deeper.
        $scanPage = max($limit, 50);
        $maxScan = max($scanPage, min(1000, $limit * 50));
        $cursor = $offset;
        $scanned = 0;
        $processed = 0;
        $filled = 0;
        $skipped = 0;
        $errors = [];
        $exhausted = false;
        $abortedBusy = false;

        while ($processed < $limit && $scanned < $maxScan) {
            $query = clone $this->assets;
            $items = array_values($query->clearData()->reset()
                ->where(FileAsset::schema_fields_LIFECYCLE_STATE, FileAsset::STATE_READY)
                ->order(FileAsset::schema_fields_UPDATED_AT, 'ASC')
                ->limit($scanPage, $cursor)
                ->select()
                ->fetch()
                ->getItems());
            if ($items === []) {
                $exhausted = true;
                break;
            }
            foreach ($items as $asset) {
                $scanned++;
                $cursor++;
                if (!$asset instanceof FileAsset || $asset->getAssetId() === '' || $asset->isDeleted()) {
                    continue;
                }
                if (!$this->assetNeedsGapFill($asset)) {
                    continue;
                }
                $processed++;
                $sourceLocale = $asset->getDefaultLocale();
                $access = new FileAccessContext(
                    ScopeIdentity::global(),
                    $sourceLocale,
                    null,
                    [],
                    'metadata_edit',
                );
                try {
                    $result = $this->translateMissing($asset->getAssetId(), $sourceLocale, $access, null);
                    $filled += count($result['filled']);
                    $skipped += count($result['skipped']);
                    $errors = array_merge($errors, $result['errors']);
                    if ($this->errorsIndicateBusy($result['errors'])) {
                        // Retry this asset later — do not keep hammering a saturated model.
                        $cursor--;
                        $processed--;
                        $abortedBusy = true;
                        break 2;
                    }
                } catch (\Throwable $throwable) {
                    $message = $throwable->getMessage();
                    $errors[] = $asset->getAssetId() . ': ' . $message;
                    if (str_contains($message, 'AI_TRANSLATION_BUSY')) {
                        $cursor--;
                        $processed--;
                        $abortedBusy = true;
                        break 2;
                    }
                }
                if ($processed >= $limit) {
                    break;
                }
            }
            if (count($items) < $scanPage) {
                $exhausted = true;
                break;
            }
        }

        return [
            'processed' => $processed,
            'filled' => $filled,
            'skipped' => $skipped,
            'errors' => array_values(array_unique($errors)),
            // next_offset is the catalog cursor after this walk (skips included).
            'next_offset' => $cursor,
            'aborted_busy' => $abortedBusy,
            // Continue while catalog remains, even if this window found 0 gaps
            // (maxScan cap) so the chain can reach deeper gap assets.
            // Busy/error abort: stop this round — next cron continues when free.
            'continuation' => !$abortedBusy && !$exhausted,
        ];
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

    /**
     * @param array{display_name:string,default_alt:string,description:string,default_caption:string} $sourceTexts
     * @return array{display_name:string,default_alt:string,description:string,default_caption:string}|null
     */
    private function translateFieldMap(array $sourceTexts, string $sourceLocale, string $targetLocale): ?array
    {
        $words = [];
        foreach (self::TRANSLATE_FIELDS as $field) {
            $value = trim((string)($sourceTexts[$field] ?? ''));
            if ($value !== '') {
                $words[$value] = $value;
            }
        }
        if ($words === []) {
            return null;
        }
        $eventData = [
            'words' => $words,
            'target_locale' => $targetLocale,
            'source_locale' => $sourceLocale,
            'strategy' => 'light',
            'translations' => [],
            'errors' => [],
            'success' => false,
        ];
        try {
            $this->eventsManager->dispatch('Weline_I18n::machine_translate', $eventData);
        } catch (\Throwable) {
            return null;
        }
        foreach ((array)($eventData['errors'] ?? []) as $error) {
            if (str_contains((string)$error, 'AI_TRANSLATION_BUSY')) {
                // Surface busy to the batch walker so it can stop instead of waiting per locale.
                throw new \RuntimeException((string)$error);
            }
        }
        if (empty($eventData['success']) || !is_array($eventData['translations'] ?? null)) {
            return null;
        }
        $map = $eventData['translations'];
        $out = [];
        foreach (self::TRANSLATE_FIELDS as $field) {
            $source = trim((string)($sourceTexts[$field] ?? ''));
            $out[$field] = $source === '' ? '' : trim((string)($map[$source] ?? ''));
        }

        return $out;
    }

    /** @return list<string> */
    private function resolveTargetLocales(string $sourceLocale): array
    {
        $targets = [];
        foreach ($this->installedActiveLocaleCodes() as $code) {
            if ($code !== $sourceLocale) {
                $targets[] = $code;
            }
        }

        return $targets;
    }

    /** @return list<string> */
    private function installedActiveLocaleCodes(): array
    {
        $localeClass = 'Weline\\I18n\\Model\\Locale';
        if (!class_exists($localeClass)) {
            return [];
        }
        try {
            $locale = ObjectManager::getInstance($localeClass);
            $rows = $locale->clear()
                ->where($localeClass::schema_fields_IS_INSTALL, 1)
                ->where($localeClass::schema_fields_IS_ACTIVE, 1)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }
        $codes = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $code = trim((string)($row[$localeClass::schema_fields_CODE] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    private function findLocale(string $assetId, string $localeCode): ?FileAssetLocale
    {
        $locale = clone $this->locales;
        $locale->clearData()->reset()
            ->where(FileAssetLocale::schema_fields_ASSET_ID, $assetId)
            ->where(FileAssetLocale::schema_fields_LOCALE_CODE, $localeCode)
            ->find()->fetch();

        return (int)$locale->getData(FileAssetLocale::schema_fields_ID) > 0 ? $locale : null;
    }

    private function localeHasContent(FileAssetLocale $locale): bool
    {
        return $this->hasContent(
            trim((string)$locale->getData(FileAssetLocale::schema_fields_DISPLAY_NAME)),
            trim((string)$locale->getData(FileAssetLocale::schema_fields_DEFAULT_ALT)),
            trim((string)$locale->getData(FileAssetLocale::schema_fields_DESCRIPTION)),
        );
    }

    private function hasContent(string $displayName, string $defaultAlt, string $description): bool
    {
        return $displayName !== '' || $defaultAlt !== '' || $description !== '';
    }
}
