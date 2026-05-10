<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace WeShop\Cms\Service;

use WeShop\Cms\Model\Page;
use Weline\Framework\Database\Exception\DbException;
use Weline\Framework\Manager\ObjectManager;

class CmsPageService
{
    private Page $pageModel;

    public function __construct()
    {
        $this->pageModel = ObjectManager::getInstance(Page::class);
    }

    /**
     * Get paginated list of CMS pages for admin management.
     */
    public function getList(int $page = 1, int $pageSize = 20, array $filters = []): array
    {
        $model = $this->pageModel->reset();

        if (!empty($filters['title'])) {
            $model->like('title', '%' . $filters['title'] . '%');
        }
        if (!empty($filters['identifier'])) {
            $model->like('identifier', '%' . $filters['identifier'] . '%');
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $model->where('status', (int) $filters['status']);
        }

        $model->order('sort_order', 'ASC')
            ->order('page_id', 'DESC');

        $pagination = $model->pagination($page, $pageSize);
        $items = $pagination->fetchArray();

        $total = $model->reset()
            ->where('1=1');
        if (!empty($filters['title'])) {
            $total->like('title', '%' . $filters['title'] . '%');
        }
        if (!empty($filters['identifier'])) {
            $total->like('identifier', '%' . $filters['identifier'] . '%');
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $total->where('status', (int) $filters['status']);
        }
        $totalCount = (int) $total->count();

        return [
            'items' => $items,
            'total' => $totalCount,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * Get a single CMS page by ID.
     */
    public function getById(int $id): ?Page
    {
        $page = $this->pageModel->reset()->load($id);
        return $page->getId() ? $page : null;
    }

    /**
     * Get a single CMS page by identifier (for frontend).
     */
    public function getByIdentifier(string $identifier): ?Page
    {
        $page = $this->pageModel->reset()
            ->where(Page::schema_fields_IDENTIFIER, $identifier)
            ->where(Page::schema_fields_STATUS, Page::STATUS_ENABLED)
            ->fetch();
        return $page->getId() ? $page : null;
    }

    /**
     * Save (create or update) a CMS page.
     */
    public function save(array $data): Page
    {
        $id = (int) ($data['page_id'] ?? 0);
        if ($id > 0) {
            $page = $this->getById($id);
            if (!$page) {
                throw new \RuntimeException((string) __('CMS page not found.'));
            }
        } else {
            $page = ObjectManager::getInstance(Page::class);
        }

        $page->setTitle((string) ($data['title'] ?? ''));
        $page->setIdentifier((string) ($data['identifier'] ?? ''));
        $page->setContent((string) ($data['content'] ?? ''));
        $page->setContentHeading((string) ($data['content_heading'] ?? ''));
        $page->setMetaTitle((string) ($data['meta_title'] ?? ''));
        $page->setMetaDescription((string) ($data['meta_description'] ?? ''));
        $page->setMetaKeywords((string) ($data['meta_keywords'] ?? ''));
        $page->setStatus((int) ($data['status'] ?? Page::STATUS_ENABLED));
        $page->setPageLayout((string) ($data['page_layout'] ?? ''));
        $page->setSortOrder((int) ($data['sort_order'] ?? 0));

        $page->save();
        return $page;
    }

    /**
     * Delete a CMS page by ID.
     */
    public function deleteById(int $id): bool
    {
        $page = $this->getById($id);
        if (!$page) {
            throw new \RuntimeException((string) __('CMS page not found.'));
        }
        return $page->delete();
    }

    /**
     * Check if identifier is unique (excluding given page ID).
     */
    public function isIdentifierUnique(string $identifier, int $excludeId = 0): bool
    {
        $model = $this->pageModel->reset()
            ->where(Page::schema_fields_IDENTIFIER, $identifier);
        if ($excludeId > 0) {
            $model->where(Page::schema_fields_ID . ' !=', $excludeId);
        }
        return (int) $model->count() === 0;
    }
}
