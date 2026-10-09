<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;

/**
 * Resolves effective display_type: channel → store → website (first non-empty).
 * Unknown codes fail soft to empty. Frozen value lives on a RequestContext private key.
 */
final class ScopeDisplayTypeResolver
{
    public const REQUEST_PRIVATE_KEY = 'runtime.request_context.display_type';

    public function __construct(
        private readonly ScopeDisplayTypeRegistry $registry,
        private readonly Website $websiteModel,
        private readonly Store $storeModel,
        private readonly SalesChannel $channelModel,
    ) {
    }

    public function resolveForScope(int $websiteId, int $storeId, int $channelId): string
    {
        if ($websiteId < 0 || $storeId < 0 || $channelId < 0) {
            return '';
        }

        $candidates = [
            $this->readChannelDisplayType($channelId),
            $this->readStoreDisplayType($storeId),
            $this->readWebsiteDisplayType($websiteId),
        ];
        foreach ($candidates as $code) {
            if ($code === '') {
                continue;
            }
            if (!$this->registry->isKnownCode($code)) {
                continue;
            }

            return $code;
        }

        return '';
    }

    public function currentEffectiveDisplayType(): string
    {
        if (RequestContext::isInitialized()) {
            $frozen = RequestContext::get(self::REQUEST_PRIVATE_KEY);
            if (is_string($frozen)) {
                return $frozen;
            }
        }

        $websiteId = RequestContext::getWelineWebsiteId();
        $storeId = RequestContext::getWelineStoreId();
        $channelId = RequestContext::getWelineChannelId();
        if ($websiteId < 0) {
            return '';
        }

        return $this->resolveForScope($websiteId, max(0, $storeId), max(0, $channelId));
    }

    public function freezeCurrentFromRequestContext(): void
    {
        if (!RequestContext::isInitialized()) {
            return;
        }
        $websiteId = RequestContext::getWelineWebsiteId();
        $storeId = RequestContext::getWelineStoreId();
        $channelId = RequestContext::getWelineChannelId();
        if ($websiteId < 0) {
            RequestContext::set(self::REQUEST_PRIVATE_KEY, '');

            return;
        }
        RequestContext::set(
            self::REQUEST_PRIVATE_KEY,
            $this->resolveForScope($websiteId, max(0, $storeId), max(0, $channelId)),
        );
    }

    public function normalizeAssignedCode(?string $raw): string
    {
        $code = strtolower(trim((string)$raw));
        if ($code === '') {
            return '';
        }
        if (!$this->registry->isKnownCode($code)) {
            throw new \InvalidArgumentException(__('未知的展示类型：%{1}', [$code]));
        }

        return $code;
    }

    private function readChannelDisplayType(int $channelId): string
    {
        $channel = clone $this->channelModel;
        $rows = $channel->clear()
            ->where(SalesChannel::schema_fields_ID, $channelId)
            ->select()
            ->fetchArray();
        $row = $this->firstRow($rows);
        if ($row === null || (int)($row[SalesChannel::schema_fields_ID] ?? -1) !== $channelId) {
            return '';
        }

        return strtolower(trim((string)($row[SalesChannel::schema_fields_DISPLAY_TYPE] ?? '')));
    }

    private function readStoreDisplayType(int $storeId): string
    {
        $store = clone $this->storeModel;
        $rows = $store->clear()
            ->where(Store::schema_fields_ID, $storeId)
            ->select()
            ->fetchArray();
        $row = $this->firstRow($rows);
        if ($row === null || (int)($row[Store::schema_fields_ID] ?? -1) !== $storeId) {
            return '';
        }

        return strtolower(trim((string)($row[Store::schema_fields_DISPLAY_TYPE] ?? '')));
    }

    private function readWebsiteDisplayType(int $websiteId): string
    {
        $website = clone $this->websiteModel;
        $rows = $website->clear()
            ->where(Website::schema_fields_ID, $websiteId)
            ->select()
            ->fetchArray();
        $row = $this->firstRow($rows);
        if ($row === null) {
            return '';
        }
        $loadedId = (int)($row[Website::schema_fields_ID] ?? -1);
        if ($loadedId !== $websiteId
            && !($websiteId === Website::ID_DEFAULT && trim((string)($row[Website::schema_fields_CODE] ?? '')) !== '')
        ) {
            return '';
        }

        return strtolower(trim((string)($row[Website::schema_fields_DISPLAY_TYPE] ?? '')));
    }

    /** @param mixed $rows */
    private function firstRow(mixed $rows): ?array
    {
        if (!is_array($rows) || $rows === []) {
            return null;
        }
        if (!array_is_list($rows)) {
            return isset($rows[SalesChannel::schema_fields_ID]) || isset($rows[Store::schema_fields_ID]) || isset($rows[Website::schema_fields_ID])
                ? $rows
                : null;
        }
        $first = $rows[0] ?? null;

        return is_array($first) ? $first : null;
    }

    public static function instance(): self
    {
        return ObjectManager::getInstance(self::class);
    }
}
