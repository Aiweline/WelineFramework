<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Sitemap;

use Weline\Seo\Model\SitemapUrl;

class SitemapXmlExtensionRenderer
{
    /**
     * @param array<int, array<string, mixed>> $urls
     */
    public function urlsetOpenTag(array $urls): string
    {
        $namespaces = ['xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'];
        $required = $this->requiredNamespaces($urls);
        if (isset($required['image'])) {
            $namespaces[] = 'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"';
        }
        if (isset($required['video'])) {
            $namespaces[] = 'xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"';
        }
        if (isset($required['news'])) {
            $namespaces[] = 'xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"';
        }
        if (isset($required['xhtml'])) {
            $namespaces[] = 'xmlns:xhtml="http://www.w3.org/1999/xhtml"';
        }

        return '<urlset ' . implode(' ', $namespaces) . '>';
    }

    /**
     * @param array<string, mixed> $url
     */
    public function renderUrlExtensions(array $url): string
    {
        $metadata = $this->metadata($url);
        $pageUrl = $this->pageUrl($url);
        $xml = '';
        foreach ($this->list($metadata['images'] ?? $metadata['image'] ?? []) as $image) {
            $xml .= $this->renderImage($image, $pageUrl);
        }
        foreach ($this->list($metadata['videos'] ?? $metadata['video'] ?? []) as $video) {
            $xml .= $this->renderVideo($video, $pageUrl);
        }
        if (isset($metadata['news']) && is_array($metadata['news'])) {
            $xml .= $this->renderNews($metadata['news']);
        }
        $xml .= $this->renderAlternates($metadata['alternates'] ?? $metadata['hreflang'] ?? [], $pageUrl);

        return $xml;
    }

    /**
     * @param array<int, array<string, mixed>> $urls
     * @return array<string, bool>
     */
    private function requiredNamespaces(array $urls): array
    {
        $required = [];
        foreach ($urls as $url) {
            $metadata = $this->metadata($url);
            if ($this->list($metadata['images'] ?? $metadata['image'] ?? []) !== []) {
                $required['image'] = true;
            }
            if ($this->list($metadata['videos'] ?? $metadata['video'] ?? []) !== []) {
                $required['video'] = true;
            }
            if (isset($metadata['news']) && is_array($metadata['news']) && $metadata['news'] !== []) {
                $required['news'] = true;
            }
            if ($this->alternates($metadata['alternates'] ?? $metadata['hreflang'] ?? []) !== []) {
                $required['xhtml'] = true;
            }
        }
        return $required;
    }

    /**
     * @param array<string, mixed> $url
     * @return array<string, mixed>
     */
    private function metadata(array $url): array
    {
        $metadata = $url[SitemapUrl::schema_fields_METADATA] ?? $url['metadata'] ?? [];
        if (is_string($metadata) && trim($metadata) !== '') {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($metadata)) {
            $metadata = [];
        }
        if (isset($url['sitemap']) && is_array($url['sitemap'])) {
            $metadata = array_replace_recursive($metadata, $url['sitemap']);
        }
        foreach (['images', 'image', 'videos', 'video', 'news', 'alternates', 'hreflang'] as $key) {
            if (array_key_exists($key, $url) && !array_key_exists($key, $metadata)) {
                $metadata[$key] = $url[$key];
            }
        }
        return $metadata;
    }

    private function renderImage(mixed $image, string $pageUrl = ''): string
    {
        $data = is_array($image) ? $image : ['loc' => $image];
        $loc = $this->absoluteUrl(
            trim((string) ($data['loc'] ?? $data['url'] ?? $data['src'] ?? '')),
            $pageUrl,
        );
        if ($loc === '') {
            return '';
        }
        $xml = "    <image:image>\n";
        $xml .= '      <image:loc>' . $this->escape($loc) . "</image:loc>\n";
        foreach (['caption' => 'caption', 'title' => 'title'] as $source => $tag) {
            $value = trim((string) ($data[$source] ?? ''));
            if ($value !== '') {
                $xml .= '      <image:' . $tag . '>' . $this->escape($value) . '</image:' . $tag . ">\n";
            }
        }
        $xml .= "    </image:image>\n";
        return $xml;
    }

    private function renderVideo(mixed $video, string $pageUrl = ''): string
    {
        if (!is_array($video)) {
            return '';
        }
        $thumbnail = $this->absoluteUrl(
            trim((string) ($video['thumbnail_loc'] ?? $video['thumbnail'] ?? $video['image'] ?? '')),
            $pageUrl,
        );
        $title = trim((string) ($video['title'] ?? ''));
        $description = trim((string) ($video['description'] ?? ''));
        if ($thumbnail === '' || $title === '' || $description === '') {
            return '';
        }
        $xml = "    <video:video>\n";
        $xml .= '      <video:thumbnail_loc>' . $this->escape($thumbnail) . "</video:thumbnail_loc>\n";
        $xml .= '      <video:title>' . $this->escape($title) . "</video:title>\n";
        $xml .= '      <video:description>' . $this->escape($description) . "</video:description>\n";
        foreach (['content_loc', 'player_loc', 'duration', 'publication_date'] as $tag) {
            $value = trim((string) ($video[$tag] ?? ''));
            if ($value !== '') {
                if ($tag === 'publication_date') {
                    $value = $this->formatDateIfNeeded($value);
                } elseif ($tag === 'content_loc' || $tag === 'player_loc') {
                    $value = $this->absoluteUrl($value, $pageUrl);
                    if ($value === '') {
                        continue;
                    }
                }
                $xml .= '      <video:' . $tag . '>' . $this->escape($value) . '</video:' . $tag . ">\n";
            }
        }
        $xml .= "    </video:video>\n";
        return $xml;
    }

