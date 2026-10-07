<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Weline\Framework\Runtime\RequestContext;

/**
 * Resolve YouTube / Vimeo / Bilibili ids and source URLs for Theme video widgets.
 *
 * Accepts watch URLs, short links, embed URLs, and bare URLs pasted into embed_code.
 */
final class VideoEmbedResolver
{
    /** @var array<string, string> */
    private static array $sanitizeProcessCache = [];

    /**
     * Sanitize editor/storefront embed HTML (iframe/video/source/embed only).
     * Memoized per process + request so carousel slides do not re-parse DOMDocument.
     */
    public static function sanitizeEmbedHtml(mixed $html): string
    {
        $html = trim((string)$html);
        if ($html === '') {
            return '';
        }

        $cacheKey = hash('xxh3', $html);
        if (isset(self::$sanitizeProcessCache[$cacheKey])) {
            return self::$sanitizeProcessCache[$cacheKey];
        }

        $requestKey = 'theme.video_embed.sanitize.' . $cacheKey;
        if (RequestContext::isInitialized() && RequestContext::has($requestKey)) {
            $cached = RequestContext::get($requestKey);
            if (\is_string($cached)) {
                return self::$sanitizeProcessCache[$cacheKey] = $cached;
            }
        }

        $safe = self::sanitizeEmbedHtmlUncached($html);
        self::$sanitizeProcessCache[$cacheKey] = $safe;
        if (RequestContext::isInitialized()) {
            RequestContext::set($requestKey, $safe);
        }
        // Bound process cache growth for long-lived WLS workers.
        if (\count(self::$sanitizeProcessCache) > 256) {
            self::$sanitizeProcessCache = \array_slice(self::$sanitizeProcessCache, -128, null, true);
        }

        return $safe;
    }

