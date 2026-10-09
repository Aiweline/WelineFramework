<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Websites\Model\SalesChannel;

/**
 * Private reader for SalesChannel.url (not part of SalesChannelSummary v1).
 */
final class ScopeChannelUrlReader
{
    public function __construct(
        private readonly SalesChannel $channelModel,
    ) {
    }

    /**
     * @return array<int, string> channel_id => absolute storefront URL
     */
    public function urlsByStore(int $storeId): array
    {
        if ($storeId < 0) {
            return [];
        }
        $channel = clone $this->channelModel;
        $rows = $channel->clear()
            ->where(SalesChannel::schema_fields_STORE_ID, $storeId)
            ->where(SalesChannel::schema_fields_STATUS, 1)
            ->select()
            ->fetchArray();
        if (!is_array($rows)) {
            return [];
        }
        if (!array_is_list($rows) && isset($rows[SalesChannel::schema_fields_ID])) {
            $rows = [$rows];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int)($row[SalesChannel::schema_fields_ID] ?? 0);
            $url = trim((string)($row[SalesChannel::schema_fields_URL] ?? ''));
            if ($id > 0 && $url !== '') {
                $out[$id] = $url;
            }
        }

        return $out;
    }

    public function urlForChannel(int $channelId): ?string
    {
        if ($channelId < 0) {
            return null;
        }
        $channel = clone $this->channelModel;
        $channel->clear()->load($channelId);
        if ((int)$channel->getData(SalesChannel::schema_fields_ID) !== $channelId) {
            return null;
        }
        $url = trim((string)$channel->getData(SalesChannel::schema_fields_URL));

        return $url !== '' ? $url : null;
    }
}
