<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Model\WebsiteLanguage;

/**
 * Storefront notice when the visitor URL names a language the website has not enabled.
 *
 * The request stays on that URL. Page copy uses the website default language.
 * The visitor chooses an enabled language; nothing redirects on its own.
 */
final class UnsupportedPathLanguageNotice
{
    public const CONTEXT_KEY = 'route.rejected_path_language';

    public function rejectedCode(): string
    {
        return trim((string) RequestContext::get(self::CONTEXT_KEY, ''));
    }

    public function isActive(): bool
    {
        return $this->rejectedCode() !== '';
    }

    /**
     * @return list<array{code: string, label: string, href: string}>
     */
    public function choices(string $requestUri): array
    {
        $rejected = $this->rejectedCode();
        if ($rejected === '') {
            return [];
        }
        $default = State::resolveWebsiteDefaultLanguage();
        $displayLocale = str_replace('_', '-', $default !== '' ? $default : 'zh_Hans_CN');
        $out = [];
        foreach ($this->enabledLanguageCodes() as $code) {
            $code = str_replace('-', '_', trim($code));
            if ($code === '' || strcasecmp($code, $rejected) === 0) {
                continue;
            }
            $out[] = [
                'code' => $code,
                'label' => self::displayName($code, $displayLocale),
                'href' => self::replaceLanguageSegment($requestUri, $rejected, $code),
            ];
        }

        return $out;
    }

    public function canonicalUri(string $requestUri): string
    {
        $rejected = $this->rejectedCode();
        if ($rejected === '') {
            return $requestUri;
        }
        $default = State::resolveWebsiteDefaultLanguage();
        if ($default === '') {
            return $requestUri;
        }

        return self::replaceLanguageSegment($requestUri, $rejected, $default);
    }

    public static function replaceLanguageSegment(string $uri, string $from, string $to): string
    {
        $from = str_replace('-', '_', trim($from));
        $to = str_replace('-', '_', trim($to));
        if ($from === '' || $to === '' || strcasecmp($from, $to) === 0) {
            return $uri;
        }

        $parts = parse_url($uri);
        if (!is_array($parts)) {
            return $uri;
        }
        $path = (string) ($parts['path'] ?? '');
        if ($path === '' && !isset($parts['host'])) {
            $path = $uri;
            $parts = [];
        }
        $segments = explode('/', $path);
        $replaced = false;
        foreach ($segments as $index => $segment) {
            $normalized = str_replace('-', '_', $segment);
            if ($normalized !== '' && strcasecmp($normalized, $from) === 0) {
                $segments[$index] = $to;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            return $uri;
        }
        $newPath = implode('/', $segments);
        if (!isset($parts['host'])) {
            $rebuilt = $newPath;
            if (isset($parts['query']) && $parts['query'] !== '') {
                $rebuilt .= '?' . $parts['query'];
            }

            return $rebuilt;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $auth = $parts['host'];
        if (isset($parts['port'])) {
            $auth .= ':' . $parts['port'];
        }
        $rebuilt = $scheme . $auth . $newPath;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $rebuilt .= '?' . $parts['query'];
        }

        return $rebuilt;
    }

    /**
     * @return list<string>
     */
    private function enabledLanguageCodes(): array
    {
        try {
            $websiteId = RequestContext::websiteId();
            /** @var WebsiteLanguage $languages */
            $languages = ObjectManager::getInstance(WebsiteLanguage::class);
            $codes = $languages->getWebsiteLanguageCodes($websiteId === null ? 0 : $websiteId);

            return is_array($codes) ? array_values($codes) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private static function displayName(string $code, string $displayLocale): string
    {
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayName(str_replace('_', '-', $code), $displayLocale);
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return $code;
    }
}
