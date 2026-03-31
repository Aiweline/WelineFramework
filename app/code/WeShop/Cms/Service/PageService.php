<?php

declare(strict_types=1);

namespace WeShop\Cms\Service;

use WeShop\Cms\Model\Page;
use WeShop\Cms\Model\Page\LocalDescription;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locals;

/**
 * CMS页面服务
 *
 * 提供CMS页面完整的业务逻辑处理，包括页面的增删改查、多语言支持、状态管理等
 */
class PageService
{
    private Page $pageModel;
    private LocalDescription $localDescriptionModel;
    private I18n $i18nModel;
    private Locals $localsModel;

    public function __construct(
        Page $pageModel,
        LocalDescription $localDescriptionModel,
        I18n $i18nModel,
        Locals $localsModel
    ) {
        $this->pageModel = $pageModel;
        $this->localDescriptionModel = $localDescriptionModel;
        $this->i18nModel = $i18nModel;
        $this->localsModel = $localsModel;
    }

    /**
     * 获取页面（按handle）
     */
    public function getPage(string $handle): ?Page
    {
        $lookupFields = [Page::schema_fields_HANDLE, 'identifier'];
        foreach ($lookupFields as $field) {
            try {
                $page = clone $this->pageModel;
                $page->clear()->load($field, $handle);

                if ($page->getId() && (int) ($page->getData(Page::schema_fields_STATUS) ?? 0) === Page::STATUS_PUBLISHED) {
                    return $page;
                }
            } catch (\Throwable) {
                // Some environments still use legacy identifier schema; fallback to next field.
                continue;
            }
        }

        return null;
    }

    /**
     * 获取页面（按ID）
     */
    public function getPageById(int $pageId): ?Page
    {
        $page = clone $this->pageModel;
        $page->clear()->load($pageId);

        if (!$page->getId()) {
            return null;
        }

        return $page;
    }

    /**
     * 获取页面列表（支持分页）
     *
     * @param int $page 页码
     * @param int $size 每页数量
     * @param array $filters 筛选条件
     * @param string $orderField 排序字段
     * @param string $orderDir 排序方向
     * @return array{items: Page[], pagination: string}
     */
    public function getPageList(
        int $page = 1,
        int $size = 20,
        array $filters = [],
        string $orderField = Page::schema_fields_CREATE_TIME,
        string $orderDir = 'DESC'
    ): array {
        $model = clone $this->pageModel;
        $model->clear();

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $model->where(Page::schema_fields_NAME, "%$search%", 'like')
                ->where(Page::schema_fields_TITLE, "%$search%", 'like', 'or')
                ->where(Page::schema_fields_HANDLE, "%$search%", 'like', 'or');
        }

        if (!empty($filters['status'])) {
            $model->where(Page::schema_fields_STATUS, (int) $filters['status']);
        }

        if (!empty($filters['type'])) {
            $model->where(Page::schema_fields_TYPE, $filters['type']);
        }

        $result = $model
            ->order($orderField, $orderDir)
            ->pagination($page, $size)
            ->select()
            ->fetch();

