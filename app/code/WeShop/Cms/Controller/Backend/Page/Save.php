<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace WeShop\Cms\Controller\Backend\Page;

use WeShop\Cms\Service\CmsPageService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_Cms::page_management_actions', 'CMS Page Actions', 'mdi mdi-content-save-edit-outline', 'Create and update CMS pages', 'WeShop_Cms::page_management')]
class Save extends BaseController
{
    public function __construct(
        private readonly CmsPageService $cmsPageService
    ) {
    }

    #[Acl('WeShop_Cms::page_management_save_post', 'Save CMS page', 'mdi mdi-content-save', 'Save CMS page data')]
    public function post(): string
    {
        $defaultBackUrl = $this->_url->getBackendUrl('*/backend/cms/page');
        $backUrl = (string) $this->request->getParam('back_url', $defaultBackUrl);
        if (trim($backUrl) === '') {
            $backUrl = $defaultBackUrl;
        }

        try {
            $pageId = (int) $this->request->getParam('page_id', 0);
            $identifier = trim((string) $this->request->getParam('identifier', ''));
            $title = trim((string) $this->request->getParam('title', ''));

            if ($title === '') {
                throw new \InvalidArgumentException((string) __('Page title is required.'));
            }
            if ($identifier === '') {
                throw new \InvalidArgumentException((string) __('Page identifier is required.'));
            }
            if (!$this->cmsPageService->isIdentifierUnique($identifier, $pageId)) {
                throw new \InvalidArgumentException((string) __('Page identifier already exists. Please use a different one.'));
            }

            $page = $this->cmsPageService->save([
                'page_id' => $pageId,
                'title' => $title,
                'identifier' => $identifier,
                'content' => $this->request->getParam('content', ''),
                'content_heading' => $this->request->getParam('content_heading', ''),
                'meta_title' => $this->request->getParam('meta_title', ''),
                'meta_description' => $this->request->getParam('meta_description', ''),
                'meta_keywords' => $this->request->getParam('meta_keywords', ''),
                'status' => (int) $this->request->getParam('status', 1),
                'page_layout' => $this->request->getParam('page_layout', ''),
                'sort_order' => (int) $this->request->getParam('sort_order', 0),
            ]);

            $this->getMessageManager()->addSuccess(__('CMS page saved successfully.'));
            $backUrl = str_replace('{id}', (string) $page->getId(), $backUrl);
            $this->redirect($backUrl);
            return '';
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: __('CMS page save failed.'));
            $this->redirect($backUrl);
            return '';
        }
    }

    #[Acl('WeShop_Cms::page_management_save_index', 'Open CMS page save route', 'mdi mdi-content-save-outline', 'Open CMS page save route')]
    public function index(): string
    {
        return $this->post();
    }

    #[Acl('WeShop_Cms::page_management_delete', 'Delete CMS page', 'mdi mdi-delete-outline', 'Delete a CMS page')]
    public function delete(): string
    {
        $defaultBackUrl = $this->_url->getBackendUrl('*/backend/cms/page');
        $backUrl = (string) $this->request->getParam('back_url', $defaultBackUrl);

        try {
            $pageId = (int) $this->request->getParam('page_id', 0);
            if ($pageId <= 0) {
                throw new \InvalidArgumentException((string) __('Invalid page ID.'));
            }
            $this->cmsPageService->deleteById($pageId);
            $this->getMessageManager()->addSuccess(__('CMS page deleted successfully.'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: __('CMS page delete failed.'));
        }

        $this->redirect($backUrl);
        return '';
    }
}
