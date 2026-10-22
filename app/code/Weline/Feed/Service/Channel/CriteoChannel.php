<?php

declare(strict_types=1);

namespace Weline\Feed\Service\Channel;

use Weline\Feed\Api\Data\FeedConnectionMode;
use Weline\Feed\Api\Data\FeedDefinition;
use Weline\Feed\Api\Data\FeedItem;

final class CriteoChannel extends DelimitedCatalogChannel
{
    public function channelCode(): string
    {
        return 'criteo';
    }

    public function displayName(): string
    {
        return 'Criteo';
    }

    public function acceptedModes(): array
    {
        return [FeedConnectionMode::FEED_URL, FeedConnectionMode::API_KEY];
    }

    public function connectionDescription(): string
    {
        return '一期只有拉取地址或 api_key，不做同意页。';
    }

    protected function separator(): string
    {
        return ',';
    }

    protected function columns(): array
    {
        return ['id', 'title', 'description', 'link', 'image_link', 'price', 'availability'];
    }

    protected function extraRequired(FeedItem $item): array
    {
        return [$item->description];
    }

    protected function cell(FeedItem $item, FeedDefinition $definition, string $column): string
    {
        return match ($column) {
            'id' => $item->id,
            'title' => $item->title,
            'description' => $item->description,
            'link' => $item->link,
            'image_link' => $item->imageLink,
            'price' => $this->priced($item, $definition),
            'availability' => $item->availability,
            default => '',
        };
    }
}