        return [
            'items' => $result->getItems(),
            'pagination' => $result->getPagination(),
        ];
    }

    /**
     * 获取已发布页面列表（供前台使用）
     *
     * @param int $limit 限制数量
     * @return Page[]
     */
    public function getActivePages(int $limit = 10): array
    {
        $pages = clone $this->pageModel;
        return $pages->clear()
            ->where(Page::schema_fields_STATUS, Page::STATUS_PUBLISHED)
            ->order(Page::schema_fields_CREATE_TIME, 'DESC')
            ->limit($limit)
            ->select()
            ->fetch()
            ->getItems();
    }

    /**
     * 获取子页面列表
     *
     * @param int $parentId 父页面ID
     * @return Page[]
     */
    public function getChildPages(int $parentId): array
    {
        $children = clone $this->pageModel;
        return $children->clear()
            ->where(Page::schema_fields_PARENT_ID, $parentId)
            ->order(Page::schema_fields_CREATE_TIME, 'DESC')
            ->select()
            ->fetch()
            ->getItems();
    }

    /**
     * 验证页面句柄是否唯一
     */
    public function validateHandle(string $handle, int $excludePageId = 0): bool
    {
        $model = clone $this->pageModel;
        $model->clear()->where(Page::schema_fields_HANDLE, $handle);

        if ($excludePageId > 0) {
            $model->where(Page::schema_fields_ID, $excludePageId, '!=');
        }

        $existing = $model->find()->fetch();
        return !$existing->getId();
    }

    /**
     * 创建页面
     *
     * @param array $pageData 页面数据
     * @return Page 创建的页面
     * @throws \Exception 验证失败或创建失败时抛出异常
     */
    public function createPage(array $pageData): Page
    {
        $this->validatePageData($pageData);

        if (!$this->validateHandle($pageData[Page::schema_fields_HANDLE] ?? '')) {
            throw new \Exception(__('页面句柄"%{1}"已被使用', $pageData[Page::schema_fields_HANDLE]));
        }

        $page = clone $this->pageModel;
        $page->clearData()
            ->setData(Page::schema_fields_HANDLE, $pageData[Page::schema_fields_HANDLE])
            ->setData(Page::schema_fields_TYPE, $pageData[Page::schema_fields_TYPE])
            ->setData(Page::schema_fields_NAME, $pageData[Page::schema_fields_NAME])
            ->setData(Page::schema_fields_TITLE, $pageData[Page::schema_fields_TITLE])
            ->setData(Page::schema_fields_CONTENT, $pageData[Page::schema_fields_CONTENT] ?? '')
            ->setData(Page::schema_fields_PARENT_ID, $pageData[Page::schema_fields_PARENT_ID] ?? 0)
            ->setData(Page::schema_fields_STYLE, $pageData[Page::schema_fields_STYLE] ?? '')
            ->setData(Page::schema_fields_STYLE_SETTING, $pageData[Page::schema_fields_STYLE_SETTING] ?? '{}')
            ->setData(Page::schema_fields_GA4_ID, $pageData[Page::schema_fields_GA4_ID] ?? '')
            ->setData(Page::schema_fields_GTM_ID, $pageData[Page::schema_fields_GTM_ID] ?? '')
            ->setData(Page::schema_fields_FB_PIXEL_ID, $pageData[Page::schema_fields_FB_PIXEL_ID] ?? '')
            ->setData(Page::schema_fields_LOGO, $pageData[Page::schema_fields_LOGO] ?? '')
            ->setData(Page::schema_fields_ICON, $pageData[Page::schema_fields_ICON] ?? '')
            ->setData(Page::schema_fields_LOCALES, $pageData[Page::schema_fields_LOCALES] ?? '[]')
            ->setData(Page::schema_fields_DEFAULT_LOCALE, $pageData[Page::schema_fields_DEFAULT_LOCALE] ?? '')
            ->setData(Page::schema_fields_META_TITLE, $pageData[Page::schema_fields_META_TITLE] ?? '')
            ->setData(Page::schema_fields_META_DESCRIPTION, $pageData[Page::schema_fields_META_DESCRIPTION] ?? '')
            ->setData(Page::schema_fields_META_KEYWORDS, $pageData[Page::schema_fields_META_KEYWORDS] ?? '')
            ->setData(Page::schema_fields_REDIRECT_URL, $pageData[Page::schema_fields_REDIRECT_URL] ?? '')
            ->setData(Page::schema_fields_STATUS, $pageData[Page::schema_fields_STATUS] ?? Page::STATUS_DRAFT)
            ->save(true);

        return $page;
    }

    /**
     * 更新页面
     *
     * @param int $pageId 页面ID
     * @param array $pageData 页面数据
     * @return Page 更新后的页面
     * @throws \Exception 验证失败或更新失败时抛出异常
     */
    public function updatePage(int $pageId, array $pageData): Page
    {
        $page = $this->getPageById($pageId);
        if (!$page) {
            throw new \Exception(__('页面不存在'));
        }

        $this->validatePageData($pageData);

        $handle = $pageData[Page::schema_fields_HANDLE] ?? '';
        if (!$this->validateHandle($handle, $pageId)) {
            throw new \Exception(__('页面句柄"%{1}"已被使用', $handle));
        }

        $page->setData(Page::schema_fields_HANDLE, $handle)
            ->setData(Page::schema_fields_TYPE, $pageData[Page::schema_fields_TYPE])
            ->setData(Page::schema_fields_NAME, $pageData[Page::schema_fields_NAME])
            ->setData(Page::schema_fields_TITLE, $pageData[Page::schema_fields_TITLE])
            ->setData(Page::schema_fields_CONTENT, $pageData[Page::schema_fields_CONTENT] ?? '')
            ->setData(Page::schema_fields_PARENT_ID, $pageData[Page::schema_fields_PARENT_ID] ?? 0)
            ->setData(Page::schema_fields_STYLE, $pageData[Page::schema_fields_STYLE] ?? '')
            ->setData(Page::schema_fields_STYLE_SETTING, $pageData[Page::schema_fields_STYLE_SETTING] ?? '{}')
            ->setData(Page::schema_fields_GA4_ID, $pageData[Page::schema_fields_GA4_ID] ?? '')
            ->setData(Page::schema_fields_GTM_ID, $pageData[Page::schema_fields_GTM_ID] ?? '')
            ->setData(Page::schema_fields_FB_PIXEL_ID, $pageData[Page::schema_fields_FB_PIXEL_ID] ?? '')
            ->setData(Page::schema_fields_LOGO, $pageData[Page::schema_fields_LOGO] ?? '')
            ->setData(Page::schema_fields_ICON, $pageData[Page::schema_fields_ICON] ?? '')
            ->setData(Page::schema_fields_LOCALES, $pageData[Page::schema_fields_LOCALES] ?? '[]')
            ->setData(Page::schema_fields_DEFAULT_LOCALE, $pageData[Page::schema_fields_DEFAULT_LOCALE] ?? '')
            ->setData(Page::schema_fields_META_TITLE, $pageData[Page::schema_fields_META_TITLE] ?? '')
            ->setData(Page::schema_fields_META_DESCRIPTION, $pageData[Page::schema_fields_META_DESCRIPTION] ?? '')
            ->setData(Page::schema_fields_META_KEYWORDS, $pageData[Page::schema_fields_META_KEYWORDS] ?? '')
            ->setData(Page::schema_fields_REDIRECT_URL, $pageData[Page::schema_fields_REDIRECT_URL] ?? '')
            ->setData(Page::schema_fields_STATUS, $pageData[Page::schema_fields_STATUS] ?? Page::STATUS_DRAFT)
            ->save();

        return $page;
    }

    /**
     * 更新页面状态
     *
     * @param int $pageId 页面ID
     * @param int $status 状态
     * @return bool 是否成功
     */
    public function updatePageStatus(int $pageId, int $status): bool
    {
        $page = $this->getPageById($pageId);
        if (!$page) {
            return false;
        }

        $page->setData(Page::schema_fields_STATUS, $status)->save();
        return true;
    }

    /**
     * 删除页面
     *
     * @param int $pageId 页面ID
     * @return bool 是否成功
     * @throws \Exception 删除失败时抛出异常
     */
    public function deletePage(int $pageId): bool
    {
        $page = $this->getPageById($pageId);
        if (!$page) {
            throw new \Exception(__('页面不存在'));
        }

        $childPages = $this->getChildPages($pageId);
        if (!empty($childPages)) {
            throw new \Exception(__('该页面存在子页面，无法删除！请先删除或移动子页面。'));
        }

        $page->delete();

        $localDescriptions = clone $this->localDescriptionModel;
        $localDescriptions->clear()
            ->where(LocalDescription::schema_fields_ID, $pageId)
            ->delete()
            ->fetch();

        return true;
    }

    /**
     * 验证页面数据
     *
     * @param array $pageData 页面数据
     * @throws \Exception 验证失败时抛出异常
     */
    private function validatePageData(array $pageData): void
    {
        if (empty($pageData[Page::schema_fields_HANDLE])) {
            throw new \Exception(__('页面句柄不能为空'));
        }

        if (empty($pageData[Page::schema_fields_TYPE])) {
            throw new \Exception(__('页面类型不能为空'));
        }

        if (empty($pageData[Page::schema_fields_NAME])) {
            throw new \Exception(__('页面名称不能为空'));
        }

        if (empty($pageData[Page::schema_fields_TITLE])) {
            throw new \Exception(__('页面标题不能为空'));
        }
    }

    /**
     * 保存页面（兼容旧接口）
     *
     * @deprecated 使用 createPage 或 updatePage 替代
     */
    public function savePage(array $pageData): Page
    {
        if (!empty($pageData['page_id'])) {
            return $this->updatePage((int) $pageData['page_id'], $pageData);
        }

        return $this->createPage($pageData);
    }

    /**
     * 获取页面总数
     */
    public function getTotalCount(array $filters = []): int
    {
        $model = clone $this->pageModel;
        $model->clear();

        if (!empty($filters['status'])) {
            $model->where(Page::schema_fields_STATUS, (int) $filters['status']);
        }

        if (!empty($filters['type'])) {
            $model->where(Page::schema_fields_TYPE, $filters['type']);
        }

        $result = $model->select()->fetch();
        return count($result->getItems());
    }

    /**
     * 获取所有页面类型
     *
     * @return array<string, string>
     */
    public function getPageTypes(): array
    {
        return Page::getPageTypes();
    }

    /**
     * 检查页面是否有子页面
     */
    public function hasChildPages(int $pageId): bool
    {
        return !empty($this->getChildPages($pageId));
    }

    /**
     * 批量更新页面状态
     *
     * @param array $pageIds 页面ID列表
     * @param int $status 状态
     * @return int 成功更新的数量
     */
    public function batchUpdateStatus(array $pageIds, int $status): int
    {
        $count = 0;
        foreach ($pageIds as $pageId) {
            if ($this->updatePageStatus((int) $pageId, $status)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 复制页面
     *
     * @param int $pageId 源页面ID
     * @param string $newHandle 新页面句柄
     * @param string $newName 新页面名称
     * @return Page 复制后的新页面
     * @throws \Exception 复制失败时抛出异常
     */
    public function duplicatePage(int $pageId, string $newHandle, string $newName): Page
    {
        $sourcePage = $this->getPageById($pageId);
        if (!$sourcePage) {
            throw new \Exception(__('源页面不存在'));
        }

        if (!$this->validateHandle($newHandle)) {
            throw new \Exception(__('页面句柄"%{1}"已被使用', $newHandle));
        }

        $pageData = [
            Page::schema_fields_HANDLE => $newHandle,
            Page::schema_fields_TYPE => $sourcePage->getData(Page::schema_fields_TYPE),
            Page::schema_fields_NAME => $newName,
            Page::schema_fields_TITLE => $sourcePage->getData(Page::schema_fields_TITLE),
            Page::schema_fields_CONTENT => $sourcePage->getData(Page::schema_fields_CONTENT),
            Page::schema_fields_PARENT_ID => 0,
            Page::schema_fields_STYLE => $sourcePage->getData(Page::schema_fields_STYLE),
            Page::schema_fields_STYLE_SETTING => $sourcePage->getData(Page::schema_fields_STYLE_SETTING),
            Page::schema_fields_GA4_ID => $sourcePage->getData(Page::schema_fields_GA4_ID),
            Page::schema_fields_GTM_ID => $sourcePage->getData(Page::schema_fields_GTM_ID),
            Page::schema_fields_FB_PIXEL_ID => $sourcePage->getData(Page::schema_fields_FB_PIXEL_ID),
            Page::schema_fields_LOGO => $sourcePage->getData(Page::schema_fields_LOGO),
            Page::schema_fields_ICON => $sourcePage->getData(Page::schema_fields_ICON),
            Page::schema_fields_LOCALES => $sourcePage->getData(Page::schema_fields_LOCALES),
            Page::schema_fields_DEFAULT_LOCALE => $sourcePage->getData(Page::schema_fields_DEFAULT_LOCALE),
            Page::schema_fields_META_TITLE => $sourcePage->getData(Page::schema_fields_META_TITLE),
            Page::schema_fields_META_DESCRIPTION => $sourcePage->getData(Page::schema_fields_META_DESCRIPTION),
            Page::schema_fields_META_KEYWORDS => $sourcePage->getData(Page::schema_fields_META_KEYWORDS),
            Page::schema_fields_REDIRECT_URL => '',
            Page::schema_fields_STATUS => Page::STATUS_DRAFT,
        ];

        return $this->createPage($pageData);
    }
}
