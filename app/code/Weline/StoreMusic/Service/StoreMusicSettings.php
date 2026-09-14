<?php

declare(strict_types=1);

namespace Weline\StoreMusic\Service;

use Weline\Framework\App\State;
use Weline\SystemConfig\Api\ConfigReader;

/**
 * Scoped entrance-music (进店音乐) settings from SystemConfig.
 */
class StoreMusicSettings
{
    public const MODULE = 'Weline_StoreMusic';
    public const AREA = ConfigReader::area_FRONTEND;

    public const KEY_ENABLED = 'store_music/general/enabled';
    /** First-track URL mirrored for media picker sync; playlist is authoritative. */
    public const KEY_TRACK = 'store_music/music/track';
    public const KEY_PLAYLIST = 'store_music/music/playlist';
    public const KEY_DELAY_SECONDS = 'store_music/music/delay_seconds';
    public const KEY_TRY_AUTOPLAY = 'store_music/music/try_autoplay';
    public const KEY_LOOP = 'store_music/music/loop';
    public const KEY_DEFAULT_VOLUME = 'store_music/music/default_volume';
    public const KEY_WAVEFORM_DEFAULT = 'store_music/visual/waveform_default';

    public function __construct(
        private readonly ConfigReader $config,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->boolean(self::KEY_ENABLED, true);
    }

    public function trackUrl(): string
    {
        $tracks = $this->tracks();
        if ($tracks === []) {
            return '';
        }

        return (string)($tracks[0]['url'] ?? '');
    }

    /**
     * Admin / storage shape: intro is a locale map.
     *
     * @return list<array{url:string,title:string,intro:array<string,string>}>
     */
    public function tracks(?string $scope = null): array
    {
        $fromPlaylist = self::parsePlaylistJson($this->string(self::KEY_PLAYLIST, $scope));
        if ($fromPlaylist !== []) {
            return $fromPlaylist;
        }

        $legacy = $this->string(self::KEY_TRACK, $scope);
        if ($legacy === '') {
            return [];
        }

        return self::tracksFromMediaCsv($legacy);
    }

    /**
     * Storefront shape: intro resolved to a single string for the request locale.
     *
     * @return list<array{url:string,title:string,intro:string}>
     */
    public function tracksForFrontend(?string $scope = null, ?string $locale = null): array
    {
        $locale = $locale !== null && trim($locale) !== ''
            ? trim($locale)
            : self::currentRequestLocale();
        $out = [];
        foreach ($this->tracks($scope) as $track) {
            $out[] = [
                'url' => (string)($track['url'] ?? ''),
                'title' => (string)($track['title'] ?? ''),
                'intro' => self::resolveIntroForLocale(
                    is_array($track['intro'] ?? null) ? $track['intro'] : [],
                    $locale
                ),
            ];
        }

        return $out;
    }

    public function isWidgetActive(): bool
    {
        return $this->isEnabled() && $this->tracks() !== [];
    }

    public function delaySeconds(): int
    {
        $value = (int)$this->string(self::KEY_DELAY_SECONDS);
        if ($value <= 0) {
            $value = 3;
        }

        return max(1, min(15, $value));
    }

    public function tryAutoplay(): bool
    {
        return $this->boolean(self::KEY_TRY_AUTOPLAY, true);
    }

    public function loop(): bool
    {
        return $this->boolean(self::KEY_LOOP, true);
    }

    public function defaultVolume(): int
    {
        $value = (int)$this->string(self::KEY_DEFAULT_VOLUME);
        if ($this->string(self::KEY_DEFAULT_VOLUME) === '') {
            $value = 12;
        }

        return max(0, min(100, $value));
    }

    public function waveformDefault(): bool
    {
        return $this->boolean(self::KEY_WAVEFORM_DEFAULT, false);
    }

    /**
     * @return array{
     *   enabled: bool,
     *   track: string,
     *   tracks: list<array{url:string,title:string,intro:string}>,
     *   delay_seconds: int,
     *   try_autoplay: bool,
     *   loop: bool,
     *   default_volume: int,
     *   waveform_default: bool
     * }
     */
    public function frontendPayload(): array
    {
        $tracks = $this->tracksForFrontend();

        return [
            'enabled' => $this->isEnabled(),
            'track' => $tracks[0]['url'] ?? '',
            'tracks' => $tracks,
            'delay_seconds' => $this->delaySeconds(),
            'try_autoplay' => $this->tryAutoplay(),
            'loop' => $this->loop(),
            'default_volume' => $this->defaultVolume(),
            'waveform_default' => $this->waveformDefault(),
        ];
    }

