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

#[Acl('WeShop_Cms::page_management', 'CMS Page Management', 'mdi mdi-file-document-edit-outline', 'Manage CMS pages', 'WeShop_Cms::cms_management')]
class Index extends BaseController
{
    public function __construct(
        private readonly CmsPageService $cmsPageService
    ) {
    }

    #[Acl('WeShop_Cms::page_management_index', 'View CMS pages', 'mdi mdi-file-document-outline', 'View CMS page list')]
    public function index(): string
    {
        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = max(1, (int) $this->request->getParam('page_size', 20));
        $editingId = (int) $this->request->getParam('id', 0);
        $filters = [
            'title' => $this->request->getParam('title', ''),
            'identifier' => $this->request->getParam('identifier', ''),
            'status' => $this->request->getParam('status', ''),
        ];

        $listData = $this->cmsPageService->getList($page, $pageSize, $filters);

        $editingPage = null;
        if ($editingId > 0) {
            $editingPage = $this->cmsPageService->getById($editingId);
        }

        $this->assign([
            'title' => (string) __('CMS Page Management'),
            'pageIndexUrl' => $this->_url->getBackendUrl('*/backend/cms/page'),
            'pageSaveUrl' => $this->_url->getBackendUrl('*/backend/cms/page/save'),
            'items' => $listData['items'],
            'total' => $listData['total'],
            'current_page' => $listData['page'],
            'page_size' => $listData['page_size'],
            'filters' => $filters,
            'editing_page' => $editingPage,
        ]);

        return (string) $this->fetchBase('WeShop_Cms::templates/Backend/Page/Index/index.phtml');
    }
}
