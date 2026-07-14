<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\ModuleManager\Controller\Backend;

use Weline\Framework\Manager\ObjectManager;
use Weline\ModuleManager\Model\Module;

class Listing extends \Weline\Framework\App\Controller\BackendController
{
    public function index()
    {
        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = (int) $this->request->getParam('pageSize', 25);
        $pageSizes = [10, 25, 50, 100];
        if (!in_array($pageSize, $pageSizes, true)) {
            $pageSize = 25;
        }

        $search = trim((string) $this->request->getParam('search', ''));
        $status = (string) $this->request->getParam('status', '');
        $position = trim((string) $this->request->getParam('position', ''));
        $schemaStatus = trim((string) $this->request->getParam('schema_status', ''));

        if (!in_array($status, ['', '0', '1'], true)) {
            $status = '';
        }
        if (!in_array($position, ['', 'system', 'system_config', 'app', 'composer'], true)) {
            $position = '';
        }
        if (!in_array($schemaStatus, ['', 'consistent', 'drifted', 'unverified', 'unknown'], true)) {
            $schemaStatus = '';
        }

        /**@var Module $module */
        $module = ObjectManager::getInstance(Module::class)->reset();
        if ($search !== '') {
            $searchFields = implode(',', array_map(
                static fn (string $field): string => "COALESCE(main_table.{$field},'')",
                [
                    Module::schema_fields_NAME,
                    Module::schema_fields_DESCRIPTION,
                    Module::schema_fields_NAMESPACE_PATH,
                    Module::schema_fields_BASE_PATH,
                    Module::schema_fields_ROUTER,
                    Module::schema_fields_VERSION,
                    Module::schema_fields_CODE_VERSION,
                    Module::schema_fields_DATABASE_VERSION,
                ],
            ));
            $module->concat_like($searchFields, '%' . $search . '%');
        }
        if ($status !== '') {
            $module->where(Module::schema_fields_STATUS, (int) $status);
        }
        if ($position !== '') {
            $module->where(Module::schema_fields_POSITION, $position);
        }
        if ($schemaStatus !== '') {
            $module->where(Module::schema_fields_SCHEMA_STATUS, $schemaStatus);
        }

        $paginationParams = array_filter([
            'search' => $search,
            'status' => $status,
            'position' => $position,
            'schema_status' => $schemaStatus,
            'pageSize' => $pageSize,
        ], static fn (mixed $value): bool => $value !== '');

        $module->order(Module::schema_fields_NAME, 'ASC')
            ->pagination($page, $pageSize, $paginationParams)
            ->select()
            ->fetch();

        $modules = $module->getItems();
        $paginationData = $module->getPaginationData();
        $total = (int) ($paginationData['totalSize'] ?? 0);
        $visibleCount = count($modules);
        $rangeStart = $total === 0 || $visibleCount === 0 ? 0 : (($page - 1) * $pageSize) + 1;
        $rangeEnd = $rangeStart === 0 ? 0 : min($total, $rangeStart + $visibleCount - 1);

        $this->assign('modules', $modules);
        $this->assign('pagination', $module->getPagination());
        $this->assign('total', $total);
        $this->assign('page', $page);
        $this->assign('pageSize', $pageSize);
        $this->assign('pageSizes', $pageSizes);
        $this->assign('search', $search);
        $this->assign('status', $status);
        $this->assign('position', $position);
        $this->assign('schemaStatus', $schemaStatus);
        $this->assign('visibleCount', $visibleCount);
        $this->assign('rangeStart', $rangeStart);
        $this->assign('rangeEnd', $rangeEnd);
        $this->assign('activeFilterCount', count(array_filter(
            [$search, $status, $position, $schemaStatus],
            static fn (string $value): bool => $value !== '',
        )));

        return $this->fetch();
    }
}
