<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\GenerativeEngineOptimization\Service;

use Weline\GenerativeEngineOptimization\Model\Feed;
use Weline\GenerativeEngineOptimization\Model\FeedItem;
use Weline\Framework\Manager\ObjectManager;

/**
 * Feed生成服务
 * 
 * @package Weline_GenerativeEngineOptimization
 */
class FeedGeneratorService
{
    public const FEED_DIR = BP . '/pub/geo-feeds';

    /**
     * 生成Feed内容
     * 
     * @param Feed $feed Feed配置
     * @param string $format Feed格式（json_feed, xml, rss）
     * @return string Feed内容
     */
    public function generateFeed(Feed $feed, string $format = 'json_feed'): string
    {
        // 获取Feed条目
        $items = $this->getFeedItems($feed);
        
        // 根据格式生成Feed
        switch ($format) {
            case 'json_feed':
                return $this->generateJsonFeed($feed, $items);
            case 'xml':
            case 'rss':
                return $this->generateRssFeed($feed, $items);
            default:
                return $this->generateJsonFeed($feed, $items);
        }
    }

    /**
     * 生成Feed并保存到pub目录，返回相对URL路径
     *
     * @param Feed $feed
     * @param string $format
     * @return string 相对URL路径，例如：/geo-feeds/feed_1.json
     */
    public function generateAndSaveFeed(Feed $feed, string $format = 'json_feed'): string
    {
        // 生成内容
        $content = $this->generateFeed($feed, $format);

        // 计算扩展名
        $ext = match ($format) {
            'xml', 'rss' => 'xml',
            default => 'json',
        };

        // 确保目录存在
        if (!is_dir(self::FEED_DIR)) {
            @mkdir(self::FEED_DIR, 0755, true);
        }

        // 文件名：feed_{id}.ext
        $feedId = $feed->getId();
        if (!$feedId) {
            // 没有ID时先保存一次以确保有主键
            $feed->save();
            $feedId = $feed->getId();
        }

        $fileName = 'feed_' . $feedId . '.' . $ext;
        $filePath = self::FEED_DIR . '/' . $fileName;

        // 写入文件
        file_put_contents($filePath, $content);

        // 相对URL路径（相对于站点根目录）
        $relativeUrl = '/geo-feeds/' . $fileName;

        // 同步更新Feed的URL字段
        $feed->setData(Feed::schema_fields_FEED_URL, $relativeUrl);
        $feed->setData(Feed::schema_fields_LAST_GENERATED_AT, time());
        $feed->save();

        return $relativeUrl;
    }

    /**
     * 获取Feed条目
     * 
     * @param Feed $feed Feed配置
     * @return array Feed条目数组
     */
    protected function getFeedItems(Feed $feed): array
    {
        /** @var FeedItem $feedItemModel */
        $feedItemModel = ObjectManager::getInstance(FeedItem::class);
        
        $items = $feedItemModel
            ->where('feed_id', $feed->getId())
            ->where('is_published', 1)
            ->order('published_at', 'DESC')
            ->select()
            ->fetchArray();

        return $items;
    }

    /**
     * 生成JSON Feed
     * 
     * @param Feed $feed Feed配置
     * @param array $items Feed条目
     * @return string JSON Feed内容
     */
    protected function generateJsonFeed(Feed $feed, array $items): string
    {
        $jsonFeed = [
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => $feed->getData(Feed::schema_fields_FEED_NAME),
            'description' => $feed->getConfigArray()['description'] ?? '',
            'home_page_url' => $feed->getData(Feed::schema_fields_FEED_URL) ?? '',
            'feed_url' => $feed->getData(Feed::schema_fields_FEED_URL) ?? '',
            'items' => [],
        ];

        foreach ($items as $item) {
            $jsonFeed['items'][] = [
                'id' => $item['url'] ?? '',
                'url' => $item['url'] ?? '',
                'title' => $item['title'] ?? '',
                'content_text' => $item['content'] ?? '',
                'content_html' => $this->getContentHtml($item),
                'date_published' => $this->formatDate($item['published_at'] ?? $item['created_at'] ?? time()),
                'date_modified' => $this->formatDate($item['updated_at'] ?? $item['created_at'] ?? time()),
                'authors' => $this->getAuthors($item),
                'tags' => $this->getTags($item),
            ];
        }

        return json_encode($jsonFeed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * 生成RSS Feed
     * 
     * @param Feed $feed Feed配置
     * @param array $items Feed条目
     * @return string RSS Feed内容
     */
    protected function generateRssFeed(Feed $feed, array $items): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">' . "\n";
        $xml .= '  <channel>' . "\n";
        $xml .= '    <title>' . htmlspecialchars($feed->getData(Feed::schema_fields_FEED_NAME)) . '</title>' . "\n";
        $xml .= '    <link>' . htmlspecialchars($feed->getData(Feed::schema_fields_FEED_URL) ?? '') . '</link>' . "\n";
        $xml .= '    <description>' . htmlspecialchars($feed->getConfigArray()['description'] ?? '') . '</description>' . "\n";
        $xml .= '    <lastBuildDate>' . date('r') . '</lastBuildDate>' . "\n";

        foreach ($items as $item) {
            $xml .= '    <item>' . "\n";
            $xml .= '      <title>' . htmlspecialchars($item['title'] ?? '') . '</title>' . "\n";
            $xml .= '      <link>' . htmlspecialchars($item['url'] ?? '') . '</link>' . "\n";
            $xml .= '      <guid>' . htmlspecialchars($item['url'] ?? '') . '</guid>' . "\n";
            $xml .= '      <pubDate>' . date('r', $item['published_at'] ?? $item['created_at'] ?? time()) . '</pubDate>' . "\n";
            $xml .= '      <description><![CDATA[' . ($item['content'] ?? '') . ']]></description>' . "\n";
            $xml .= '      <content:encoded><![CDATA[' . $this->getContentHtml($item) . ']]></content:encoded>' . "\n";
            $xml .= '    </item>' . "\n";
        }

        $xml .= '  </channel>' . "\n";
        $xml .= '</rss>';

        return $xml;
    }

    /**
     * 获取内容HTML
     * 
     * @param array $item Feed条目
     * @return string HTML内容
     */
    protected function getContentHtml(array $item): string
    {
        $metadata = json_decode($item['metadata'] ?? '{}', true);
        return $metadata['content_html'] ?? $item['content'] ?? '';
    }

    /**
     * 获取作者信息
     * 
     * @param array $item Feed条目
     * @return array 作者数组
     */
    protected function getAuthors(array $item): array
    {
        $metadata = json_decode($item['metadata'] ?? '{}', true);
        $authors = $metadata['authors'] ?? [];
        
        if (empty($authors) && isset($metadata['author'])) {
            $authors = [['name' => $metadata['author']]];
        }
        
        return $authors;
    }

    /**
     * 获取标签
     * 
     * @param array $item Feed条目
     * @return array 标签数组
     */
    protected function getTags(array $item): array
    {
        $metadata = json_decode($item['metadata'] ?? '{}', true);
        return $metadata['tags'] ?? [];
    }

    /**
     * 格式化日期
     * 
     * @param int|string $timestamp 时间戳
     * @return string ISO 8601格式日期
     */
    protected function formatDate($timestamp): string
    {
        if (is_string($timestamp)) {
            $timestamp = strtotime($timestamp);
        }
        return date('c', $timestamp ?: time());
    }
}
