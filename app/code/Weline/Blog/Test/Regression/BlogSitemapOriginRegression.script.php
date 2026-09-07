<?php
declare(strict_types=1);

/** Real provider/builder/DTOs with read-only fixtures; no DB, HTTP, or publishing. */
namespace Weline\Framework\Manager {
    class ObjectManager { public static function getInstance(string $class): object { return new $class(); } }
}
namespace Weline\Seo\Model { class SitemapUrl {} }
namespace Weline\Blog\Service {
    class BlogScopeResolver { public function baseUrl(): string { return 'https://another-website.invalid'; } }
    class CmsBlogPageAdapter {}
    class BlogContentResolver {
        public array $calls = [];
        public ?array $articles = null;
        public function listPublishedArticles(int $websiteId, string $locale, int $limit, string $baseUrl): array {
            $this->calls[] = [$websiteId, $baseUrl];
            if ($this->articles !== null) return $this->articles;
            return [
                \Weline\Blog\Api\Data\BlogArticle::fromArray(['public_url' => '/blog/fixture', 'source_ref' => ['post_id' => 1]]),
                \Weline\Blog\Api\Data\BlogArticle::fromArray(['public_url' => 'https://existing.example/blog/absolute', 'source_ref' => ['post_id' => 2]]),
            ];
        }
        public function resolveBySlug(int $websiteId, string $locale, string $slug, string $baseUrl = ''): ?\Weline\Blog\Api\Data\BlogArticle {
            foreach ($this->articles ?? [] as $article) {
                if ($article->contentKind === 'blog_post' && ($article->sourceRef['storage_slug'] ?? '') === $slug) return $article;
            }
            return null;
        }
    }
}
namespace {
    function __(string $message): string { return $message; }
    $root = dirname(__DIR__, 6);
    foreach (['Interface/SitemapUrlProviderInterface.php', 'Api/Sitemap/Data/Website.php', 'Api/Sitemap/WebsiteDirectoryInterface.php', 'Api/Sitemap/AbstractSitemapUrlProvider.php'] as $file) {
        require $root . '/app/code/Weline/Seo/' . $file;
    }
    foreach (['Api/Data/BlogArticle.php', 'Api/Uri/BlogNamespace.php', 'Service/BlogSitemapUrlBuilder.php', 'extends/module/Weline_Seo/SitemapUrlProvider/BlogSitemapUrlProvider.php'] as $file) {
        require $root . '/app/code/Weline/Blog/' . $file;
    }
    $directory = new class implements \Weline\Seo\Api\Sitemap\WebsiteDirectoryInterface {
        public function all(): array { return [$this->get(0), $this->get(4)]; }
        public function get(int $websiteId): ?\Weline\Seo\Api\Sitemap\Data\Website {
            return match ($websiteId) {
                0 => new \Weline\Seo\Api\Sitemap\Data\Website(0, 'Default', 'default', 'http://localhost/'),
                4 => new \Weline\Seo\Api\Sitemap\Data\Website(4, 'Shop', 'shop', 'https://target.example:8443/deployment/'),
                default => null,
            };
        }
    };
    $resolver = new \Weline\Blog\Service\BlogContentResolver();
    $builder = new \Weline\Blog\Service\BlogSitemapUrlBuilder($resolver, new \Weline\Blog\Service\CmsBlogPageAdapter());
    $provider = new \Weline\Blog\Extends\Module\Weline_Seo\SitemapUrlProvider\BlogSitemapUrlProvider($builder, new \Weline\Blog\Service\BlogScopeResolver(), $directory);
    $failed = 0;
    foreach ([0 => 'http://localhost', 4 => 'https://target.example:8443/deployment'] as $websiteId => $base) {
        try {
            $rows = $provider->getUrlsForWebsite($websiteId);
            $expected = [$base . '/blog', $base . '/blog/fixture', 'https://existing.example/blog/absolute'];
            if (array_column($rows, 'loc') !== $expected || end($resolver->calls) !== [$websiteId, $base]) {
                throw new \RuntimeException('sitemap loc or resolver base did not use the target website origin');
            }
            $article = \Weline\Blog\Api\Data\BlogArticle::fromArray(['public_url' => '/blog/fixture']);
            if ($builder->articleToUrl($article)['loc'] !== '/blog/fixture' || $article->publicUrl !== '/blog/fixture') {
                throw new \RuntimeException('frontend publicUrl contract changed');
            }
            echo "PASS: website {$websiteId} uses canonical origin and keeps frontend publicUrl\n";
        } catch (\Throwable $e) { $failed++; echo "FAIL: website {$websiteId}: {$e->getMessage()}\n"; }
    }
    try {
        $resolver->articles = [
            \Weline\Blog\Api\Data\BlogArticle::fromArray(['content_kind' => 'blog_post', 'locale' => 'en_US', 'public_url' => '/blog/paired', 'source_ref' => ['post_id' => 326, 'storage_slug' => 'paired-en']]),
            \Weline\Blog\Api\Data\BlogArticle::fromArray(['content_kind' => 'blog_post', 'locale' => 'zh_Hans_CN', 'public_url' => '/blog/paired', 'source_ref' => ['post_id' => 325, 'storage_slug' => 'paired']]),
            \Weline\Blog\Api\Data\BlogArticle::fromArray(['content_kind' => 'cms_page', 'public_url' => '/blog/paired', 'source_ref' => ['cms_page_id' => 11]]),
        ];
        $provider = new \Weline\Blog\Extends\Module\Weline_Seo\SitemapUrlProvider\BlogSitemapUrlProvider($builder, new \Weline\Blog\Service\BlogScopeResolver(), $directory, $resolver);
        foreach ([false, true] as $reverse) {
            if ($reverse) $resolver->articles = array_reverse($resolver->articles);
            $rows = $provider->getUrlsForWebsite(0);
            if (array_column($rows, 'url_key') !== ['blog-list', 'blog-post-325'] || array_column($rows, 'loc') !== ['http://localhost/blog', 'http://localhost/blog/paired']) {
                throw new \RuntimeException('duplicate locale records did not retain the actual public Post route and stable url_key');
            }
        }
        echo "PASS: duplicate locale records retain the public route once regardless of enumeration order\n";
    } catch (\Throwable $e) { $failed++; echo "FAIL: duplicate canonical URL: {$e->getMessage()}\n"; }
    exit($failed ? 1 : 0);
}
