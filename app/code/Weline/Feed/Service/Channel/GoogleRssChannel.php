<?php

declare(strict_types=1);

namespace Weline\Feed\Service\Channel;

use Weline\Feed\Api\Data\FeedConnectionMode;
use Weline\Feed\Api\Data\FeedDefinition;
use Weline\Feed\Api\Data\FeedItem;

/**
 * Google Merchant Center RSS. Microsoft Merchant Center reuses the same g: nodes.
 */
abstract class GoogleRssChannel extends AbstractFeedChannel
{
    public function acceptedModes(): array
    {
        return [FeedConnectionMode::FEED_URL, FeedConnectionMode::OAUTH];
    }

    public function connectionDescription(): string
    {
        return 'oauth 同意页连接；公开文档走 feed_url。本地文件不是已授权。';
    }

    public function contentType(): string
    {
        return 'application/rss+xml; charset=utf-8';
    }

    public function filenameSuffix(): string
    {
        return 'xml';
    }

    /**
     * Brand is optional for products without a clearly associated brand; Google forbids made-up brands.
     */
    protected function extraRequired(FeedItem $item): array
    {
        return [$item->description];
    }

    protected function build(FeedDefinition $definition, array $rows): string
    {
        $title = trim($definition->title) !== '' ? trim($definition->title) : $this->displayName();
        $home = $rows === [] ? 'https://shop.example/' : $this->origin($rows[0]->link);
        $items = '';
        foreach ($rows as $row) {
            $items .= $this->itemXml($definition, $row);
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">'
            . '<channel>'
            . '<title>' . $this->xml($title) . '</title>'
            . '<link>' . $this->xml($home) . '</link>'
            . '<description>' . $this->xml($title) . '</description>'
            . $items
            . '</channel></rss>';
    }

    private function itemXml(FeedDefinition $definition, FeedItem $item): string
    {
        $currency = trim($item->currency) !== '' ? $item->currency : $definition->currency;
        $xml = '<item>'
            . '<g:id>' . $this->xml($item->id) . '</g:id>'
            . '<g:title>' . $this->xml($item->title) . '</g:title>'
            . '<g:description>' . $this->xml($item->description) . '</g:description>'
            . '<g:link>' . $this->xml($item->link) . '</g:link>'
            . '<g:image_link>' . $this->xml($item->imageLink) . '</g:image_link>'
            . '<g:availability>' . $this->xml($this->googleAvailability($item->availability)) . '</g:availability>'
            . '<g:condition>new</g:condition>'
            . '<g:price>' . $this->xml($this->money($item->priceMinor, $currency, true)) . '</g:price>';
        $salePriceMinor = (int)($item->salePriceMinor ?? 0);
        if ($salePriceMinor > 0 && $salePriceMinor < $item->priceMinor) {
            $xml .= '<g:sale_price>' . $this->xml($this->money($salePriceMinor, $currency, true)) . '</g:sale_price>';
        }
        foreach ($item->shipping as $shipping) {
            if (!is_array($shipping)) {
                continue;
            }
            $country = strtoupper(trim((string)($shipping['country'] ?? '')));
            $costMinor = (int)($shipping['cost_minor'] ?? 0);
            $shippingCurrency = strtoupper(trim((string)($shipping['currency'] ?? '')));
            if (strlen($country) !== 2 || $costMinor <= 0 || $shippingCurrency === '') {
                continue;
            }
            $xml .= '<g:shipping>'
                . '<g:country>' . $this->xml($country) . '</g:country>'
                . '<g:price>' . $this->xml($this->money($costMinor, $shippingCurrency, true)) . '</g:price>'
                . '<g:service>Standard</g:service>';
            $daysMin = max(0, (int)($shipping['days_min'] ?? 0));
            $daysMax = max(0, (int)($shipping['days_max'] ?? 0));
            if ($daysMin > 0) {
                $xml .= '<g:min_transit_time>' . $daysMin . '</g:min_transit_time>';
            }
            if ($daysMax >= $daysMin && $daysMax > 0) {
                $xml .= '<g:max_transit_time>' . $daysMax . '</g:max_transit_time>';
            }
            $xml .= '</g:shipping>';
        }
        $brand = trim($item->brand);
        $gtin = trim((string)$item->gtin);
        $mpn = trim((string)$item->mpn);
        if ($brand !== '') {
            $xml .= '<g:brand>' . $this->xml($brand) . '</g:brand>';
        }
        if (trim($item->groupId) !== '') {
            $xml .= '<g:item_group_id>' . $this->xml($item->groupId) . '</g:item_group_id>';
        }
        if ($gtin !== '') {
            $xml .= '<g:gtin>' . $this->xml($gtin) . '</g:gtin>';
        }
        if ($mpn !== '') {
            $xml .= '<g:mpn>' . $this->xml($mpn) . '</g:mpn>';
        }
        if ($gtin === '' && ($brand === '' || $mpn === '')) {
            $xml .= '<g:identifier_exists>no</g:identifier_exists>';
        }
        $category = trim((string)$item->channelCategory);
        if ($item->id !== 'sample-offer' && preg_match('/^[1-9][0-9]*$/', $category)) {
            $xml .= '<g:google_product_category>' . $this->xml($category) . '</g:google_product_category>';
        }

        return $xml . '</item>';
    }

    /**
     * Google Merchant Center accepts "in stock" / "out of stock"; the neutral row uses underscores.
     */
    private function googleAvailability(string $availability): string
    {
        return $availability === 'in_stock' ? 'in stock' : 'out of stock';
    }
}
