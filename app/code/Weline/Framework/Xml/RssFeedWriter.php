<?php

declare(strict_types=1);

namespace Weline\Framework\Xml;

/**
 * Thin RSS 2.0 serializer — no business dependencies.
 */
final class RssFeedWriter
{
    private const ESCAPE_FLAGS = ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE;

    /**
     * @param array{
     *   title?: string,
     *   link?: string,
     *   description?: string,
     *   lastBuildDate?: string
     * } $channel
     * @param list<array{
     *   title?: string,
     *   link?: string,
     *   guid?: string,
     *   pubDate?: string,
     *   description?: string,
     *   content_html?: string,
     *   author?: string
     * }> $items
     */
    public function write(array $channel, array $items = []): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">' . "\n";
        $xml .= '  <channel>' . "\n";
        $xml .= '    <title>' . $this->escape((string)($channel['title'] ?? '')) . '</title>' . "\n";
        $xml .= '    <link>' . $this->escape((string)($channel['link'] ?? '')) . '</link>' . "\n";
        $xml .= '    <description>' . $this->escape((string)($channel['description'] ?? '')) . '</description>' . "\n";
        $lastBuild = trim((string)($channel['lastBuildDate'] ?? ''));
        if ($lastBuild === '') {
            $lastBuild = date('r');
        }
        $xml .= '    <lastBuildDate>' . $this->escape($lastBuild) . '</lastBuildDate>' . "\n";

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = (string)($item['title'] ?? '');
            $link = (string)($item['link'] ?? '');
            $guid = (string)($item['guid'] ?? $link);
            $pubDate = (string)($item['pubDate'] ?? '');
            $description = (string)($item['description'] ?? '');
            $contentHtml = (string)($item['content_html'] ?? '');
            $author = trim((string)($item['author'] ?? ''));

            $xml .= '    <item>' . "\n";
            $xml .= '      <title>' . $this->escape($title) . '</title>' . "\n";
            $xml .= '      <link>' . $this->escape($link) . '</link>' . "\n";
            $xml .= '      <guid>' . $this->escape($guid) . '</guid>' . "\n";
            if ($pubDate !== '') {
                $xml .= '      <pubDate>' . $this->escape($pubDate) . '</pubDate>' . "\n";
            }
            if ($author !== '') {
                $xml .= '      <author>' . $this->escape($author) . '</author>' . "\n";
            }
            $xml .= '      <description>' . $this->cdata($description) . '</description>' . "\n";
            if ($contentHtml !== '') {
                $xml .= '      <content:encoded>' . $this->cdata($contentHtml) . '</content:encoded>' . "\n";
            }
            $xml .= '    </item>' . "\n";
        }

        $xml .= '  </channel>' . "\n";
        $xml .= '</rss>';

        return $xml;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, self::ESCAPE_FLAGS, 'UTF-8');
    }

    private function cdata(string $value): string
    {
        $safe = str_replace(']]>', ']]]]><![CDATA[>', $value);

        return '<![CDATA[' . $safe . ']]>';
    }
}
