<?php
declare(strict_types=1);

namespace Weline\Cms\Controller\Frontend;

use Weline\Cms\Model\Page as CmsPage;
use Weline\Cms\Service\PageService;
use Weline\Framework\App\State;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Cache\SharedResponseCachePolicy;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Theme\Api\Layout\LayoutIdentity;
use Weline\Theme\Api\Layout\LayoutStatus;
use Weline\Theme\Api\Preview\PreviewContext;

class Page extends FrontendController
{
    protected ?string $layoutType = CmsPage::LAYOUT_TYPE;

    public function __construct(
        private readonly PageService $pageService
    ) {
    }

    public function index(): string
    {
        return $this->getView();
    }

    public function getView(): string
    {
        $preview = (int)$this->request->getParam('preview', 0) === 1;
        $previewToken = $preview
            ? trim((string)$this->request->getParam(PageService::PREVIEW_TOKEN_QUERY_KEY, ''))
            : '';
        $previewTokenContext = null;
        if ($preview) {
            if ($previewToken === '') {
                $this->noRouter();
                return '';
            }
            $previewTokenContext = $this->validatedPreviewTokenContext($previewToken);
            if ($previewTokenContext === null) {
                $this->noRouter();
                return '';
            }
            $this->applyPrivatePreviewResponsePolicy();
        }

        $previewStoreId = $previewTokenContext !== null
            ? (int)$previewTokenContext['cms_store_id']
            : (int)$this->request->getParam('store_id', 0);
        $previewLocale = $previewTokenContext !== null
            ? (string)$previewTokenContext['cms_locale_code']
            : (string)$this->request->getParam('locale_code', '');

        $payload = $this->pageService->renderPagePayload([
            // A preview capability selects its own immutable target. Do not
            // let path/query parameters select a different draft page first.
            'page_id' => $previewTokenContext !== null
                ? (int)$previewTokenContext['cms_page_id']
                : (int)$this->request->getParam('page_id', 0),
            'identifier' => (string)$this->request->getParam('identifier', $this->request->getParam('cms_identifier', '')),
            'website_id' => (int)$this->request->getParam('website_id', 0),
            'website_code' => (string)$this->request->getParam('website_code', ''),
            'path_group' => (string)$this->request->getParam('path_group', ''),
            'slug' => (string)$this->request->getParam('slug', ''),
            'scope' => (string)$this->request->getParam('scope', ''),
            'store_id' => $preview ? $previewStoreId : 0,
            'locale_code' => $preview ? $previewLocale : '',
            'preview' => $preview,
        ]);
        if ($payload === null) {
            $this->noRouter();
            return '';
        }

        $page = is_array($payload['page'] ?? null) ? $payload['page'] : [];
        $layout = is_array($payload['layout'] ?? null) ? $payload['layout'] : [];
        $layoutOption = (string)($layout['layout_option'] ?? 'default');
        $scope = (string)($page['scope'] ?? 'default');
        $pageId = (int)($page['page_id'] ?? 0);
        $layoutStatus = $preview ? LayoutStatus::DRAFT->value : LayoutStatus::PUBLISHED->value;
        if ($previewTokenContext !== null && !$this->previewContextMatchesPage($previewTokenContext, $page)) {
            $this->noRouter();
            return '';
        }
        if ($preview) {
            $this->applyPreviewRequestContext($page);
        }

        RequestContext::set(LayoutIdentity::REQUEST_CONTEXT_KEY, new LayoutIdentity(
            $layoutOption !== '' ? $layoutOption : 'default',
            $scope !== '' ? $scope : 'default.default.default',
            CmsPage::TARGET_TYPE,
            $pageId,
            (string)($page['locale_code'] ?? ''),
        ));

        $this->layoutType = CmsPage::LAYOUT_TYPE . '.' . ($layoutOption !== '' ? $layoutOption : 'default');
        $this->request->setGet('page_type', CmsPage::LAYOUT_TYPE);
        $this->request->setGet('layout_type', CmsPage::LAYOUT_TYPE);
        $this->request->setGet('layout_option', $layoutOption);
        $this->request->setGet('scope', $scope);
        $this->request->setGet('store_id', (int)($page['store_id'] ?? 0));
        $this->request->setGet('store_code', (string)($page['store_code'] ?? ''));
        $this->request->setGet('store_mode', (string)($page['store_mode'] ?? 'normal'));
        $this->request->setGet('locale', (string)($page['locale_code'] ?? ''));
        $this->request->setGet('locale_code', (string)($page['locale_code'] ?? ''));
        $this->request->setGet('status', $layoutStatus);
        if ($preview) {
            $previewContext = PreviewContext::frontend();
            $this->request->setGet('preview_mode', $previewContext->previewMode);
            $this->request->setGet('shell', $previewContext->shell);
            $this->request->setGet('editor_area', $previewContext->editorArea);
        } else {
            $this->clearPreviewContextParams();
        }
        $this->request->setGet('theme_layout_target_type', CmsPage::TARGET_TYPE);
        $this->request->setGet('theme_layout_target_id', $pageId);
        $this->request->setGet('theme_layout_source_target_type', CmsPage::TARGET_TYPE);
        $this->request->setGet('theme_layout_source_target_id', $pageId);
        $this->request->setData('params', $this->request->getParameterBag()->all());

        $meta = $this->getTemplate()->getData('meta');
        $meta = is_array($meta) ? $meta : [];
        $meta['cms_page_id'] = $pageId;
        $meta['cms_identifier'] = (string)($page['identifier'] ?? '');
        $meta['cms_website_id'] = (int)($page['website_id'] ?? 0);
        $meta['cms_website_code'] = (string)($page['website_code'] ?? '');
        $meta['cms_store_id'] = (int)($page['store_id'] ?? 0);
        $meta['cms_store_code'] = (string)($page['store_code'] ?? '');
        $meta['cms_locale_code'] = (string)($page['locale_code'] ?? '');
        $meta['cms_path_group'] = (string)($page['path_group'] ?? '');
        $meta['cms_slug'] = (string)($page['slug'] ?? '');
        $pageTitle = trim((string)($page['title'] ?? ''));
        if (trim((string)($meta['title'] ?? '')) === '' && $pageTitle !== '') {
            $meta['title'] = $pageTitle;
        }
        if (trim((string)($meta['meta_title'] ?? '')) === '' && $pageTitle !== '') {
            $meta['meta_title'] = $pageTitle;
        }
        if (trim((string)($meta['description'] ?? '')) === '' && $pageTitle !== '') {
            $meta['description'] = $pageTitle;
        }
        if (trim((string)($meta['meta_description'] ?? '')) === '' && trim((string)($meta['description'] ?? '')) !== '') {
            $meta['meta_description'] = (string)$meta['description'];
        }
        if (trim((string)($meta['canonical_url'] ?? '')) === '') {
            $meta['canonical_url'] = (string)($page['public_url'] ?? '');
        }
        if (trim((string)($meta['robots'] ?? '')) === '') {
            $meta['robots'] = $preview ? 'noindex,nofollow' : 'index,follow';
        }
        $this->assign('meta', $meta);
        $this->assign('page', $page);
        $this->assign('meta_title', (string)($meta['meta_title'] ?? $meta['title'] ?? ''));
        $this->assign('meta_description', (string)($meta['meta_description'] ?? $meta['description'] ?? ''));
        $this->assign('canonical_url', (string)($meta['canonical_url'] ?? ''));
        $this->assign('cms_page', $page);
        $this->assign('cms_payload', $payload);

        return $this->fetch('Weline_Cms::templates/frontend/page/content.phtml');
    }

