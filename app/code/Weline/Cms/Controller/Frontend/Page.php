<?php
declare(strict_types=1);

namespace Weline\Cms\Controller\Frontend;

use Weline\Cms\Model\Page as CmsPage;
use Weline\Cms\Service\PageService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Session\SessionFactory;
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
        if ($preview && !$this->canPreview()) {
            $this->noRouter();
            return '';
        }

        $payload = $this->pageService->renderPagePayload([
            'page_id' => (int)$this->request->getParam('page_id', 0),
            'identifier' => (string)$this->request->getParam('identifier', $this->request->getParam('cms_identifier', '')),
            'website_id' => (int)$this->request->getParam('website_id', 0),
            'website_code' => (string)$this->request->getParam('website_code', ''),
            'path_group' => (string)$this->request->getParam('path_group', ''),
            'slug' => (string)$this->request->getParam('slug', ''),
            'scope' => (string)$this->request->getParam('scope', ''),
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

        $this->layoutType = CmsPage::LAYOUT_TYPE . '.' . ($layoutOption !== '' ? $layoutOption : 'default');
        $this->request->setGet('page_type', CmsPage::LAYOUT_TYPE);
        $this->request->setGet('layout_type', CmsPage::LAYOUT_TYPE);
        $this->request->setGet('layout_option', $layoutOption);
        $this->request->setGet('scope', $scope);
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

    private function canPreview(): bool
    {
        if ($this->hasValidPreviewToken()) {
            return true;
        }

        try {
            $backendSession = SessionFactory::getInstance()->createBackendSession();
            $backendSession->start(null);
            return $backendSession->isLoggedIn();
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasValidPreviewToken(): bool
    {
        $token = trim((string)$this->request->getParam(PageService::PREVIEW_TOKEN_QUERY_KEY, ''));
        if ($token === '') {
            return false;
        }

        try {
            $result = w_query('theme', 'validatePreviewToken', [
                'token' => $token,
                'page_type' => CmsPage::LAYOUT_TYPE,
                'theme_layout_target_type' => CmsPage::TARGET_TYPE,
                'theme_layout_target_id' => (int)$this->request->getParam('page_id', 0),
            ]);
        } catch (\Throwable) {
            return false;
        }

        return is_array($result) && !empty($result['success']);
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
}
