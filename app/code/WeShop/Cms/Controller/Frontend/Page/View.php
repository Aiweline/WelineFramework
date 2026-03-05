<?php

declare(strict_types=1);

namespace WeShop\Cms\Controller\Frontend\Page;

use WeShop\Frontend\Controller\BaseController;
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
            return $this->redirect('weshop');
        }
        
        /** @var PageService $pageService */
        $pageService = ObjectManager::getInstance(PageService::class);
        $page = $pageService->getPage($identifier);
        
        if (!$page) {
            $this->getMessageManager()->addError(__('页面不存在'));
            return $this->redirect('weshop');
        }
        
        // 格式化页面数据
        $pageData = [
            'page_id' => $page->getId(),
            'title' => $page->getData(\WeShop\Cms\Model\Page::schema_fields_TITLE) ?? '',
            'identifier' => $page->getData(\WeShop\Cms\Model\Page::schema_fields_IDENTIFIER) ?? '',
            'content' => $page->getData(\WeShop\Cms\Model\Page::schema_fields_CONTENT) ?? '',
            'is_active' => (bool)($page->getData(\WeShop\Cms\Model\Page::schema_fields_IS_ACTIVE) ?? true),
            'created_at' => $page->getData(\WeShop\Cms\Model\Page::schema_fields_CREATED_AT) ?? '',
            'updated_at' => $page->getData(\WeShop\Cms\Model\Page::schema_fields_UPDATED_AT) ?? '',
        ];
        
        // 准备模板数据
        $this->assign('page', $pageData);
        
        // SEO数据
        $this->assign('title', $pageData['title']);
        $this->assign('meta_title', $pageData['title']);
        $this->assign('meta_description', mb_substr(strip_tags($pageData['content']), 0, 160));
        
        // Theme模块会自动根据 layoutType 和主题配置加载对应的布局
        // 布局文件路径：app/design/WeShop/default/frontend/layouts/cms/cms_page_{variant}.phtml
        return $this->fetch();
    }
}
