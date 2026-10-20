<?php

declare(strict_types=1);

namespace Weline\Framework\View\Helper;

use Weline\Framework\App\State;

/**
 * Single admission gate for process-level HTML caches (FPC, hook output,
 * reusable template fragments). Reject — do not rewrite — poisoned bodies.
 */
final class HtmlCacheAdmission
{
    public static function admit(string $html): bool
    {
        if ($html === '') {
            return false;
        }

        if (!EmbeddedPageTitle::htmlAdmissible($html)) {
            return false;
        }

        return self::storefrontProductCardCssOk($html);
    }

    /**
     * Path-locale FPC identity must not store/serve a body frozen under another
     * locale (e.g. /en_GB leaf with data-local=zh_Hans_CN). Unprefixed URLs skip.
     */
    public static function admitPathLocale(string $html, string $fullUriOrPath): bool
    {
        if ($html === '') {
            return false;
        }
        $pathLang = self::extractPathLanguage($fullUriOrPath);
        if ($pathLang === '') {
            return true;
        }
        $bodyLang = self::extractBodyLanguage($html);
        if ($bodyLang === '') {
            return false;
        }

        return \strcasecmp(
            \str_replace('-', '_', $pathLang),
            \str_replace('-', '_', $bodyLang)
        ) === 0;
    }

    public static function extractPathLanguage(string $fullUriOrPath): string
    {
        $fullUriOrPath = \trim($fullUriOrPath);
        if ($fullUriOrPath === '') {
            return '';
        }
        try {
            if (\str_contains($fullUriOrPath, '://') || \str_starts_with($fullUriOrPath, '/')) {
                $path = (string)(\parse_url(
                    \str_contains($fullUriOrPath, '://') ? $fullUriOrPath : ('https://local.invalid' . $fullUriOrPath),
                    \PHP_URL_PATH
                ) ?: '');
            } else {
                $path = '/' . \ltrim($fullUriOrPath, '/');
            }
        } catch (\ValueError) {
            return '';
        }
        if ($path === '') {
            return '';
        }
        $segments = \array_values(\array_filter(
            \explode('/', \trim($path, '/')),
            static fn(string $segment): bool => $segment !== ''
        ));
        if ($segments === []) {
            return '';
        }
        try {
            $localized = State::resolveLocalizationFromPathSegments($segments);
            $lang = \str_replace('-', '_', \trim((string)($localized['language'] ?? '')));

            return $lang;
        } catch (\Throwable) {
            foreach ($segments as $segment) {
                if (\preg_match('/^[a-z]{2}(_[A-Za-z0-9]+)+$/', $segment) === 1) {
                    return \str_replace('-', '_', $segment);
                }
            }

            return '';
        }
    }

    public static function extractBodyLanguage(string $html): string
    {
        if (\preg_match('/\bdata-local\s*=\s*["\']([^"\']+)["\']/i', $html, $match) === 1) {
            return \str_replace('-', '_', \trim((string)$match[1]));
        }
        if (\preg_match('/<html\b[^>]*\blang\s*=\s*["\']([^"\']+)["\']/i', $html, $match) === 1) {
            return \str_replace('-', '_', \trim((string)$match[1]));
        }

        return '';
    }

    /**
     * Heal missing product-card CSS before FPC/hook admission.
     *
     * Slot/DOM repair and asset extract can drop the stylesheet declaration while leaving
     * cards, which permanently blocks Full Page Cache publish. Re-inject once.
     */
    public static function healStorefrontProductCardCss(string $html): string
    {
        if ($html === '' || self::storefrontProductCardCssOk($html)) {
            return $html;
        }

        $tag = self::buildProductCardStyleTag();
        if ($tag === '') {
            return $html;
        }

        $headEnd = stripos($html, '</head>');
        if ($headEnd !== false) { return substr_replace($html, $tag, $headEnd, 0); }

        if (\preg_match('/<body\b[^>]*>/i', $html, $match, \PREG_OFFSET_CAPTURE) === 1) {
            $offset = (int)$match[0][1] + \strlen($match[0][0]);

            return \substr($html, 0, $offset) . $tag . \substr($html, $offset);
        }

        $cardPos = \stripos($html, 'data-testid="weline-product-card"');
        if ($cardPos === false) {
            $cardPos = \stripos($html, "data-testid='weline-product-card'");
        }
        if ($cardPos !== false) {
            return \substr($html, 0, $cardPos) . $tag . \substr($html, $cardPos);
        }

        return $tag . $html;
    }

    /**
     * Storefront pages that render canonical product cards must carry the external
     * product-card CSS marker.
     */
    public static function storefrontProductCardCssOk(string $body): bool
    {
        $hasCard = \str_contains($body, 'data-testid="weline-product-card"')
            || \str_contains($body, "data-testid='weline-product-card'");
        if (!$hasCard) {
            return true;
        }

        return \str_contains($body, 'data-weline-product-card-css');
    }

    /**
     * Framework-only heal tag: marker required by {@see storefrontProductCardCssOk}.
     * Product owns richer emission (version / instance styles) at render time —
     * admission must not soft-pull Product FQCN.
     */
    private static function buildProductCardStyleTag(): string
    {
        try {
            $url = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\View\Template::class)
                ->fetchTagSource(\Weline\Framework\View\Data\DataInterface::dir_type_STATICS, 'Weline_Product::css/frontend/product-card.css');
            $url = \trim((string)$url);
            if ($url === '') {
                return '';
            }

            return '<link rel="stylesheet" data-weline-product-card-css="1" data-weline-widget-asset="source" data-weline-source-position="head" href="'
                . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        } catch (\Throwable) {
            return '';
        }
    }
}

