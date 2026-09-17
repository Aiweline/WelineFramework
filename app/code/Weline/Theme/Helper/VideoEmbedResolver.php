<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

/**
 * Resolve YouTube / Vimeo ids and source URLs for the video-player widget.
 *
 * Accepts watch URLs, short links, embed URLs, and bare URLs pasted into embed_code.
 */
final class VideoEmbedResolver
{
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
        ];
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
