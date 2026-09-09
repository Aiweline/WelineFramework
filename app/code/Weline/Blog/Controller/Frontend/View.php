<?php

declare(strict_types=1);

namespace Weline\Blog\Controller\Frontend;

use Weline\Blog\Api\Data\BlogArticle;
use Weline\Blog\Api\Uri\BlogNamespace;
use Weline\Blog\Model\Post as PostModel;
use Weline\Blog\Service\BlogContentResolver;
use Weline\Blog\Service\BlogScopeResolver;
use Weline\Blog\Service\BlogSeoFactsBuilder;
use Weline\Blog\Service\CmsBlogPageRenderBridge;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Runtime\RequestContext;

/** Blog detail: /blog/{slug} — Theme layout blog (Amazon-style article). */
final class View extends FrontendController
{
    public function __construct(
        private readonly BlogContentResolver $resolver,
        private readonly BlogScopeResolver $scope,
        private readonly CmsBlogPageRenderBridge $cmsRenderBridge,
        private readonly BlogSeoFactsBuilder $seoFacts,
    ) {
    }

    public function index(): string
    {
        $slug = strtolower(trim((string)$this->request->getParam('slug', '')));
        if ($slug === '' || BlogNamespace::isReservedSlug($slug)) {
            $this->noRouter();

            return '';
        }

        $article = $this->resolver->resolveBySlug(
            $this->scope->websiteId(),
            $this->scope->locale(),
            $slug,
            $this->scope->baseUrl(),
        );
        if ($article === null) {
            $this->noRouter();

            return '';
        }

        $this->assign('blog_article', $article->toArray());
        $this->request->setGet('page_type', 'blog_post');
        $this->request->setGet('theme_public_route', 'blog/' . $slug);
        $this->request->setGet('theme_page_title', $article->title);
        $this->assign('page_title', $article->title);
        $this->assign('title', $article->title);
        // Prefer request-aware getUrl so canonical/hreflang self keep /{currency}/{locale}/…
        $canonical = (string)$this->getUrl('blog/' . $slug);
        $this->assign('seo', $this->seoFacts->buildDetailProfile($article, $canonical));
        RequestContext::set('blog.seo.canonical.v1', $canonical);
        $this->assign('blog_rss_url', BlogNamespace::rssPublicPath());
        $this->assign('blog_rss_label', (string)__('订阅博客 RSS'));

        if ($article->contentKind === BlogArticle::KIND_CMS) {
            return $this->cmsRenderBridge->render($this, $article);
        }

        $related = $this->resolver->listRelatedArticles(
            $this->scope->websiteId(),
            $this->scope->locale(),
            $article,
            4,
            $this->scope->baseUrl(),
        );

        $this->layoutType = 'blog';
        $postId = $article->entityId();
        $this->assign('blog_entity_uuid', $postId > 0 ? 'blog:post:' . $postId : '');
        $this->assign('post_content', $this->loadPostContent($article));
        $this->assign('blog_related_articles', array_map(
            static fn(BlogArticle $row): array => $row->toArray(),
            $related,
        ));

        return (string)$this->fetch('Weline_Blog::templates/frontend/post/detail.phtml');
    }

    private function loadPostContent(BlogArticle $article): string
    {
        $postId = (int)($article->sourceRef['post_id'] ?? 0);
        if ($postId <= 0) {
            return '';
        }
        $cached = RequestContext::get('blog.published_post.by_id.v1.' . $postId);
        if (\is_array($cached)) {
            return (string)($cached[PostModel::schema_fields_CONTENT] ?? '');
        }
        $model = \Weline\Framework\Manager\ObjectManager::getInstance(PostModel::class);
        $model->clearData()->reset()->load($postId);
        if ($model->getPostId() <= 0) {
            return '';
        }

        return (string)($model->getData(PostModel::schema_fields_CONTENT) ?? '');
    }
}
