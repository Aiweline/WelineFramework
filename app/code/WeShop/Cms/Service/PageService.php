<?php

declare(strict_types=1);

namespace WeShop\Cms\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Cms\Model\Page;

/**
 * CMS页面服务
 */
class PageService
{
    /**
     * 获取页面
     */
    public function getPage(string $identifier): ?Page
    {
        /** @var Page $page */
        $page = ObjectManager::getInstance(Page::class);
        $page->load(Page::fields_HANDLE, $identifier);
        
        if ($page->getId() && (int)($page->getData(Page::fields_STATUS) ?? 0) === Page::STATUS_PUBLISHED) {
            return $page;
        }
        
        return null;
    }
    
    /**
     * 保存页面
     */
    public function savePage(array $pageData): Page
    {
        /** @var Page $page */
        $page = ObjectManager::getInstance(Page::class);
        
        if (!empty($pageData['page_id'])) {
            $page->load($pageData['page_id']);
        }
        
        $page->setData([
            Page::fields_TITLE => $pageData['title'] ?? '',
            Page::fields_HANDLE => $pageData['identifier'] ?? $pageData['handle'] ?? '',
            Page::fields_CONTENT => $pageData['content'] ?? '',
            Page::fields_STATUS => (int)($pageData['is_active'] ?? $pageData['status'] ?? Page::STATUS_PUBLISHED),
            Page::fields_UPDATE_TIME => date('Y-m-d H:i:s'),
        ]);
        
        if (!$page->getId()) {
            $page->setData(Page::fields_CREATE_TIME, date('Y-m-d H:i:s'));
        }
        
        $page->save();
        
        return $page;
    }
}
