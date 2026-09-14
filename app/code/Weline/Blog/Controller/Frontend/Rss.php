<?php

declare(strict_types=1);

namespace Weline\Blog\Controller\Frontend;

use Weline\Blog\Api\Data\BlogArticle;
use Weline\Blog\Api\Uri\BlogNamespace;
use Weline\Blog\Service\BlogContentResolver;
use Weline\Blog\Service\BlogScopeResolver;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Response;
use Weline\Framework\Xml\RssFeedWriter;
use Weline\Theme\Helper\WidgetI18n;

/** Reader RSS: /blog/rss.xml and /blog/category/{slug}/rss.xml */
final class Rss extends FrontendController
{
    private const LIMIT = 50;

    public function __construct(
        private readonly BlogContentResolver $resolver,
        private readonly BlogScopeResolver $scope,
        private readonly RssFeedWriter $rssWriter = new RssFeedWriter(),
    ) {
    }

    public function index(): Response
    {
        $websiteId = $this->scope->websiteId();
        $locale = $this->scope->locale();
        $baseUrl = $this->scope->baseUrl();
        if ($baseUrl === '') {
            $baseUrl = $this->requestOrigin();
        }
        $categorySlug = strtolower(trim((string)$this->request->getParam('category_slug', '')));

        $channelTitle = WidgetI18n::label('博客');
        $channelLink = $this->absolutePath(BlogNamespace::publicPath(), $baseUrl);
        $channelDescription = WidgetI18n::label('最新博客文章');
        $articles = [];

        if ($categorySlug !== '') {
            $active = $this->resolver->resolveCategoryBySlug($websiteId, $categorySlug, $locale);
            if ($active === null) {
                $this->noRouter();

                return Response::text('Not Found', 404, 'text/plain; charset=utf-8');
            }
            $categoryId = (int)($active['category_id'] ?? 0);
            $channelTitle = (string)($active['name'] ?? $categorySlug);
            $channelLink = $this->absolutePath(BlogNamespace::categoryPublicPath($categorySlug), $baseUrl);
            $channelDescription = WidgetI18n::label('分类「%{1}」的最新文章', '', [$channelTitle]);
            $articles = $categoryId > 0
                ? $this->resolver->listPublishedArticlesByCategory(
                    $websiteId,
                    $locale,
                    $categoryId,
                    self::LIMIT,
                    $baseUrl,
                )
                : [];
        } else {
            $articles = $this->resolver->listPublishedArticles(
                $websiteId,
                $locale,
                self::LIMIT,
                $baseUrl,
            );
        }

        $items = [];
        $lastBuild = '';
        foreach ($articles as $article) {
            if (!$article instanceof BlogArticle) {
                continue;
            }
            $link = $this->articleLink($article, $baseUrl);
            $pubDate = $this->toRfc822($article->publishedAt);
            if ($pubDate !== '' && ($lastBuild === '' || strtotime($pubDate) > strtotime($lastBuild))) {
                $lastBuild = $pubDate;
            }
            $description = trim($article->excerpt);
            $items[] = [
                'title' => $article->title,
                'link' => $link,
                'guid' => $link !== '' ? $link : $article->identifier,
                'pubDate' => $pubDate,
                'description' => $description,
                'author' => trim((string)($article->author ?? '')),
            ];
        }

        $xml = $this->rssWriter->write(
            [
                'title' => $channelTitle,
                'link' => $channelLink,
                'description' => $channelDescription,
                'lastBuildDate' => $lastBuild !== '' ? $lastBuild : date('r'),
            ],
            $items,
        );

        return Response::text($xml, 200, 'application/rss+xml; charset=utf-8');
    }

    private function articleLink(BlogArticle $article, string $baseUrl): string
    {
        $canonical = trim($article->canonicalUrl);
        if ($canonical !== '' && preg_match('#^https?://#i', $canonical) === 1) {
            return $canonical;
        }
        $public = trim($article->publicUrl);
        if ($public === '') {
            return $this->absolutePath(BlogNamespace::publicPath($article->slug), $baseUrl);
        }
        if (preg_match('#^https?://#i', $public) === 1) {
            return $public;
        }

        return $this->absolutePath($public, $baseUrl);
    }

    private function absolutePath(string $path, string $baseUrl): string
    {
        $path = '/' . ltrim($path, '/');
        $baseUrl = rtrim($baseUrl, '/');
        if ($baseUrl === '') {
            return $path;
        }

        return $baseUrl . $path;
    }

    private function toRfc822(?string $datetime): string
    {
        $datetime = trim((string)$datetime);
        if ($datetime === '') {
            return '';
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return '';
        }

        return date('r', $ts);
    }

    private function requestOrigin(): string
    {
        $host = trim((string)($this->request->getServer('HTTP_HOST') ?: ''));
        if ($host === '') {
            return '';
        }
        $https = (string)($this->request->getServer('HTTPS') ?: '');
        $forwarded = strtolower((string)($this->request->getServer('HTTP_X_FORWARDED_PROTO') ?: ''));
        $scheme = ($https !== '' && $https !== 'off') || $forwarded === 'https' ? 'https' : 'http';

        return $scheme . '://' . $host;
    }
}
