<?php

declare(strict_types=1);

namespace Weline\Feed\Service\Channel;

use Weline\Feed\Api\Data\FeedConnectionMode;
use Weline\Feed\Api\Data\FeedDefinition;
use Weline\Feed\Api\Data\FeedItem;

/**
 * Yandex Direct product feed. The code stays yandex_market. Consent without a remote receipt is not authorization.
 */
final class YandexMarketChannel extends AbstractFeedChannel
{
    public function channelCode(): string
    {
        return 'yandex_market';
    }

    public function displayName(): string
    {
        return 'Yandex Direct 商品 Feed（YML）';
    }

    public function acceptedModes(): array
    {
        return [FeedConnectionMode::FEED_URL, FeedConnectionMode::OAUTH];
    }

    public function connectionDescription(): string
    {
        return '有同意页。没有远端回执不算已授权。';
    }

    public function contentType(): string
    {
        return 'application/xml; charset=utf-8';
    }

    public function filenameSuffix(): string
    {
        return 'xml';
    }

    protected function build(FeedDefinition $definition, array $rows): string
    {
        $label = $this->displayName();
        $currency = strtoupper(trim($definition->currency) !== '' ? $definition->currency : 'USD');
        $home = $rows === [] ? 'https://shop.example/' : $this->origin($rows[0]->link);
        $categories = '';
        $offers = '';
        $seen = [];
        foreach ($rows as $row) {
            $categoryId = $this->categoryId($row);
            if ($categoryId === '') {
                continue;
            }
            if (!isset($seen[$categoryId])) {
                $categories .= '<category id="' . $this->xml($categoryId) . '">'
                    . $this->xml($this->categoryName($row, $label))
                    . '</category>';
                $seen[$categoryId] = true;
            }
            $offers .= $this->offerXml($definition, $row, $currency, $categoryId);
        }
        $date = date('Y-m-d H:i');

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<yml_catalog date="' . $this->xml($date) . '">'
            . '<shop>'
            . '<name>' . $this->xml($label) . '</name>'
            . '<company>' . $this->xml($label) . '</company>'
            . '<url>' . $this->xml($home) . '</url>'
            . '<currencies><currency id="' . $this->xml($currency) . '" rate="1"/></currencies>'
            . '<categories>' . $categories . '</categories>'
            . '<offers>' . $offers . '</offers>'
            . '</shop></yml_catalog>';
    }

    private function categoryId(FeedItem $item): string
    {
        if ($item->id === 'sample-offer') {
            return '1';
        }
        $value = trim((string)$item->channelCategory);
        if ($value === '' || $value === '杂项' || strcasecmp($value, 'other') === 0 || $value === '0') {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $value)) {
            return '';
        }

        return $value;
    }

    private function categoryName(FeedItem $item, string $fallback): string
    {
        if ($item->id === 'sample-offer') {
            return $fallback;
        }
        $name = trim((string)$item->channelCategoryName);

        return $name !== '' ? $name : trim((string)$item->channelCategory);
    }

    private function offerXml(FeedDefinition $definition, FeedItem $item, string $fallbackCurrency, string $categoryId): string
    {
        $currency = strtoupper(trim($item->currency) !== '' ? $item->currency : $fallbackCurrency);
        $available = $item->availability === 'in_stock' ? 'true' : 'false';

        return '<offer id="' . $this->xml($item->id) . '" available="' . $available . '">'
            . '<url>' . $this->xml($item->link) . '</url>'
            . '<price>' . $this->xml($this->money($item->priceMinor, $currency, false)) . '</price>'
            . '<currencyId>' . $this->xml($currency) . '</currencyId>'
            . '<categoryId>' . $this->xml($categoryId) . '</categoryId>'
            . '<picture>' . $this->xml($item->imageLink) . '</picture>'
            . '<name>' . $this->xml($item->title) . '</name>'
            . '</offer>';
    }
}
