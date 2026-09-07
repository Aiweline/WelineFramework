<?php

declare(strict_types=1);

namespace Weline\FileManager\Api;

use Weline\FileManager\Api\Data\FileAccessContext;

/**
 * FileAssetLocale translation boundary: list locales, fill missing languages,
 * and module-level AI auto-translation control. Consumers never touch models.
 */
interface FileAssetLocaleTranslationInterface
{
    public function isAutoTranslationEnabled(): bool;

    public function setAutoTranslationEnabled(bool $enabled): void;

    /** @return list<string> */
    public function listInstalledLocaleCodes(): array;

    /**
     * @return list<array{
     *   locale_code:string,
     *   display_name:string,
     *   default_alt:string,
     *   description:string,
     *   default_caption:?string,
     *   translation_state:string,
     *   translation_origin:string,
     *   has_content:bool
     * }>
     */
    public function listLocales(string $assetId, FileAccessContext $access): array;

    /**
     * Fill missing locales from source. Does not consult auto-translation config
     * (upload one-click translate stays available when cron auto-fill is off).
     * Existing non-empty locale rows are skipped (gap-fill only).
     *
     * @param list<string>|null $targetLocales null = all installed active locales except source
     * @return array{filled:list<string>,skipped:list<string>,errors:list<string>}
     */
    public function translateMissing(
        string $assetId,
        string $sourceLocale,
        FileAccessContext $access,
        ?array $targetLocales = null,
    ): array;

    /** Cron path: enqueue only when auto-translation is enabled. Returns queue_id or 0. */
    public function enqueueAutoFill(string $requestedBy = 'cron', bool $force = false): int;

    /**
     * Queue worker: gap-fill a limited batch of assets that still need work.
     * Walks the READY catalog from $offset, skipping complete assets, and returns
     * next_offset as the catalog cursor for continuation.
     *
     * @return array{processed:int,filled:int,skipped:int,errors:list<string>,continuation:bool,next_offset:int,aborted_busy?:bool}
     */
    public function processPendingBatch(int $offset = 0, int $limit = 20): array;
}