    /**
     * Normalize media picker CSV / single URL into playlist rows.
     *
     * @param array<string, array{url?:string,title?:string,intro?:string|array<string,string>}> $previousByUrl
     * @return list<array{url:string,title:string,intro:array<string,string>}>
     */
    public static function tracksFromMediaCsv(string $csv, array $previousByUrl = []): array
    {
        $parts = preg_split('/\s*,\s*/', trim($csv)) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $url = self::normalizeMediaUrl((string)$part);
            if ($url === '') {
                continue;
            }
            $prev = $previousByUrl[$url] ?? null;
            $title = is_array($prev) && trim((string)($prev['title'] ?? '')) !== ''
                ? trim((string)$prev['title'])
                : self::titleFromUrl($url);
            $intro = is_array($prev) ? self::normalizeIntroMap($prev['intro'] ?? []) : [];
            $out[] = [
                'url' => $url,
                'title' => $title,
                'intro' => $intro,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{url:string,title:string,intro:array<string,string>}>
     */
    public static function parsePlaylistJson(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = self::normalizeMediaUrl((string)($row['url'] ?? $row['path'] ?? ''));
            if ($url === '') {
                continue;
            }
            $title = trim((string)($row['title'] ?? ''));
            if ($title === '') {
                $title = self::titleFromUrl($url);
            }
            $out[] = [
                'url' => $url,
                'title' => mb_substr($title, 0, 120),
                'intro' => self::normalizeIntroMap($row['intro'] ?? []),
            ];
        }

        return $out;
    }

    /**
     * @param list<array{url?:string,title?:string,intro?:string|array<string,string>}> $tracks
     */
    public static function encodePlaylist(array $tracks): string
    {
        $normalized = [];
        foreach ($tracks as $track) {
            if (!is_array($track)) {
                continue;
            }
            $url = self::normalizeMediaUrl((string)($track['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $title = trim((string)($track['title'] ?? ''));
            if ($title === '') {
                $title = self::titleFromUrl($url);
            }
            $normalized[] = [
                'url' => $url,
                'title' => mb_substr($title, 0, 120),
                'intro' => self::normalizeIntroMap($track['intro'] ?? []),
            ];
        }

        return (string)json_encode(array_values($normalized), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param list<array{url?:string,title?:string,intro?:mixed}> $tracks
     */
    public static function mediaCsvFromTracks(array $tracks): string
    {
        $urls = [];
        foreach ($tracks as $track) {
            $url = self::normalizeMediaUrl((string)($track['url'] ?? ''));
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return implode(',', $urls);
    }

    /**
     * @param mixed $intro
     * @return array<string, string>
     */
    public static function normalizeIntroMap(mixed $intro, string $fallbackLocale = 'default'): array
    {
        if (is_string($intro)) {
            $text = mb_substr(trim($intro), 0, 500);
            if ($text === '') {
                return [];
            }
            $locale = trim($fallbackLocale) !== '' ? trim($fallbackLocale) : 'default';

            return [$locale => $text];
        }
        if (!is_array($intro)) {
            return [];
        }
        $out = [];
        foreach ($intro as $locale => $text) {
            $code = trim((string)$locale);
            $value = mb_substr(trim((string)$text), 0, 500);
            if ($code === '' || $value === '') {
                continue;
            }
            $out[$code] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, string> $map
     */
    public static function resolveIntroForLocale(array $map, string $locale): string
    {
        $locale = trim($locale);
        if ($locale !== '' && isset($map[$locale]) && trim($map[$locale]) !== '') {
            return trim($map[$locale]);
        }
        if ($locale !== '') {
            $lang = strtolower((string)explode('_', $locale)[0]);
            if ($lang !== '') {
                foreach ($map as $code => $text) {
                    $codeLang = strtolower((string)explode('_', (string)$code)[0]);
                    if ($codeLang === $lang && trim((string)$text) !== '') {
                        return trim((string)$text);
                    }
                }
            }
        }
        if (isset($map['default']) && trim($map['default']) !== '') {
            return trim($map['default']);
        }
        foreach ($map as $text) {
            if (trim((string)$text) !== '') {
                return trim((string)$text);
            }
        }

        return '';
    }

    public static function currentRequestLocale(): string
    {
        try {
            $locale = trim((string)State::getLangLocal());
            if ($locale !== '') {
                return $locale;
            }
        } catch (\Throwable) {
            // Soft.
        }
        try {
            $lang = trim((string)State::getLang());
            if ($lang !== '') {
                return $lang;
            }
        } catch (\Throwable) {
            // Soft.
        }

        return 'default';
    }

    public static function normalizeMediaUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('~^https?://~i', $value) === 1) {
            return $value;
        }
        $value = str_replace('\\', '/', $value);
        $value = preg_replace('~^/+~', '', $value) ?? $value;
        if (str_starts_with($value, 'pub/media/')) {
            $value = substr($value, strlen('pub/media/'));
        }
        if (str_starts_with($value, 'media/')) {
            return '/' . $value;
        }

        return '/media/' . ltrim($value, '/');
    }

    public static function titleFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $base = basename(is_string($path) && $path !== '' ? $path : $url);
        $base = rawurldecode($base);
        $base = preg_replace('/\.[a-z0-9]{2,5}$/i', '', $base) ?? $base;
        $base = trim(str_replace(['_', '+'], ' ', $base));

        return $base !== '' ? mb_substr($base, 0, 120) : '进店音乐';
    }

    private function string(string $key, ?string $scope = null): string
    {
        return trim((string)$this->config->get($key, self::MODULE, self::AREA, '', $scope));
    }

    private function boolean(string $key, bool $default): bool
    {
        $value = $this->config->get($key, self::MODULE, self::AREA, $default ? '1' : '0');
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
