<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Controller\Backend\Visual\Api;

use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

/**
 * 可视化编辑「页面结构」树数据
 * GET pagebuilder/backend/visual/api/page_tree/get?page_id=22
 */
class PageTree extends BackendController
{
    private Page $pageModel;

    public function __construct()
    {
        $this->pageModel = ObjectManager::getInstance(Page::class);
    }

    /**
     * 返回当前页对应的本站页面列表（与 getExistingSitePagesList 一致，供右侧栏页面树展示与跳转）
     */
    public function get()
    {
        $pageId = (int)$this->request->getParam('page_id') ?: (int)$this->request->getGet('page_id');
        if ($pageId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => __('缺少 page_id')]);
        }
        $page = clone $this->pageModel;
        $page->load($pageId);
        if (!$page->getId()) {
            return $this->fetchJson(['success' => false, 'message' => __('页面不存在')]);
        }
        $list = $this->getNavPagesForTree($page);
        return $this->fetchJson(['success' => true, 'list' => $list]);
    }

    private function getNavPagesForTree(Page $page): array
    {
        $parentId = (int)$page->getData(Page::schema_fields_PARENT_ID);
        try {
            if ($parentId === 0) {
                $list = $page->getChildPagesForNav(50);
                if ($page->getId() && $page->getData(Page::schema_fields_TYPE) === Page::TYPE_HOME) {
                    $h = $page->getData(Page::schema_fields_HANDLE);
                    $hStr = $h === null || $h === '' ? '' : (string)$h;
                    array_unshift($list, [
                        'title' => $page->getData(Page::schema_fields_TITLE) ?: $page->getData(Page::schema_fields_NAME),
                        'handle' => $hStr,
                        'url' => '/', // 首页直接用域名，不拼 handle
                        'type' => Page::TYPE_HOME,
                        'page_id' => $page->getId(),
                    ]);
                }
            } else {
                $list = $page->getSiblingPagesForNav(50);
            }
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            $handle = $item['handle'] ?? '';
            $type = $item['type'] ?? '';
            $url = $item['url'] ?? '';
            if ($type === Page::TYPE_HOME) {
                $url = '/';
            } elseif ($url === '' && ($handle === '' || $handle === null)) {
                $url = '/';
            } elseif ($url === '' && $handle !== '') {
                $url = '/' . $handle;
            }
            $out[] = [
                'page_id' => (int)($item['page_id'] ?? 0),
                'handle' => $handle === '' || $handle === null ? '' : (string)$handle,
                'url' => $url,
                'title' => $item['title'] ?? '',
            ];
        }
        return $out;
    }
}
