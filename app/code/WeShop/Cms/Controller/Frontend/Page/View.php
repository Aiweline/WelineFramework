<?php

declare(strict_types=1);

namespace WeShop\Cms\Controller\Frontend\Page;

use WeShop\Frontend\Controller\BaseController;
use WeShop\Cms\Model\Page as PageModel;
use WeShop\Cms\Service\PageService;
use Weline\Framework\Manager\ObjectManager;

/**
 * CMS页面控制器
 * 
 * 支持3种布局变体：
 * - cms_page_1
 * - cms_page_2
 * - cms_page_3
 * 
 * 布局变体通过主题配置设置：layouts.cms = cms_page_1
 */
class View extends BaseController
{
    /**
     * 布局类型
     * Theme模块会根据此类型从主题配置中加载对应的布局
     */
    protected ?string $layoutType = 'cms';
    
    /**
     * CMS页面
     */
    public function index(): string
    {
        $identifier = $this->request->getParam('identifier') ?? $this->request->getParam('id') ?? '';
        
        if (empty($identifier)) {
            $this->getMessageManager()->addError(__('页面标识符不能为空'));
            return $this->redirect('weshop') ?? '';
        }
        
        /** @var PageService $pageService */
        $pageService = ObjectManager::getInstance(PageService::class);
        $page = $pageService->getPage($identifier);
        
        if (!$page) {
            $this->getMessageManager()->addError(__('页面不存在'));
            return $this->redirect('weshop') ?? '';
        }
        
        $pageData = self::buildPageData($page);
        
        // 准备模板数据
        $this->assign('page', $pageData);

        // 准备 meta 数据（供 cms_page 布局的 {{meta.content}} 使用）
        $this->assign('meta', [
            'title' => $pageData['title'],
            'content' => $pageData['content'],
            'class' => '',
        ]);

        // SEO数据
        $this->assign('title', $pageData['title']);
        $this->assign('meta_title', $pageData['title']);
        $this->assign('meta_description', mb_substr(strip_tags($pageData['content']), 0, 160));
        
        // Theme模块会自动根据 layoutType 和主题配置加载对应的布局
        // 布局文件路径：app/design/WeShop/default/frontend/layouts/cms/cms_page_{variant}.phtml
        return $this->fetch();
    }

    /**
     * @return array{page_id:int|string,title:string,identifier:string,content:string,is_active:bool,created_at:string,updated_at:string}
     */
    private static function buildPageData(PageModel $page): array
    {
        $status = (int) ($page->getData(PageModel::schema_fields_STATUS) ?? PageModel::STATUS_DRAFT);
        return [
            'page_id' => $page->getId(),
            'title' => (string) ($page->getData(PageModel::schema_fields_TITLE) ?? ''),
            'identifier' => (string) ($page->getData(PageModel::schema_fields_HANDLE) ?? ''),
            'content' => (string) ($page->getData(PageModel::schema_fields_CONTENT) ?? ''),
            'is_active' => $status === PageModel::STATUS_PUBLISHED,
            'created_at' => (string) ($page->getData(PageModel::schema_fields_CREATE_TIME) ?? ''),
            'updated_at' => (string) ($page->getData(PageModel::schema_fields_UPDATE_TIME) ?? ''),
        ];
    }
}
