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
        $page->load($identifier, Page::fields_IDENTIFIER);
        
        if ($page->getId() && (bool)($page->getData(Page::fields_IS_ACTIVE) ?? true)) {
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
            Page::fields_title => $pageData['title'] ?? '',
            Page::fields_identifier => $pageData['identifier'] ?? '',
            Page::fields_content => $pageData['content'] ?? '',
            Page::fields_is_active => $pageData['is_active'] ?? 1,
            Page::fields_updated_at => date('Y-m-d H:i:s'),
        ]);
        
        if (!$page->getId()) {
            $page->setCreatedAt(date('Y-m-d H:i:s'));
        }
        
        $page->save();
        
        return $page;
    }
}
