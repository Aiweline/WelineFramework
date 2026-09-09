<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Api\Data\BlogArticle;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Api\Layout\LayoutIdentity;
use Weline\Theme\Api\Layout\LayoutStatus;

final class CmsBlogPageRenderBridge
{
    private const CMS_LAYOUT_TYPE = 'cms_page';
    private const CMS_TARGET_TYPE = 'cms_page';

    public function __construct(
        private readonly CmsBlogPageAdapter $cmsAdapter,
    ) {
    }

    public function render(FrontendController $controller, BlogArticle $article): string
    {
        $pageId = (int)($article->sourceRef['cms_page_id'] ?? 0);
        $payload = $this->cmsAdapter->renderPagePayload($article->websiteId, $article->slug, $pageId);
        if ($payload === null) {
            $controller->noRouter();

            return '';
        }

        $page = is_array($payload['page'] ?? null) ? $payload['page'] : [];
        $layout = is_array($payload['layout'] ?? null) ? $payload['layout'] : [];
        $layoutOption = (string)($layout['layout_option'] ?? 'default');
        $scope = (string)($page['scope'] ?? 'default');
        $resolvedPageId = (int)($page['page_id'] ?? $pageId);
        $layoutStatus = LayoutStatus::PUBLISHED->value;

        RequestContext::set(LayoutIdentity::REQUEST_CONTEXT_KEY, new LayoutIdentity(
            $layoutOption !== '' ? $layoutOption : 'default',
            $scope !== '' ? $scope : 'default.default.default',
            self::CMS_TARGET_TYPE,
            $resolvedPageId,
            (string)($page['locale_code'] ?? $article->locale),
        ));

        $request = $controller->getRequest();
        $request->setGet('page_type', self::CMS_LAYOUT_TYPE);
        $request->setGet('layout_type', self::CMS_LAYOUT_TYPE);
        $request->setGet('layout_option', $layoutOption);
        $request->setGet('scope', $scope);
        $request->setGet('identifier', $article->identifier);
        $request->setGet('cms_identifier', $article->identifier);
        $request->setGet('page_id', $resolvedPageId);
        $request->setGet('website_id', $article->websiteId);
        $request->setGet('path_group', BlogArticle::KIND_CMS === $article->contentKind ? 'blog' : '');
        $request->setGet('slug', $article->slug);
        $request->setGet('status', $layoutStatus);
        $request->setGet('theme_layout_target_type', self::CMS_TARGET_TYPE);
        $request->setGet('theme_layout_target_id', $resolvedPageId);
        $request->setGet('theme_layout_source_target_type', self::CMS_TARGET_TYPE);
        $request->setGet('theme_layout_source_target_id', $resolvedPageId);
        $request->setData('params', $request->getParameterBag()->all());

        $controller->layoutType = self::CMS_LAYOUT_TYPE . '.' . ($layoutOption !== '' ? $layoutOption : 'default');
        $controller->assign('blog_article', $article->toArray());
        $controller->assign('page', $page);
        $controller->assign('cms_page', $page);
        $controller->assign('cms_payload', $payload);
        $controller->assign('meta_title', $article->title);
        $controller->assign('meta_description', $article->excerpt);
        // Keep currency/locale canonical from View (RequestContext) when present.
        $canonical = trim((string)(RequestContext::get('blog.seo.canonical.v1') ?? ''));
        if ($canonical === '') {
            $canonical = $article->canonicalUrl;
        }
        $controller->assign('canonical_url', $canonical);

        return (string)$controller->fetch('Weline_Cms::templates/frontend/page/content.phtml');
    }
}
