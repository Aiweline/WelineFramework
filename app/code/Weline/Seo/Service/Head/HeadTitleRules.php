<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Head;

/**
 * 页面标题与站点标题组合规则（供 SEO / Frontend Head 解析共用）。
 */
final class HeadTitleRules
{
    public static function isModuleCodeTitle(string $title): bool
    {
        return (bool) preg_match('/^[A-Z][A-Za-z0-9]*_[A-Z][A-Za-z0-9_]*$/', trim($title));
    }

    public static function sanitizeTitle(string $title, ?string $moduleName = null): string
    {
        $title = trim($title);
        if ($title === '') {
            return '';
        }

        $moduleName = trim((string) $moduleName);
        if ($moduleName !== '' && $title === $moduleName) {
            return '';
        }

        if (self::isModuleCodeTitle($title)) {
            return '';
        }

        return $title;
    }

    public static function composePageAndSite(string $pageTitle, string $siteName, string $separator = ' | '): string
    {
        $pageTitle = trim($pageTitle);
        $siteName = trim($siteName);
        if ($pageTitle === '') {
            return $siteName;
        }
        if ($siteName === '') {
            return $pageTitle;
        }
        if (mb_strtolower($pageTitle) === mb_strtolower($siteName)) {
            return $pageTitle;
        }
        if (mb_strpos(mb_strtolower($pageTitle), mb_strtolower($siteName)) !== false) {
            return $pageTitle;
        }

        return $pageTitle . $separator . $siteName;
    }
}
