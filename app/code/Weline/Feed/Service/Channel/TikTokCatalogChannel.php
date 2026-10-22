<?php

declare(strict_types=1);

namespace Weline\Feed\Service\Channel;

use Weline\Feed\Api\Data\FeedConnectionMode;
use Weline\Feed\Api\Data\FeedDefinition;
use Weline\Feed\Api\Data\FeedItem;

final class TikTokCatalogChannel extends DelimitedCatalogChannel
{
    public function channelCode(): string
    {
        return 'tiktok_catalog';
    }

    public function displayName(): string
    {
        return 'TikTok Catalog';
    }

    public function acceptedModes(): array
    {
        return [FeedConnectionMode::FEED_URL, FeedConnectionMode::OAUTH];
    }

    public function connectionDescription(): string
    {
        return 'oauth 同意页连接；公开文档走 feed_url。本地文件不是已授权。';
    }

    protected function separator(): string
    {
        return ',';
    }

    protected function columns(): array
    {
        return ['sku_id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'brand'];
    }

    protected function extraRequired(FeedItem $item): array
    {
        return [$item->description, $item->brand];
    }

    protected function cell(FeedItem $item, FeedDefinition $definition, string $column): string
    {
        return match ($column) {
            'sku_id' => $item->id,
            'title' => $item->title,
            'description' => $item->description,
            'availability' => $item->availability,
            'condition' => 'new',
            'price' => $this->priced($item, $definition),
            'link' => $item->link,
            'image_link' => $item->imageLink,
            'brand' => $item->brand,
            default => '',
        };
    }
}