    /** @return array<string,mixed>|null */
    private function validatedPreviewTokenContext(string $token): ?array
    {
        try {
            $result = w_query('theme', 'validatePreviewToken', [
                'token' => $token,
                'page_type' => CmsPage::LAYOUT_TYPE,
                'theme_layout_target_type' => CmsPage::TARGET_TYPE,
            ]);
        } catch (\Throwable) {
            return null;
        }

        if (
            !is_array($result)
            || empty($result['success'])
        ) {
            return null;
        }
        $context = is_array($result['context'] ?? null) ? $result['context'] : null;
        if (
            !is_array($context)
            || (int)($context['cms_page_id'] ?? 0) <= 0
            || (int)($context['cms_store_id'] ?? 0) <= 0
            || trim((string)($context['cms_locale_code'] ?? '')) === ''
            || trim((string)($context['cms_canonical_scope'] ?? '')) === ''
        ) {
            return null;
        }

        return $context;
    }

    /** @param array<string,mixed> $page */
    private function applyPreviewRequestContext(array $page): void
    {
        $locale = trim((string)($page['locale_code'] ?? ''));
        $locale = trim($locale);
        if (
            strlen($locale) > 16
            || preg_match('/^[a-z]{2,3}(?:_[A-Z][a-z]{3})?(?:_(?:[A-Z]{2}|[0-9]{3}))?$/D', $locale) !== 1
        ) {
            throw new \InvalidArgumentException((string)__('CMS 预览语言无效。'));
        }

        $identity = ScopeIdentity::store(
            (int)($page['website_id'] ?? -1),
            (string)($page['website_code'] ?? ''),
            (string)($page['store_code'] ?? ''),
            (string)($page['store_mode'] ?? ''),
        );
        RequestContext::replaceScopeIdentityForAuthorizedPreview(
            $identity,
            (int)($page['store_id'] ?? 0),
        );
        SharedResponseCachePolicy::forbid('authorized_cms_preview_scope');

        $this->request->setGet('locale', $locale);
        $this->request->setGet('locale_code', $locale);
        $this->assign('locale', $locale);
        RequestContext::setWelineUserLang($locale);
        State::resetRequestPathLocalizationCache();
        State::resetLangLocalCache();
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $page */
    private function previewContextMatchesPage(array $context, array $page): bool
    {
        return (int)($context['cms_page_id'] ?? 0) === (int)($page['page_id'] ?? 0)
            && (int)($context['cms_website_id'] ?? -1) === (int)($page['website_id'] ?? -2)
            && hash_equals(
                (string)($context['cms_website_code'] ?? ''),
                (string)($page['website_code'] ?? ''),
            )
            && (int)($context['cms_store_id'] ?? 0) === (int)($page['store_id'] ?? 0)
            && hash_equals(
                (string)($context['cms_store_code'] ?? ''),
                (string)($page['store_code'] ?? ''),
            )
            && hash_equals(
                (string)($context['cms_store_mode'] ?? ''),
                (string)($page['store_mode'] ?? ''),
            )
            && hash_equals(
                (string)($context['cms_locale_code'] ?? ''),
                (string)($page['locale_code'] ?? ''),
            )
            && hash_equals(
                (string)($context['cms_canonical_scope'] ?? ''),
                (string)($page['scope'] ?? ''),
            );
    }

    private function clearPreviewContextParams(): void
    {
        foreach ([
            'editor_mode',
            'visual_editor',
            'preview_mode',
            'preview_theme',
            'preview_area',
            'frontend_theme_id',
            'backend_theme_id',
            'theme_id',
            'weline_theme_id',
            'shell',
            'editor_area',
            'version_id',
            PageService::PREVIEW_TOKEN_QUERY_KEY,
        ] as $key) {
            $this->request->setGet($key, '');
        }
    }

    private function applyPrivatePreviewResponsePolicy(): void
    {
        $response = $this->request->getResponse();
        $response->setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Referrer-Policy', 'no-referrer');
        $response->setHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
