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
            . '<g:availability>' . $this->xml($item->availability) . '</g:availability>'
            . '<g:price>' . $this->xml($this->money($item->priceMinor, $currency, true)) . '</g:price>';
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
}