    /**
     * @param array<string, mixed> $news
     */
    private function renderNews(array $news): string
    {
        $publication = is_array($news['publication'] ?? null) ? $news['publication'] : [];
        $name = trim((string) ($publication['name'] ?? $news['publication_name'] ?? $news['name'] ?? ''));
        $language = trim((string) ($publication['language'] ?? $news['language'] ?? ''));
        $date = trim((string) ($news['publication_date'] ?? $news['datePublished'] ?? $news['published_at'] ?? ''));
        $title = trim((string) ($news['title'] ?? $news['headline'] ?? ''));
        if ($name === '' || $language === '' || $date === '' || $title === '') {
            return '';
        }

        $xml = "    <news:news>\n";
        $xml .= "      <news:publication>\n";
        $xml .= '        <news:name>' . $this->escape($name) . "</news:name>\n";
        $xml .= '        <news:language>' . $this->escape($this->normalizeNewsLanguage($language)) . "</news:language>\n";
        $xml .= "      </news:publication>\n";
        $xml .= '      <news:publication_date>' . $this->escape($this->formatDateIfNeeded($date)) . "</news:publication_date>\n";
        $xml .= '      <news:title>' . $this->escape($title) . "</news:title>\n";
        $xml .= "    </news:news>\n";
        return $xml;
    }

    private function renderAlternates(mixed $alternates, string $pageUrl = ''): string
    {
        $xml = '';
        foreach ($this->alternates($alternates, $pageUrl) as $alternate) {
            $xml .= '    <xhtml:link rel="alternate" hreflang="' . $this->escape($alternate['hreflang']) . '" href="' . $this->escape($alternate['href']) . "\" />\n";
        }
        return $xml;
    }

    /**
     * @return array<int, array{hreflang:string,href:string}>
     */
    private function alternates(mixed $alternates, string $pageUrl = ''): array
    {
        if (!is_array($alternates)) {
            return [];
        }
        $result = [];
        foreach ($alternates as $locale => $value) {
            if (is_array($value)) {
                $locale = $value['hreflang'] ?? $value['locale'] ?? $locale;
                $href = $value['href'] ?? $value['url'] ?? '';
            } else {
                $href = $value;
            }
            $locale = trim((string) $locale);
            $href = $this->absoluteUrl(trim((string) $href), $pageUrl);
            if ($locale !== '' && $href !== '') {
                $result[] = ['hreflang' => $this->normalizeHreflang($locale), 'href' => $href];
            }
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $url
     */
    private function pageUrl(array $url): string
    {
        foreach ([SitemapUrl::schema_fields_URL, 'loc', 'url'] as $key) {
            $candidate = trim((string) ($url[$key] ?? ''));
            if ($candidate !== '' && preg_match('#^https?://#i', $candidate) === 1) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Sitemap protocol requires absolute http(s) URLs for image:loc / video:*_loc / xhtml:link href.
     */
    private function absoluteUrl(string $url, string $pageUrl): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            $scheme = 'https';
            if ($pageUrl !== '' && preg_match('#^(https?):#i', $pageUrl, $matches) === 1) {
                $scheme = strtolower($matches[1]);
            }

            return $scheme . ':' . $url;
        }

        $parts = $pageUrl !== '' ? parse_url($pageUrl) : false;
        if (!is_array($parts) || empty($parts['host'])) {
            // No page origin available — refuse relative output (would fail GSC).
            return '';
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $origin = ((string) ($parts['scheme'] ?? 'https')) . '://' . $parts['host'] . $port;
        if (!str_starts_with($url, '/')) {
            $url = '/' . ltrim($url, '/');
        }

        return $origin . $url;
    }

    /**
     * @return array<int, mixed>
     */
    private function list(mixed $value): array
    {
        if ($value === null || $value === '' || $value === false) {
            return [];
        }
        if (!is_array($value)) {
            return [$value];
        }
        return array_keys($value) === range(0, count($value) - 1) ? $value : [$value];
    }

    private function normalizeNewsLanguage(string $language): string
    {
        $language = strtolower(str_replace('_', '-', trim($language)));
        return match ($language) {
            'zh', 'zh-hans', 'zh-hans-cn', 'zh-cn' => 'zh-cn',
            'zh-hant', 'zh-hant-tw', 'zh-tw' => 'zh-tw',
            default => $language,
        };
    }

    private function normalizeHreflang(string $locale): string
    {
        $locale = trim($locale);
        if (strtolower($locale) === 'x-default') {
            return 'x-default';
        }
        return str_replace('_', '-', $locale);
    }

    private function formatDateIfNeeded(string $value): string
    {
        if (is_numeric($value)) {
            return date('c', (int) $value);
        }
        $time = strtotime($value);
        return $time ? date('c', $time) : $value;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
