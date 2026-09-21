<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\WidgetI18n;

/**
 * 织艺谱系卡片文案跟项目已发布文章标题走，不再用部件自带的 caption_en 短译。
 */
final class TextileHeritageLabels
{
    public static function blogSlugFromLink(string $link): string
    {
        $path = trim($link);
        if ($path === '') {
            return '';
        }
        $withoutHost = preg_replace('#^[a-z][a-z0-9+.-]*://[^/]+#i', '', $path);
        $path = is_string($withoutHost) ? $withoutHost : $path;
        $path = trim(strtok($path, '?#') ?: $path, '/');
        if (preg_match('#(?:^|/)blog/([a-z0-9-]+)$#i', $path, $matches) !== 1) {
            return '';
        }

        return strtolower($matches[1]);
    }

    /**
     * 当前语种已有对应文章时用文章标题；否则回落到项目词典，不再套 caption_en。
     */
    public static function displayName(string $source, string $link, string $locale): string
    {
        $articleTitle = self::articleTitle($link, $locale);
        if ($articleTitle !== '') {
            return $articleTitle;
        }

        $source = trim($source);
        if ($source === '') {
            return '';
        }
        $translated = trim(WidgetI18n::label($source));

        return $translated !== '' ? $translated : $source;
    }

    public static function articleTitle(string $link, string $locale): string
    {
        $slug = self::blogSlugFromLink($link);
        $locale = trim(str_replace('-', '_', $locale));
        if ($slug === '' || $locale === '' || !class_exists(\Weline\Blog\Service\BlogContentResolver::class)) {
            return '';
        }

        try {
            /** @var \Weline\Blog\Service\BlogContentResolver $resolver */
            $resolver = ObjectManager::getInstance(\Weline\Blog\Service\BlogContentResolver::class);
            /** @var \Weline\Blog\Service\BlogScopeResolver $scope */
            $scope = ObjectManager::getInstance(\Weline\Blog\Service\BlogScopeResolver::class);
            $article = $resolver->resolveBySlug($scope->websiteId(), $locale, $slug);
        } catch (\Throwable) {
            return '';
        }

        if ($article === null) {
            return '';
        }
        $articleLocale = str_replace('-', '_', trim($article->locale));
        if ($articleLocale === '' || strcasecmp($articleLocale, $locale) !== 0) {
            return '';
        }

        return trim($article->title);
    }
}
