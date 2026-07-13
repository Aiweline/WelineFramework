<?php

declare(strict_types=1);

namespace Weline\Hook\Controller\Backend;

use Weline\Admin\Api\Controller\BaseController;
use Weline\Framework\Log\LoggerFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Hook\Service\HookListingService;

class Hook extends BaseController
{
    public function index()
    {
        try {
            /** @var HookListingService $service */
            $service = ObjectManager::getInstance(HookListingService::class);
            $requestStartedAt = microtime(true);

            $filterModule = trim($this->request->getGet('module', ''));
            $filterRegistered = $this->request->getGet('registered', '');
            $filterFiles = $this->request->getGet('files', '');
            $filterArea = $this->request->getGet('area', '');
            $filterType = $this->request->getGet('type', '');
            $sortBy = $this->request->getGet('sort', 'name');
            $sortOrder = $this->request->getGet('order', 'asc');
            $quickSearch = trim($this->request->getGet('q', ''));
            $page = $this->normalizePage($this->request->getGet('page', 1));
            $perPage = $this->normalizePerPage($this->request->getGet('per_page', 50));

            $stageStartedAt = microtime(true);
            $allHooks = $service->getAllHooks();
            $this->logPerformance('getAllHooks', $stageStartedAt, ['count' => count($allHooks)]);
            $stageStartedAt = microtime(true);
            $filteredHooks = $this->filterHooks(
                $service,
                $allHooks,
                $quickSearch,
                $filterModule,
                $filterRegistered,
                $filterFiles,
                $filterArea,
                $filterType,
                $sortBy,
                $sortOrder
            );
            $this->logPerformance('filterHooks', $stageStartedAt, ['filtered_count' => count($filteredHooks)]);

            $stageStartedAt = microtime(true);
            $pagination = $this->paginateHooks($filteredHooks, $page, $perPage);
            $this->logPerformance('paginateHooks', $stageStartedAt, [
                'page' => $pagination['page'],
                'per_page' => $pagination['per_page'],
                'page_items' => count($pagination['items']),
                'total' => $pagination['total'],
                'total_pages' => $pagination['total_pages'],
            ]);

            $stageStartedAt = microtime(true);
            $stats = $service->getHookStats();
            $allModules = $this->collectAllModules($allHooks);
            $this->logPerformance('statsAndModules', $stageStartedAt, [
                'stats_total_hooks' => (int)($stats['total_hooks'] ?? 0),
                'module_count' => count($allModules),
            ]);

            $stageStartedAt = microtime(true);
            $this->assignPageData(
                $pagination['items'],
                $stats,
                $allModules,
                $filterModule,
                $filterRegistered,
                $filterFiles,
                $filterArea,
                $filterType,
                $sortBy,
                $sortOrder,
                $quickSearch,
                $pagination['page'],
                $pagination['per_page'],
                $pagination['total_pages'],
                $pagination['total']
            );
            $this->assign('title', __('Hook 管理'));
            $this->logPerformance('assignPageData', $stageStartedAt);

            $stageStartedAt = microtime(true);
            $html = $this->renderHookPage();
            $this->logPerformance('renderHookPage', $stageStartedAt, ['html_bytes' => strlen($html)]);
            $this->logPerformance('total', $requestStartedAt);

            return $html;
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('加载 Hook 列表失败: %{1}', $e->getMessage()));
            $this->assign('hooks', []);
            $this->assign('stats', []);
            $this->assign('all_modules', []);
            $this->assign('title', __('Hook 管理'));

            return $this->renderHookPage();
        }
    }

    public function detail()
    {
        try {
            if (!$this->request->isAjax()) {
                $this->redirect('*/index');
                return;
            }

            $hookName = $this->request->getParam('hook');
            if (empty($hookName)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => __('请指定 Hook 名')], JSON_UNESCAPED_UNICODE);
                return;
            }

            /** @var HookListingService $service */
            $service = ObjectManager::getInstance(HookListingService::class);
            $hookDetail = $service->getHookDetail($hookName);

            if (!$hookDetail) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => __('Hook 不存在: %{1}', $hookName)], JSON_UNESCAPED_UNICODE);
                return;
            }

            /** @var \Weline\Hook\Block\Backend\Hook\Detail $detailBlock */
            $detailBlock = ObjectManager::make(\Weline\Hook\Block\Backend\Hook\Detail::class, [
                'data' => [
                    'hook' => $hookDetail,
                    'hook_name' => $hookName,
                ],
            ]);

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'html' => $detailBlock->render(),
                'title' => __('Hook 详情') . ': ' . $hookName,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            header('Content-Type: application/json');
            $errorMessage = $e->getMessage() ?: $e->getTraceAsString();
            echo json_encode([
                'success' => false,
                'message' => __('加载 Hook 详情失败: %{1}', $errorMessage),
                'error' => $errorMessage,
                'trace' => DEV ? $e->getTraceAsString() : '',
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    public function getSearchAjax()
    {
        try {
            if (!$this->request->isAjax()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => __('仅支持 AJAX 请求')], JSON_UNESCAPED_UNICODE);
                return;
            }

            /** @var HookListingService $service */
            $service = ObjectManager::getInstance(HookListingService::class);

            $filterModule = trim($this->request->getGet('module', ''));
            $filterRegistered = $this->request->getGet('registered', '');
            $filterFiles = $this->request->getGet('files', '');
            $filterArea = $this->request->getGet('area', '');
            $filterType = $this->request->getGet('type', '');
            $sortBy = $this->request->getGet('sort', 'name');
            $sortOrder = $this->request->getGet('order', 'asc');
            $quickSearch = trim($this->request->getGet('q', ''));
            $page = $this->normalizePage($this->request->getGet('page', 1));
            $perPage = $this->normalizePerPage($this->request->getGet('per_page', 50));

            $allHooks = $service->getAllHooks();
            $filteredHooks = $this->filterHooks(
                $service,
                $allHooks,
                $quickSearch,
                $filterModule,
                $filterRegistered,
                $filterFiles,
                $filterArea,
                $filterType,
                $sortBy,
                $sortOrder
            );

            $pagination = $this->paginateHooks($filteredHooks, $page, $perPage);

            $this->assignPageData(
                $pagination['items'],
                $service->getHookStats(),
                $this->collectAllModules($allHooks),
                $filterModule,
                $filterRegistered,
                $filterFiles,
                $filterArea,
                $filterType,
                $sortBy,
                $sortOrder,
                $quickSearch,
                $pagination['page'],
                $pagination['per_page'],
                $pagination['total_pages'],
                $pagination['total']
            );

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'hooks' => $pagination['items'],
                'stats' => $service->getHookStats(),
                'total_count' => $pagination['total'],
                'html' => $this->fetch('Weline_Hook::templates/Backend/Hook/list_table'),
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => __('搜索失败: %{1}', $e->getMessage()),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $allHooks
     * @return array<string, array<string, mixed>>
     */
    private function filterHooks(
        HookListingService $service,
        array $allHooks,
        string $quickSearch,
        string $filterModule,
        string $filterRegistered,
        string $filterFiles,
        string $filterArea,
        string $filterType,
        string $sortBy,
        string $sortOrder
    ): array {
        $filteredHooks = $allHooks;

        if ($quickSearch !== '') {
            $filteredHooks = $service->searchHooks($quickSearch, 'all');
        }

        if ($filterModule !== '') {
            $moduleHooks = $service->getHooksByModule($filterModule);
            $filteredHooks = array_intersect_key($filteredHooks, $moduleHooks);
        }

        if ($filterRegistered !== '') {
            $filteredHooks = array_filter($filteredHooks, static function (array $hook) use ($filterRegistered): bool {
                $isRegistered = (bool)($hook['is_registered'] ?? false);
                return ($filterRegistered === '1' && $isRegistered) || ($filterRegistered === '0' && !$isRegistered);
            });
        }

        if ($filterFiles !== '') {
            $filteredHooks = array_filter($filteredHooks, static function (array $hook) use ($filterFiles): bool {
                $hasFiles = (bool)($hook['has_files'] ?? false);
                return ($filterFiles === '1' && $hasFiles) || ($filterFiles === '0' && !$hasFiles);
            });
        }

        if ($filterArea !== '') {
            $filteredHooks = array_filter($filteredHooks, static function (array $hook) use ($filterArea): bool {
                return (string)($hook['area'] ?? '') === $filterArea;
            });
        }

        if ($filterType !== '') {
            $filteredHooks = array_filter($filteredHooks, static function (array $hook) use ($filterType): bool {
                return (string)($hook['type'] ?? '') === $filterType;
            });
        }

        if ($sortBy === 'name') {
            uksort($filteredHooks, static function (string $a, string $b) use ($sortOrder): int {
                $result = strcasecmp($a, $b);
                return $sortOrder === 'desc' ? -$result : $result;
            });
        } elseif ($sortBy === 'module') {
            uasort($filteredHooks, static function (array $a, array $b) use ($sortOrder): int {
                $result = strcasecmp((string)($a['module'] ?? ''), (string)($b['module'] ?? ''));
                return $sortOrder === 'desc' ? -$result : $result;
            });
        } elseif ($sortBy === 'files') {
            uasort($filteredHooks, static function (array $a, array $b) use ($sortOrder): int {
                $result = ((int)($a['file_count'] ?? 0)) <=> ((int)($b['file_count'] ?? 0));
                return $sortOrder === 'desc' ? -$result : $result;
            });
        }

        return $filteredHooks;
    }

    /**
     * @param array<string, array<string, mixed>> $hooks
     * @return array{items: array<string, array<string, mixed>>, page: int, per_page: int, total: int, total_pages: int}
     */
    private function paginateHooks(array $hooks, int $page, int $perPage): array
    {
        $total = count($hooks);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($hooks, $offset, $perPage, true),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $hooks
     * @return array<int, string>
     */
    private function collectAllModules(array $hooks): array
    {
        $allModules = [];
        foreach ($hooks as $hook) {
            $module = (string)($hook['module'] ?? '');
            if ($module !== '' && !in_array($module, $allModules, true)) {
                $allModules[] = $module;
            }
            foreach (($hook['using_modules'] ?? []) as $usingModule) {
                $usingModule = (string)$usingModule;
                if ($usingModule !== '' && !in_array($usingModule, $allModules, true)) {
                    $allModules[] = $usingModule;
                }
            }
        }
        sort($allModules);

        return $allModules;
    }

    private function normalizePage(mixed $page): int
    {
        return max(1, (int)$page);
    }

    private function normalizePerPage(mixed $perPage): int
    {
        $value = (int)$perPage;
        return in_array($value, [25, 50, 100, 200], true) ? $value : 50;
    }

    /**
     * @param array<string, array<string, mixed>> $hooks
     * @param array<string, mixed> $stats
     * @param array<int, string> $allModules
     */
    private function assignPageData(
        array $hooks,
        array $stats,
        array $allModules,
        string $filterModule,
        string $filterRegistered,
        string $filterFiles,
        string $filterArea,
        string $filterType,
        string $sortBy,
        string $sortOrder,
        string $quickSearch,
        int $page,
        int $perPage,
        int $totalPages,
        int $totalFilteredCount
    ): void {
        $this->assign('hooks', $hooks);
        $this->assign('stats', $stats);
        $this->assign('all_modules', $allModules);
        $this->assign('filter_module', $filterModule);
        $this->assign('filter_registered', $filterRegistered);
        $this->assign('filter_files', $filterFiles);
        $this->assign('filter_area', $filterArea);
        $this->assign('filter_type', $filterType);
        $this->assign('sort_by', $sortBy);
        $this->assign('sort_order', $sortOrder);
        $this->assign('quick_search', $quickSearch);
        $this->assign('current_page', $page);
        $this->assign('per_page', $perPage);
        $this->assign('total_pages', $totalPages);
        $this->assign('total_filtered_count', $totalFilteredCount);
    }

    /**
     * @param array<string, int|float|string> $context
     */
    private function logPerformance(string $stage, float $startedAt, array $context = []): void
    {
        if (!defined('DEV') || !DEV) {
            return;
        }

        if (!(bool)\Weline\Framework\App\Env::get('wls.debug.hook_perf', false)) {
            return;
        }

        $context['elapsed_ms'] = round((microtime(true) - $startedAt) * 1000, 2);
        LoggerFactory::create('hook-performance')->warning('[HookController::index] ' . $stage, $context);
    }

    private function renderHookPage(): string
    {
        $beforeStartedAt = microtime(true);
        $before = $this->getTemplate()->fetch('Weline_Admin::templates/Backend/page-layout/main-content-before.phtml');
        $this->logPerformance('render.before', $beforeStartedAt, ['html_bytes' => strlen($before)]);

        $contentStartedAt = microtime(true);
        $content = $this->getTemplate()->fetch('Weline_Hook::templates/Backend/Hook/index.phtml');
        $this->logPerformance('render.content', $contentStartedAt, ['html_bytes' => strlen($content)]);

        $afterStartedAt = microtime(true);
        $after = $this->getTemplate()->fetch('Weline_Admin::templates/Backend/page-layout/main-content-after.phtml');
        $this->logPerformance('render.after', $afterStartedAt, ['html_bytes' => strlen($after)]);

        return $before . $content . $after;
    }
}
