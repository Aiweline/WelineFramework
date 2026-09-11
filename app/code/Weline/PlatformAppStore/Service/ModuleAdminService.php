<?php
declare(strict_types=1);

namespace Weline\PlatformAppStore\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\PlatformAppStore\Model\PlatformDeveloper;
use Weline\PlatformAppStore\Model\PlatformModule;
use Weline\PlatformAppStore\Model\PlatformModuleCategory;
use Weline\PlatformAppStore\Model\PlatformModuleLicense;
use Weline\PlatformAppStore\Model\PlatformOrder;

/**
 * 平台应用商店后台列表辅助（展示标签 / 筛选规范化 / 轻量查询）。
 */
class ModuleAdminService
{
    public function statusLabel(string $status): string
    {
        return match ($status) {
            PlatformModule::STATUS_DRAFT => '草稿',
            PlatformModule::STATUS_PUBLISHED => '已发布',
            PlatformModule::STATUS_ARCHIVED => '已归档',
            default => $status !== '' ? $status : '未知',
        };
    }

    public function pricingLabel(string $pricingType): string
    {
        return match ($pricingType) {
            PlatformModule::PRICING_FREE => '免费',
            PlatformModule::PRICING_ONE_TIME => '一次性',
            PlatformModule::PRICING_SUBSCRIPTION => '订阅',
            default => $pricingType !== '' ? $pricingType : '未知',
        };
    }

    /**
     * @param array{q?: string, status?: string, pricing_type?: string}|array<string, mixed> $filters
     */
    public function hasActiveFilters(array $filters): bool
    {
        return trim((string)($filters['q'] ?? '')) !== ''
            || trim((string)($filters['status'] ?? '')) !== ''
            || trim((string)($filters['pricing_type'] ?? '')) !== '';
    }