    private static function sanitizeEmbedHtmlUncached(string $html): string
    {
        $allowedTags = ['iframe' => true, 'video' => true, 'source' => true, 'embed' => true];
        $allowedAttrs = [
            'iframe' => ['src', 'width', 'height', 'title', 'allow', 'allowfullscreen', 'loading', 'referrerpolicy', 'class'],
            'video' => ['src', 'poster', 'width', 'height', 'controls', 'autoplay', 'muted', 'loop', 'playsinline', 'preload', 'class'],
            'source' => ['src', 'type', 'media'],
            'embed' => ['src', 'type', 'width', 'height', 'class'],
        ];
        $trustedIframeHosts = self::trustedEmbedHosts();

        $previous = \libxml_use_internal_errors(true);
        try {
            $wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
            if (\function_exists('mb_encode_numericentity')) {
                $wrapped = \mb_encode_numericentity($wrapped, [0x80, 0x10FFFF, 0, 0xFFFF], 'UTF-8');
            }
            $doc = new DOMDocument('1.0', 'UTF-8');
            $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $xpath = new DOMXPath($doc);

            $nodes = [];
            foreach ($xpath->query('//body//*') as $node) {
                if ($node instanceof DOMElement) {
                    $nodes[] = $node;
                }
            }

            foreach ($nodes as $node) {
                if (!$node->parentNode) {
                    continue;
                }
                $tag = \strtolower($node->tagName);
                if (!isset($allowedTags[$tag])) {
                    if (!\in_array($tag, ['script', 'style', 'object'], true)) {
                        while ($node->firstChild) {
                            $node->parentNode->insertBefore($node->firstChild, $node);
                        }
                    }
                    $node->parentNode?->removeChild($node);
                    continue;
                }

                $toRemove = [];
                foreach ($node->attributes as $attr) {
                    $name = \strtolower($attr->name);
                    $value = (string)$attr->value;
                    if (\str_starts_with($name, 'on') || $name === 'style' || $name === 'srcdoc' || !\in_array($name, $allowedAttrs[$tag], true)) {
                        $toRemove[] = $attr->name;
                        continue;
                    }

                    if (\in_array($name, ['src', 'poster'], true)) {
                        $safeUrl = self::safeHttpUrl($value);
                        if ($safeUrl === '') {
                            $toRemove[] = $attr->name;
                            continue;
                        }
                        if ($tag === 'iframe') {
                            $host = \strtolower((string)\parse_url($safeUrl, PHP_URL_HOST));
                            if (!\in_array($host, $trustedIframeHosts, true)) {
                                $toRemove[] = $attr->name;
                                continue;
                            }
                        }
                        $node->setAttribute($attr->name, $safeUrl);
                    } elseif ($name === 'class') {
                        $node->setAttribute($attr->name, \trim((string)\preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $value)));
                    } elseif (\in_array($name, ['width', 'height'], true) && !\preg_match('/^\d{1,4}(?:\.\d{1,2})?%?$/', $value)) {
                        $toRemove[] = $attr->name;
                    } elseif ($name === 'allow') {
                        $node->setAttribute($attr->name, \preg_replace('/[^a-zA-Z0-9;,\-\s]/', '', $value) ?: '');
                    }
                }
                foreach ($toRemove as $name) {
                    $node->removeAttribute($name);
                }

                if (($tag === 'iframe' || $tag === 'embed') && !$node->hasAttribute('src')) {
                    $node->parentNode?->removeChild($node);
                    continue;
                }
                if ($tag === 'iframe') {
                    $node->setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation');
                    $node->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
                    $node->setAttribute('loading', 'lazy');
                }
            }

            $body = $doc->getElementsByTagName('body')->item(0);
            $safeHtml = '';
            if ($body) {
                foreach ($body->childNodes as $child) {
                    $safeHtml .= $doc->saveHTML($child);
                }
            }

            return $safeHtml;
        } catch (\Throwable) {
            return '';
        } finally {
            \libxml_clear_errors();
            \libxml_use_internal_errors($previous);
        }
    }

    public static function safeHttpUrl(mixed $url): string
    {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }
        $normalized = strtolower(str_replace(["\r", "\n", "\t", ' '], '', $url));
        if (preg_match('#^(javascript:|data:text/html|vbscript:)#i', $normalized)) {
            return '';
        }
        if (str_starts_with($url, '//')) {
            return '';
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
            return in_array($scheme, ['http', 'https'], true) ? $url : '';
        }
        return preg_match('#^(/(?!/)|\./|\.\./|[^<>"\']+$)#', $url) ? $url : '';
    }

    /**
     * Prefer video_url; if empty, accept a bare URL pasted into embed_code (not HTML).
     */
    public static function coalesceSourceUrl(string $videoUrl, string $embedRaw): string
    {
        $fromVideo = self::safeHttpUrl($videoUrl);
        if ($fromVideo !== '') {
            return $fromVideo;
        }

        $embedRaw = trim($embedRaw);
        if ($embedRaw === '' || str_contains($embedRaw, '<')) {
            return '';
        }

        return self::safeHttpUrl($embedRaw);
    }

    public static function resolveYoutubeId(string $urlOrHtml): string
    {
        $candidates = self::candidateUrls($urlOrHtml);
        foreach ($candidates as $candidate) {
            if (preg_match(
                '~(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:[^#]*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{6,64})~i',
                $candidate,
                $matches
            )) {
                return $matches[1];
            }
        }

        return '';
    }

    public static function resolveVimeoId(string $urlOrHtml): string
    {
        $candidates = self::candidateUrls($urlOrHtml);
        foreach ($candidates as $candidate) {
            if (preg_match('~(?:player\.)?vimeo\.com/(?:video/)?(\d{6,12})~i', $candidate, $matches)) {
                return $matches[1];
            }
        }

        return '';
    }

    /**
     * Resolve a Bilibili BV id (e.g. BV1xx411c7mD) or av/aid token (e.g. av170001).
     *
     * Accepts www.bilibili.com/video/… pages, player.bilibili.com embed URLs, and iframe src.
     * Does not follow b23.tv short links (no outbound HTTP).
     */
    public static function resolveBilibiliId(string $urlOrHtml): string
    {
        $candidates = self::candidateUrls($urlOrHtml);
        foreach ($candidates as $candidate) {
            if (preg_match('~bilibili\.com/video/(BV[0-9A-Za-z]+)~i', $candidate, $matches)) {
                return self::normalizeBilibiliBv($matches[1]);
            }
            if (preg_match('~(?:^|[?&])bvid=(BV[0-9A-Za-z]+)~i', $candidate, $matches)) {
                return self::normalizeBilibiliBv($matches[1]);
            }
            if (preg_match('~bilibili\.com/video/av(\d{1,12})\b~i', $candidate, $matches)) {
                return 'av' . $matches[1];
            }
            if (
                stripos($candidate, 'bilibili.com') !== false
                && preg_match('~(?:^|[?&])aid=(\d{1,12})\b~i', $candidate, $matches)
            ) {
                return 'av' . $matches[1];
            }
        }

        return '';
    }

    /**
     * Build a trusted Bilibili player embed URL from a BV id or av/aid token.
     */
    public static function bilibiliEmbedUrl(string $id): string
    {
        $id = trim($id);
        if ($id === '') {
            return '';
        }
        if (preg_match('~^BV[0-9A-Za-z]+$~i', $id)) {
            return 'https://player.bilibili.com/player.html?bvid=' . self::normalizeBilibiliBv($id);
        }
        if (preg_match('~^(?:av)?(\d{1,12})$~i', $id, $matches)) {
            return 'https://player.bilibili.com/player.html?aid=' . $matches[1];
        }

        return '';
    }

    /**
     * Trusted iframe hosts for video embeds (editor preview sanitizers must keep these).
     *
     * @return list<string>
     */
    public static function trustedEmbedHosts(): array
    {
        return [
            'youtube.com',
            'www.youtube.com',
            'youtube-nocookie.com',
            'www.youtube-nocookie.com',
            'player.vimeo.com',
            'player.bilibili.com',
        ];
    }

    private static function normalizeBilibiliBv(string $bv): string
    {
        if ($bv === '') {
            return '';
        }

        return 'BV' . substr($bv, 2);
    }

    public static function isTrustedEmbedSrc(string $src): bool
    {
        $src = self::safeHttpUrl($src);
        if ($src === '') {
            return false;
        }
        $host = strtolower((string)parse_url($src, PHP_URL_HOST));
        return $host !== '' && in_array($host, self::trustedEmbedHosts(), true);
    }

    /**
     * @return list<string>
     */
    private static function candidateUrls(string $urlOrHtml): array
    {
        $urlOrHtml = trim($urlOrHtml);
        if ($urlOrHtml === '') {
            return [];
        }

        $out = [$urlOrHtml];
        if (preg_match_all('#\bsrc=["\']([^"\']+)["\']#i', $urlOrHtml, $matches)) {
            foreach ($matches[1] as $src) {
                $safe = self::safeHttpUrl($src);
                if ($safe !== '') {
                    $out[] = $safe;
                }
            }
        }

        return $out;
    }
}
