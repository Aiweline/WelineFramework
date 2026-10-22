<?php

declare(strict_types=1);

namespace Weline\Feed\Service\Channel;

use Weline\Feed\Api\Data\FeedConnectionMode;
use Weline\Feed\Api\Data\FeedDefinition;
use Weline\Feed\Api\Data\FeedItem;

/**
 * Naver Shopping EP. Shipping is one account-level number. Unset shipping is 0 plus a diagnostic.
 */
final class NaverShoppingChannel extends DelimitedCatalogChannel
{
    public const SHIPPING_UNCONFIGURED = 'naver_shipping_unconfigured';

    public function channelCode(): string
    {
        return 'naver_shopping';
    }

    public function displayName(): string
    {
        return 'Naver Shopping';
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
        return "\t";
    }

    protected function columns(): array
    {
        return ['id', 'title', 'price_pc', 'link', 'image_link', 'category_name1', 'shipping'];
    }

    protected function documentDiagnostic(FeedDefinition $definition, string $diagnostic): string
    {
        if ($definition->accountShipping() === null) {
            return self::SHIPPING_UNCONFIGURED;
        }

        return $diagnostic;
    }

    protected function cell(FeedItem $item, FeedDefinition $definition, string $column): string
    {
        $currency = trim($item->currency) !== '' ? $item->currency : $definition->currency;

        return match ($column) {
            'id' => $item->id,
            'title' => $item->title,
            'price_pc' => $this->money($item->priceMinor, $currency, false),
            'link' => $item->link,
            'image_link' => $item->imageLink,
            'category_name1' => $this->categoryName($item, $definition),
            'shipping' => $definition->accountShipping() ?? '0',
            default => '',
        };
    }

    private function categoryName(FeedItem $item, FeedDefinition $definition): string
    {
        if ($item->id === 'sample-offer') {
            return trim($definition->title) !== '' ? trim($definition->title) : 'Catalog';
        }
        $value = trim((string)$item->channelCategory);
        if ($value === '' || $value === '杂项' || strcasecmp($value, 'other') === 0 || $value === '0') {
            return '';
        }

        return $value;
    }
}