    /**
     * 空态文案与下一步动作（模板只渲染，不内嵌业务话术分支）。
     *
     * @return array{title: string, description: string, cta_label: string, cta_action: string}
     */
    public function emptyStateMeta(bool $filtered = false): array
    {
        if ($filtered) {
            return [
                'title' => '没有匹配的模块',
                'description' => '试试清空筛选条件，或新建一个草稿模块。',
                'cta_label' => '新建模块',
                'cta_action' => 'create',
            ];
        }

        return [
            'title' => '暂无平台模块',
            'description' => '先新建草稿，完善信息后再发布到子站商城。',
            'cta_label' => '新建模块',
            'cta_action' => 'create',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{name: string, display_name: string, description: string}
     */
    public function normalizeDraftInput(array $input): array
    {
        $name = trim((string)($input['name'] ?? ''));
        $displayName = trim((string)($input['display_name'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));

        if ($name === '' || !preg_match('/^[A-Za-z][A-Za-z0-9]*_[A-Za-z][A-Za-z0-9]*$/', $name)) {
            throw new \InvalidArgumentException('模块名须为 Vendor_Module 格式');
        }
        if ($displayName === '') {
            throw new \InvalidArgumentException('显示名称不能为空');
        }

        return [
            'name' => $name,
            'display_name' => $displayName,
            'description' => $description,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{q: string, status: string, pricing_type: string, page: int, page_size: int}
     */
    public function normalizeFilters(array $input): array
    {
        $q = trim((string)($input['q'] ?? ''));
        $status = trim((string)($input['status'] ?? ''));
        $pricingType = trim((string)($input['pricing_type'] ?? ''));
        $page = max(1, (int)($input['page'] ?? 1));
        $pageSize = (int)($input['page_size'] ?? 20);
        if ($pageSize < 1) {
            $pageSize = 20;
        }
        if ($pageSize > 50) {
            $pageSize = 50;
        }

        $allowedStatus = [
            PlatformModule::STATUS_DRAFT,
            PlatformModule::STATUS_PUBLISHED,
            PlatformModule::STATUS_ARCHIVED,
        ];
        if (!in_array($status, $allowedStatus, true)) {
            $status = '';
        }

        $allowedPricing = [
            PlatformModule::PRICING_FREE,
            PlatformModule::PRICING_ONE_TIME,
            PlatformModule::PRICING_SUBSCRIPTION,
        ];
        if (!in_array($pricingType, $allowedPricing, true)) {
            $pricingType = '';
        }

        return [
            'q' => $q,
            'status' => $status,
            'pricing_type' => $pricingType,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function decorateModuleRow(array $row): array
    {
        $displayName = trim((string)($row['display_name'] ?? ''));
        $name = trim((string)($row['name'] ?? ''));
        $status = (string)($row['status'] ?? '');
        $pricingType = (string)($row['pricing_type'] ?? '');

        $row['title'] = $displayName !== '' ? $displayName : ($name !== '' ? $name : '未命名模块');
        $row['status_label'] = $this->statusLabel($status);
        $row['pricing_label'] = $this->pricingLabel($pricingType);

        return $row;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{items: list<array<string, mixed>>, total: int, filters: array<string, mixed>}
     */
    public function listModules(array $input = []): array
    {
        $filters = $this->normalizeFilters($input);

        try {
            /** @var PlatformModule $countModel */
            $countModel = ObjectManager::getInstance(PlatformModule::class);
            $this->applyModuleFilters($countModel, $filters);
            $total = (int)$countModel->reset('fields')->count();

            /** @var PlatformModule $listModel */
            $listModel = ObjectManager::getInstance(PlatformModule::class);
            $this->applyModuleFilters($listModel, $filters);
            $offset = max(0, ($filters['page'] - 1) * $filters['page_size']);
            $rows = (array)$listModel
                ->order(PlatformModule::schema_fields_ID, 'DESC')
                ->limit($filters['page_size'], $offset)
                ->select()
                ->fetchArray();

            $items = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $items[] = $this->decorateModuleRow($row);
            }

            return [
                'items' => $items,
                'total' => $total,
                'filters' => $filters,
            ];
        } catch (\Throwable) {
            return [
                'items' => [],
                'total' => 0,
                'filters' => $filters,
            ];
        }
    }

    /**
     * @param array{q: string, status: string, pricing_type: string, page: int, page_size: int} $filters
     */
    private function applyModuleFilters(PlatformModule $model, array $filters): void
    {
        $model->clear();
        if ($filters['status'] !== '') {
            $model->where(PlatformModule::schema_fields_status, $filters['status']);
        }
        if ($filters['pricing_type'] !== '') {
            $model->where(PlatformModule::schema_fields_pricing_type, $filters['pricing_type']);
        }
        if ($filters['q'] !== '') {
            $like = '%' . $filters['q'] . '%';
            $model->where(PlatformModule::schema_fields_name, $like, 'LIKE')
                ->where(PlatformModule::schema_fields_display_name, $like, 'LIKE', 'OR');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCategories(int $limit = 50): array
    {
        return $this->safeFetchRows(PlatformModuleCategory::class, PlatformModuleCategory::schema_fields_ID, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOrders(int $limit = 50): array
    {
        return $this->safeFetchRows(PlatformOrder::class, PlatformOrder::schema_fields_ID, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listLicenses(int $limit = 50): array
    {
        return $this->safeFetchRows(PlatformModuleLicense::class, PlatformModuleLicense::schema_fields_ID, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listDevelopers(int $limit = 50): array
    {
        return $this->safeFetchRows(PlatformDeveloper::class, PlatformDeveloper::schema_fields_ID, $limit);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createDraft(array $input): array
    {
        $draft = $this->normalizeDraftInput($input);
        /** @var PlatformModule $model */
        $model = ObjectManager::getInstance(PlatformModule::class);
        $existing = $model->clear()
            ->where(PlatformModule::schema_fields_name, $draft['name'])
            ->find()
            ->fetch();
        if ($existing && $existing->getModuleId() > 0) {
            throw new \InvalidArgumentException('模块名已存在');
        }

        $now = date('Y-m-d H:i:s');
        $model->clear()
            ->setName($draft['name'])
            ->setDisplayName($draft['display_name'])
            ->setDescription($draft['description'] !== '' ? $draft['description'] : null)
            ->setDeveloperId(0)
            ->setCurrentVersion('0.1.0')
            ->setStatus(PlatformModule::STATUS_DRAFT)
            ->setPricingType(PlatformModule::PRICING_FREE)
            ->setPrice(0)
            ->setData(PlatformModule::schema_fields_created_at, $now)
            ->setData(PlatformModule::schema_fields_updated_at, $now)
            ->save();

        return $this->decorateModuleRow([
            'module_id' => $model->getModuleId(),
            'name' => $model->getName(),
            'display_name' => $model->getDisplayName(),
            'status' => $model->getStatus(),
            'pricing_type' => $model->getPricingType(),
            'price' => $model->getPrice(),
            'current_version' => $model->getCurrentVersion(),
            'downloads' => $model->getDownloads(),
        ]);
    }

    /**
     * @param class-string $modelClass
     * @return list<array<string, mixed>>
     */
    private function safeFetchRows(string $modelClass, string $orderField, int $limit): array
    {
        try {
            $model = ObjectManager::getInstance($modelClass);
            $rows = (array)$model->clear()
                ->order($orderField, 'DESC')
                ->limit(max(1, min(100, $limit)))
                ->select()
                ->fetchArray();
            $out = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $out[] = $row;
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}
