<?php

declare(strict_types=1);

/*
 * 页面服务类 - 负责页面相关的业务逻辑
 * 遵循单一职责原则(SRP)
 */

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\Style;
use Weline\Framework\Manager\ObjectManager;

class PageService
{
    private Page $pageModel;
    private Style $styleModel;
    
    public function __construct()
    {
        $this->pageModel = ObjectManager::getInstance(Page::class);
        $this->styleModel = ObjectManager::getInstance(Style::class);
    }
    
    /**
     * 根据ID获取页面
     */
    public function getById(int $pageId): ?Page
    {
        $page = clone $this->pageModel;
        $page->load($pageId);
        
        return $page->getId() ? $page : null;
    }
    
    /**
     * 获取页面的样式代码
     */
    public function getStyleCode(Page $page): string
    {
        return $page->getData(Page::fields_STYLE) ?? '';
    }
    
    /**
     * 获取所有可用的样式模板
     */
    public function getAvailableStyles(): array
    {
        // 自动扫描样式
        Style::autoScan();
        
        return $this->styleModel->clear()
            ->where(Style::fields_IS_ACTIVE, 1)
            ->order(Style::fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
    }
}
