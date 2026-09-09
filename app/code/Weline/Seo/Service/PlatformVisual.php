<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

class PlatformVisual
{
    private const DEFAULT_COLOR = '#64748B';

    private const ALIASES = [
        'google_indexing_api' => 'google',
        'google_search_console' => 'google',
        'bing_webmaster' => 'bing',
        'bing_indexnow' => 'bing',
        'baidu_push_api' => 'baidu',
        'baidu_zhanzhang' => 'baidu',
        'yandex_indexnow' => 'yandex',
        'naver_indexnow' => 'naver',
        'naver_searchadvisor' => 'naver',
        'naver_crawl_request' => 'naver',
        'seznam_indexnow' => 'seznam',
        'yep_indexnow' => 'yep',
        'internetarchive_indexnow' => 'internetarchive',
        'amazonbot_indexnow' => 'amazonbot',
    ];

    private const ICON_TEXT = [
        'seo' => 'SEO',
        'google' => 'G',
        'bing' => 'B',
        'baidu' => 'BD',
        'yandex' => 'Y',
        'naver' => 'N',
        'seznam' => 'S',
        'yep' => 'YEP',
        'yahoo' => 'Y!',
        'duckduckgo' => 'DDG',
        '360' => '360',
        'sogou' => 'SG',
        'shenma' => 'SM',
        'toutiao' => 'TT',
        'internetarchive' => 'IA',
        'amazonbot' => 'AZ',
        'brave' => 'BR',
        'qwant' => 'Q',
        'ecosia' => 'E',
        'startpage' => 'SP',
        'swisscows' => 'SC',
        'mojeek' => 'MJ',
        'petal' => 'P',
        'daum' => 'D',
        'coccoc' => 'CC',
        'mailru' => 'MR',
        'rambler' => 'R',
        'you' => 'YOU',
        'kagi' => 'K',
        'aol' => 'AOL',
        'ask' => 'ASK',
        'quark' => 'QK',
        'metager' => 'MG',
        'gibiru' => 'GB',
        'indexnow' => 'IN',
    ];

    /** @var array<string, string|null> */
    private array $logoSvgCache = [];

    public function normalizeCode(string $platform): string
    {
        $code = strtolower(trim($platform));
        return self::ALIASES[$code] ?? $code;
    }

    public function getIconText(string $platform): string
    {
        $code = $this->normalizeCode($platform);
        if (isset(self::ICON_TEXT[$code])) {
            return self::ICON_TEXT[$code];
        }

        $parts = preg_split('/[^a-z0-9]+/', $code) ?: [];
        $text = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $text .= strtoupper(substr($part, 0, 1));
            if (strlen($text) >= 3) {
                break;
            }
        }

        return $text !== '' ? $text : 'SEO';
    }

    public function sanitizeColor(?string $color): string
    {
        $color = trim((string)$color);
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1) {
            return strtoupper($color);
        }

        return self::DEFAULT_COLOR;
    }

    public function hasLogoAsset(string $platform): bool
    {
        return $this->loadLogoSvg($this->normalizeCode($platform)) !== null;
    }

    public function logoDirectory(): string
    {
        return dirname(__DIR__) . '/view/statics/platform-logos';
    }

    public function renderIcon(string $platform, string $name = '', ?string $color = null, int $size = 32, string $class = ''): string
    {
        $size = max(20, min(64, $size));
        $code = $this->normalizeCode($platform);
        $title = $name !== '' ? $name : strtoupper($code);
        $classes = trim('seo-platform-logo ' . $class);
        $classAttr = ' class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '"';
        $aria = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        $logo = $this->loadLogoSvg($code);
        if ($logo !== null) {
            $svg = preg_replace('/<svg\b/', '<svg width="' . $size . '" height="' . $size . '"', $logo, 1) ?? $logo;
            if (!str_contains($svg, 'aria-label=')) {
                $svg = preg_replace('/<svg\b/', '<svg aria-label="' . $aria . '"', $svg, 1) ?? $svg;
            }
            if (!str_contains($svg, 'role=')) {
                $svg = preg_replace('/<svg\b/', '<svg role="img"', $svg, 1) ?? $svg;
            }

            return '<span' . $classAttr . ' data-seo-platform-logo="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '" style="width:' . $size . 'px;height:' . $size . 'px">' . $svg . '</span>';
        }

        return $this->renderFallbackGlyph($code, $title, $color, $size, $classes);
    }

    private function loadLogoSvg(string $code): ?string
    {
        if (array_key_exists($code, $this->logoSvgCache)) {
            return $this->logoSvgCache[$code];
        }

        if (!preg_match('/^[a-z0-9]+$/', $code)) {
            $this->logoSvgCache[$code] = null;
            return null;
        }

        $path = $this->logoDirectory() . '/' . $code . '.svg';
        if (!is_file($path)) {
            $this->logoSvgCache[$code] = null;
            return null;
        }

        $raw = (string)file_get_contents($path);
        if ($raw === '' || !str_contains($raw, '<svg')) {
            $this->logoSvgCache[$code] = null;
            return null;
        }

        // Strip XML declaration / scripts for safe inline use.
        $raw = preg_replace('/<\?xml[^>]*>/', '', $raw) ?? $raw;
        $raw = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $raw) ?? $raw;
        $this->logoSvgCache[$code] = trim($raw);

        return $this->logoSvgCache[$code];
    }

    private function renderFallbackGlyph(string $code, string $title, ?string $color, int $size, string $classes): string
    {
        $label = substr($this->getIconText($code), 0, 3);
        $fontSize = strlen($label) >= 3 ? 8 : (strlen($label) === 2 ? 10 : 13);
        $color = $this->sanitizeColor($color);
        $classAttr = $classes !== '' ? ' class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '"' : '';

        return sprintf(
            '<span%s data-seo-platform-logo-fallback="%s" style="width:%dpx;height:%dpx"><svg width="%d" height="%d" viewBox="0 0 32 32" role="img" aria-label="%s" xmlns="http://www.w3.org/2000/svg"><rect x="2" y="2" width="28" height="28" rx="7" fill="%s"/><text x="16" y="20.5" text-anchor="middle" font-size="%d" font-family="Arial, Helvetica, sans-serif" font-weight="700" fill="#fff">%s</text></svg></span>',
            $classAttr,
            htmlspecialchars($code, ENT_QUOTES, 'UTF-8'),
            $size,
            $size,
            $size,
            $size,
            htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($color, ENT_QUOTES, 'UTF-8'),
            $fontSize,
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        );
    }
}
