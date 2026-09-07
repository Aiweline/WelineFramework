<?php

declare(strict_types=1);

namespace Weline\Product\Service;

/**
 * Normalize pasted watch URLs, short links, and iframe embed snippets into a
 * stable product-video descriptor for Media role=video rows.
 */
final class ProductVideoUrlNormalizer
{
    public const PROVIDER_YOUTUBE = 'youtube';
    public const PROVIDER_VIMEO = 'vimeo';
    public const PROVIDER_FILE = 'file';

    /**
     * @return array{
     *   provider:string,
     *   provider_id:string,
     *   path:string,
     *   embed_url:string,
     *   watch_url:string,
     *   poster_url:string,
     *   mime_type:string
     * }
     */
    public function normalize(string $raw): array
    {
        $input = trim($raw);
        if ($input === '') {
            throw new \InvalidArgumentException('product_media_video_url_empty');
        }

        if (preg_match('/<iframe\b[^>]*\bsrc\s*=\s*([\'"])(.*?)\1/is', $input, $iframeMatch) === 1) {
            $input = html_entity_decode(trim((string)$iframeMatch[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } elseif (preg_match('/\bsrc\s*=\s*([\'"])(https?:\/\/.*?)\1/i', $input, $srcMatch) === 1
            && stripos($input, 'iframe') !== false
        ) {
            $input = html_entity_decode(trim((string)$srcMatch[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $input = trim($input);
        if ($input === '') {
            throw new \InvalidArgumentException('product_media_video_url_invalid');
        }

        if (!preg_match('#^https?://#i', $input)) {
            throw new \InvalidArgumentException('product_media_video_url_invalid');
        }

        $parts = parse_url($input);
        if (!is_array($parts) || empty($parts['host'])) {
            throw new \InvalidArgumentException('product_media_video_url_invalid');
        }

        $host = strtolower((string)$parts['host']);
        $path = (string)($parts['path'] ?? '');
        $query = [];
        if (isset($parts['query'])) {
            parse_str((string)$parts['query'], $query);
        }

        if ($this->isYouTubeHost($host)) {
            $videoId = $this->youtubeId($host, $path, $query);
            if ($videoId === '') {
                throw new \InvalidArgumentException('product_media_video_url_invalid');
            }

            return [
                'provider' => self::PROVIDER_YOUTUBE,
                'provider_id' => $videoId,
                'path' => 'video://youtube/' . $videoId,
                'embed_url' => 'https://www.youtube.com/embed/' . rawurlencode($videoId),
                'watch_url' => 'https://www.youtube.com/watch?v=' . rawurlencode($videoId),
                'poster_url' => 'https://i.ytimg.com/vi/' . rawurlencode($videoId) . '/hqdefault.jpg',
                'mime_type' => 'video/youtube',
            ];
        }

        if ($this->isVimeoHost($host)) {
            $videoId = $this->vimeoId($path);
            if ($videoId === '') {
                throw new \InvalidArgumentException('product_media_video_url_invalid');
            }

            return [
                'provider' => self::PROVIDER_VIMEO,
                'provider_id' => $videoId,
                'path' => 'video://vimeo/' . $videoId,
                'embed_url' => 'https://player.vimeo.com/video/' . rawurlencode($videoId),
                'watch_url' => 'https://vimeo.com/' . rawurlencode($videoId),
                'poster_url' => '',
                'mime_type' => 'video/vimeo',
            ];
        }

        if ($this->isDirectFileUrl($path, $query)) {
            $mime = $this->mimeFromPath($path);
            $stable = $this->canonicalizeHttpsUrl($input);

            return [
                'provider' => self::PROVIDER_FILE,
                'provider_id' => hash('sha256', $stable),
                'path' => $stable,
                'embed_url' => $stable,
                'watch_url' => $stable,
                'poster_url' => '',
                'mime_type' => $mime,
            ];
        }

        throw new \InvalidArgumentException('product_media_video_provider_unsupported');
    }

    /**
     * @param array<string, mixed>|null $policy
     * @return array<string, mixed>|null
     */
    public function fromAccessPolicy(mixed $policy): ?array
    {
        if (is_string($policy) && trim($policy) !== '') {
            try {
                $policy = json_decode($policy, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
        }
        if (!is_array($policy)) {
            return null;
        }
        $video = $policy['video'] ?? null;
        if (!is_array($video)) {
            return null;
        }
        $provider = strtolower(trim((string)($video['provider'] ?? '')));
        $embedUrl = trim((string)($video['embed_url'] ?? ''));
        $path = trim((string)($video['path'] ?? ''));
        if ($provider === '' || ($embedUrl === '' && $path === '')) {
            return null;
        }

        return [
            'provider' => $provider,
            'provider_id' => trim((string)($video['provider_id'] ?? '')),
            'path' => $path,
            'embed_url' => $embedUrl !== '' ? $embedUrl : $path,
            'watch_url' => trim((string)($video['watch_url'] ?? '')),
            'poster_url' => trim((string)($video['poster_url'] ?? '')),
            'mime_type' => trim((string)($video['mime_type'] ?? '')),
        ];
    }

    private function isYouTubeHost(string $host): bool
    {
        return $host === 'youtu.be'
            || $host === 'youtube.com'
            || str_ends_with($host, '.youtube.com')
            || $host === 'youtube-nocookie.com'
            || str_ends_with($host, '.youtube-nocookie.com');
    }

    private function isVimeoHost(string $host): bool
    {
        return $host === 'vimeo.com'
            || str_ends_with($host, '.vimeo.com')
            || $host === 'player.vimeo.com';
    }

    /** @param array<string, mixed> $query */
    private function youtubeId(string $host, string $path, array $query): string
    {
        if ($host === 'youtu.be') {
            return $this->sanitizeYouTubeId(ltrim($path, '/'));
        }

        if (isset($query['v']) && is_scalar($query['v'])) {
            $fromQuery = $this->sanitizeYouTubeId((string)$query['v']);
            if ($fromQuery !== '') {
                return $fromQuery;
            }
        }

        if (preg_match('#/(?:embed|shorts|live|v)/([A-Za-z0-9_-]{6,})#', $path, $match) === 1) {
            return $this->sanitizeYouTubeId((string)$match[1]);
        }

        return '';
    }

    private function vimeoId(string $path): string
    {
        if (preg_match('#/(?:video/)?(\d{6,})#', $path, $match) === 1) {
            return (string)$match[1];
        }

        return '';
    }

    private function sanitizeYouTubeId(string $value): string
    {
        $value = trim($value);
        if (str_contains($value, '?')) {
            $value = (string)strtok($value, '?');
        }
        if (str_contains($value, '/')) {
            $value = basename($value);
        }
        if (preg_match('/^[A-Za-z0-9_-]{6,32}$/', $value) !== 1) {
            return '';
        }

        return $value;
    }

    /** @param array<string, mixed> $query */
    private function isDirectFileUrl(string $path, array $query): bool
    {
        $basename = strtolower(basename(parse_url($path, PHP_URL_PATH) ?: $path));
        if (preg_match('/\.(mp4|webm|ogg|ogv|m4v)(?:$|\?)/i', $basename) === 1) {
            return true;
        }
        // Some CDNs omit extensions but advertise format in query; reject those for safety.
        unset($query);

        return false;
    }

    private function mimeFromPath(string $path): string
    {
        $basename = strtolower(basename(parse_url($path, PHP_URL_PATH) ?: $path));
        return match (true) {
            str_ends_with($basename, '.webm') => 'video/webm',
            str_ends_with($basename, '.ogg'), str_ends_with($basename, '.ogv') => 'video/ogg',
            default => 'video/mp4',
        };
    }

    private function canonicalizeHttpsUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('product_media_video_url_invalid');
        }
        $scheme = strtolower((string)$parts['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            throw new \InvalidArgumentException('product_media_video_url_invalid');
        }
        $host = strtolower((string)$parts['host']);
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $path = (string)($parts['path'] ?? '/');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . $port . $path . $query;
    }
}
