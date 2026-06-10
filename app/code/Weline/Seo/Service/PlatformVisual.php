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
    ];

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

    public function renderIcon(string $platform, string $name = '', ?string $color = null, int $size = 32, string $class = ''): string
    {
        $size = max(20, min(64, $size));
        $code = $this->normalizeCode($platform);
        $label = $this->getIconText($code);
        $label = substr($label, 0, 3);
        $fontSize = strlen($label) >= 3 ? 8 : (strlen($label) === 2 ? 10 : 13);
        $color = $this->sanitizeColor($color);
        $title = $name !== '' ? $name : strtoupper($code);
        $classAttr = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' : '';

        return sprintf(
            '<svg%s width="%d" height="%d" viewBox="0 0 32 32" role="img" aria-label="%s" xmlns="http://www.w3.org/2000/svg"><rect x="2" y="2" width="28" height="28" rx="7" fill="%s"/><circle cx="20.5" cy="11.5" r="3.5" fill="none" stroke="#fff" stroke-width="2"/><path d="M23 14.5 26 17.5" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/><text x="16" y="23.4" text-anchor="middle" font-size="%d" font-family="Arial, Helvetica, sans-serif" font-weight="700" fill="#fff">%s</text></svg>',
            $classAttr,
            $size,
            $size,
            htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($color, ENT_QUOTES, 'UTF-8'),
            $fontSize,
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        );
    }
}
