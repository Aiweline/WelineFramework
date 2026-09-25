<?php

declare(strict_types=1);

namespace Weline\StoreMusic\Service;

use Weline\Framework\App\State;
use Weline\SystemConfig\Api\ConfigReader;

/**
 * Entrance-music (进店音乐) settings.
 *
 * Primary source for layout-placed widgets: Theme widget params (部件配置).
 * SystemConfig remains the Hook fallback and legacy migration source when
 * widget tracks are empty.
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
    /** When true, spin the avatar while playing; default off — polar spectrum is the default ambience. */
    public const KEY_AVATAR_SPIN = 'store_music/visual/avatar_spin';

    public function __construct(
        private readonly ConfigReader $config,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->boolean(self::KEY_ENABLED, false);
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

    public function isWidgetActive(?array $widgetConfig = null): bool
    {
        $payload = $this->frontendPayload($widgetConfig);

        return !empty($payload['enabled']) && ($payload['tracks'] ?? []) !== [];
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
            $value = 8;
        }

        return max(0, min(100, $value));
    }

    public function waveformDefault(): bool
    {
        return $this->boolean(self::KEY_WAVEFORM_DEFAULT, false);
    }

    public function avatarSpin(): bool
    {
        return $this->boolean(self::KEY_AVATAR_SPIN, false);
    }

    /**
     * @param array<string, mixed>|null $widgetConfig Theme editor widget params when rendered from layout.
     * @return array{
     *   enabled: bool,
     *   track: string,
     *   tracks: list<array{url:string,title:string,intro:string}>,
     *   delay_seconds: int,
     *   try_autoplay: bool,
     *   loop: bool,
     *   default_volume: int,
     *   waveform_default: bool,
     *   avatar_spin: bool
     * }
     */
    public function frontendPayload(?array $widgetConfig = null): array
    {
        $systemTracks = $this->tracksForFrontend();
        $payload = [
            'enabled' => $this->isEnabled(),
            'track' => $systemTracks[0]['url'] ?? '',
            'tracks' => $systemTracks,
            'delay_seconds' => $this->delaySeconds(),
            'try_autoplay' => $this->tryAutoplay(),
            'loop' => $this->loop(),
            'default_volume' => $this->defaultVolume(),
            'waveform_default' => $this->waveformDefault(),
            'avatar_spin' => $this->avatarSpin(),
        ];

        if ($widgetConfig === null) {
            return $payload;
        }

        if (array_key_exists('enabled', $widgetConfig)) {
            $payload['enabled'] = self::coerceBool($widgetConfig['enabled'], $payload['enabled']);
        }
        if (array_key_exists('delay_seconds', $widgetConfig)) {
            $payload['delay_seconds'] = max(1, min(15, (int)$widgetConfig['delay_seconds']));
        }
        if (array_key_exists('try_autoplay', $widgetConfig)) {
            $payload['try_autoplay'] = self::coerceBool($widgetConfig['try_autoplay'], $payload['try_autoplay']);
        }
        if (array_key_exists('loop', $widgetConfig)) {
            $payload['loop'] = self::coerceBool($widgetConfig['loop'], $payload['loop']);
        }
        if (array_key_exists('default_volume', $widgetConfig)) {
            $payload['default_volume'] = max(0, min(100, (int)$widgetConfig['default_volume']));
        }
        if (array_key_exists('waveform_default', $widgetConfig)) {
            $payload['waveform_default'] = self::coerceBool(
                $widgetConfig['waveform_default'],
                $payload['waveform_default']
            );
        }
        if (array_key_exists('avatar_spin', $widgetConfig)) {
            $payload['avatar_spin'] = self::coerceBool($widgetConfig['avatar_spin'], $payload['avatar_spin']);
        }

        $widgetTracks = self::tracksFromWidgetConfig($widgetConfig['tracks'] ?? null);
        if ($widgetTracks !== []) {
            $payload['tracks'] = $widgetTracks;
            $payload['track'] = $widgetTracks[0]['url'] ?? '';
        }

        return $payload;
    }

    /**
     * Normalize Theme editor tracks array (url/title/intro) for storefront payload.
     *
     * @return list<array{url:string,title:string,intro:string}>
     */
    public static function tracksFromWidgetConfig(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = self::parsePlaylistJson($raw);
        }
        if (!is_array($raw)) {
            return [];
        }
        $locale = self::currentRequestLocale();
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = self::mediaUrlFromMixed($row['url'] ?? $row['path'] ?? '');
            if ($url === '') {
                continue;
            }
            $title = trim((string)($row['title'] ?? ''));
            if ($title === '') {
                $title = self::titleFromUrl($url);
            }
            $introRaw = $row['intro'] ?? '';
            if (is_array($introRaw)) {
                $intro = self::resolveIntroForLocale(self::normalizeIntroMap($introRaw), $locale);
            } else {
                $intro = mb_substr(trim((string)$introRaw), 0, 500);
            }
            $out[] = [
                'url' => $url,
                'title' => mb_substr($title, 0, 120),
                'intro' => $intro,
            ];
        }

        return $out;
    }

    /**
     * Collect widget param bag from a Theme template dictionary (null when Hook-only).
     *
     * @param object|null $template View template with getData()
     * @return array<string, mixed>|null
     */
    public static function widgetConfigFromTemplate(object $template): ?array
    {
        if (!method_exists($template, 'getData')) {
            return null;
        }
        $keys = [
            'enabled',
            'tracks',
            'delay_seconds',
            'try_autoplay',
            'loop',
            'default_volume',
            'avatar_spin',
            'waveform_default',
        ];
        $bag = [];
        $hit = false;
        foreach ($keys as $key) {
            $value = $template->getData($key);
            if ($value !== null) {
                $hit = true;
                $bag[$key] = $value;
            }
        }

        return $hit ? $bag : null;
    }

    /**
     * Hook body-end owns the audible float. Theme may stamp annotation /
     * default_injection defaults (enabled=false, tracks=[]) onto a shared
     * template bag; that must not override SystemConfig when the layout node
     * never set real tracks or an explicit enable.
     *
     * @param array<string, mixed>|null $fromTemplate
     * @return array<string, mixed>|null
     */
    public static function hookOwnedWidgetConfig(?array $fromTemplate): ?array
    {
        if ($fromTemplate === null) {
            return null;
        }
        $tracks = self::tracksFromWidgetConfig($fromTemplate['tracks'] ?? null);
        if ($tracks !== []) {
            return $fromTemplate;
        }
        $enabledOn = \array_key_exists('enabled', $fromTemplate)
            && self::coerceBool($fromTemplate['enabled'], false);
        if ($enabledOn) {
            return $fromTemplate;
        }
        $bag = $fromTemplate;
        unset($bag['enabled'], $bag['tracks']);

        return $bag === [] ? null : $bag;
    }

    public static function mediaUrlFromMixed(mixed $value): string
    {
        if (is_array($value)) {
            if (($value['type'] ?? null) === 'file-image') {
                $path = (string)($value['path'] ?? $value['file_path'] ?? $value['url'] ?? '');
                if ($path === '' && is_array($value['usage'] ?? null)) {
                    $path = (string)($value['usage']['path'] ?? $value['usage']['url'] ?? '');
                }

                return self::normalizeMediaUrl($path);
            }
            $path = (string)($value['url'] ?? $value['path'] ?? $value['src'] ?? '');

            return self::normalizeMediaUrl($path);
        }

        return self::normalizeMediaUrl((string)$value);
    }

    public static function coerceBool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
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
            return self::preferExistingMediaUrl('/' . $value);
        }

        return self::preferExistingMediaUrl('/media/' . ltrim($value, '/'));
    }

    /**
     * Prefer an on-disk media path when the configured URL extension is missing
     * (e.g. playlist .m4a while only .mp3 was uploaded).
     */
    public static function preferExistingMediaUrl(string $publicUrl): string
    {
        $publicUrl = trim($publicUrl);
        if ($publicUrl === '' || preg_match('~^https?://~i', $publicUrl) === 1) {
            return $publicUrl;
        }
        if (!str_starts_with($publicUrl, '/media/')) {
            return $publicUrl;
        }
        $relative = ltrim(substr($publicUrl, strlen('/media/')), '/');
        if ($relative === '' || !defined('BP')) {
            return $publicUrl;
        }
        $mediaRoot = rtrim((string) BP, '/\\') . '/pub/media';
        $primary = $mediaRoot . '/' . $relative;
        if (is_file($primary) && filesize($primary) > 1024) {
            return $publicUrl;
        }
        $pathInfo = pathinfo($relative);
        $dir = isset($pathInfo['dirname']) && $pathInfo['dirname'] !== '.' ? $pathInfo['dirname'] . '/' : '';
        $stem = (string) ($pathInfo['filename'] ?? '');
        if ($stem === '') {
            return $publicUrl;
        }
        $currentExt = strtolower((string) ($pathInfo['extension'] ?? ''));
        $candidates = ['mp3', 'm4a', 'ogg', 'wav', 'flac', 'aac'];
        foreach ($candidates as $ext) {
            if ($ext === $currentExt) {
                continue;
            }
            $candidateRel = $dir . $stem . '.' . $ext;
            $candidateAbs = $mediaRoot . '/' . $candidateRel;
            if (is_file($candidateAbs) && filesize($candidateAbs) > 1024) {
                return '/media/' . $candidateRel;
            }
        }
        // Fall back to any existing sibling file even if tiny (stops hard 404).
        if (is_file($primary)) {
            return $publicUrl;
        }
        foreach ($candidates as $ext) {
            if ($ext === $currentExt) {
                continue;
            }
            $candidateRel = $dir . $stem . '.' . $ext;
            $candidateAbs = $mediaRoot . '/' . $candidateRel;
            if (is_file($candidateAbs)) {
                return '/media/' . $candidateRel;
            }
        }

        return $publicUrl;
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
