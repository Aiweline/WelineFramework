<?php
declare(strict_types=1);

namespace Weline\PlatformAppStore\Controller\Api;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Manager\ObjectManager;
use Weline\PlatformAppStore\Model\PlatformModule;
use Weline\PlatformAppStore\Model\PlatformModuleVersion;
use Weline\PlatformAppStore\Model\PlatformModuleCategory;

/**
 * 平台模块 API 控制器
 *
 * 提供模块列表、详情、下载等 API
 */
class Module extends FrontendRestController
{
    /**
     * 获取模块列表
     * POST /rest/v1/platform/module/list
     */
    public function postList()
    {
        try {
            $page = (int)$this->request->getPost('page', 1);
            $pageSize = (int)$this->request->getPost('page_size', 20);
            $categoryId = $this->request->getPost('category_id');
            $keyword = $this->request->getPost('keyword');
            $pricingType = $this->request->getPost('pricing_type');
            $sortBy = $this->request->getPost('sort_by', 'downloads');
            $sortOrder = $this->request->getPost('sort_order', 'desc');

            /** @var PlatformModule $moduleModel */
            $moduleModel = ObjectManager::getInstance(PlatformModule::class);

            // 只查询已发布的模块
            $moduleModel->where('status', PlatformModule::STATUS_PUBLISHED);

            // 分类筛选
            if ($categoryId) {
                $moduleModel->where('category_id', $categoryId);
            }

            // 定价类型筛选
            if ($pricingType) {
                $moduleModel->where('pricing_type', $pricingType);
            }

            // 关键词搜索
            if ($keyword) {
                $moduleModel->where('name', '%' . $keyword . '%', 'LIKE')
                    ->where('display_name', '%' . $keyword . '%', 'LIKE', 'OR');
            }

            // 排序
            $allowedSortFields = ['downloads', 'rating', 'created_at', 'price'];
            if (in_array($sortBy, $allowedSortFields)) {
                $moduleModel->order($sortBy, $sortOrder === 'asc' ? 'ASC' : 'DESC');
            }

            // 分页
            $moduleModel->limit($pageSize, \max(0, ($page - 1) * $pageSize));

            $modules = $moduleModel->reset('fields')
                ->fields([
                    'module_id', 'name', 'display_name', 'description',
                    'developer_id', 'category_id', 'icon', 'images',
                    'current_version', 'pricing_type', 'price',
                    'downloads', 'rating', 'rating_count', 'created_at'
                ])
                ->reset('with')
                ->with(['developer' => ['fields' => ['developer_id', 'name', 'avatar']]])
                ->select()
                ->fetch();

            // 获取总数
            $totalModel = ObjectManager::getInstance(PlatformModule::class);
            $totalModel->where('status', PlatformModule::STATUS_PUBLISHED);
            if ($categoryId) {
                $totalModel->where('category_id', $categoryId);
            }
            if ($pricingType) {
                $totalModel->where('pricing_type', $pricingType);
            }
            if ($keyword) {
                $totalModel->where('name', '%' . $keyword . '%', 'LIKE')
                    ->where('display_name', '%' . $keyword . '%', 'LIKE', 'OR');
            }
            $total = $totalModel->reset('fields')->count();

            return $this->success(__('获取成功'), [
                'items' => $modules,
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'total_pages' => ceil($total / $pageSize),
            ]);
        } catch (\Exception $e) {
            return $this->error(__('获取模块列表失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    /**
     * 获取模块详情
     * POST /rest/v1/platform/module/detail
     */
    public function postDetail()
    {
        try {
            $moduleId = $this->request->getPost('module_id');
            $moduleName = $this->request->getPost('module_name');

            if (!$moduleId && !$moduleName) {
                return $this->error(__('缺少模块ID或模块名'), '', 400);
            }

            /** @var PlatformModule $moduleModel */
            $moduleModel = ObjectManager::getInstance(PlatformModule::class);

            if ($moduleId) {
                $moduleModel->load($moduleId);
            } else {
                $moduleModel->load($moduleName, 'name');
            }

            if (!$moduleModel->getId()) {
                return $this->error(__('模块不存在'), '', 404);
            }

            // 获取模块版本列表
            /** @var PlatformModuleVersion $versionModel */
            $versionModel = ObjectManager::getInstance(PlatformModuleVersion::class);
            $versions = $versionModel->reset()
                ->where('module_id', $moduleModel->getId())
                ->where('status', PlatformModuleVersion::STATUS_PUBLISHED)
                ->order('created_at', 'DESC')
                ->limit(10)
                ->select()
                ->fetch();

            // 获取开发者信息
            $developer = $moduleModel->getData('developer') ?? [];

            return $this->success(__('获取成功'), [
                'module' => $moduleModel->getData(),
                'versions' => $versions,
                'developer' => $developer,
            ]);
        } catch (\Exception $e) {
            return $this->error(__('获取模块详情失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    /**
     * 获取分类列表
     * POST /rest/v1/platform/module/categories
     */
    public function postCategories()
    {
        try {
            /** @var PlatformModuleCategory $categoryModel */
            $categoryModel = ObjectManager::getInstance(PlatformModuleCategory::class);

            $categories = $categoryModel->reset()
                ->where('is_enabled', 1)
                ->order('sort_order', 'ASC')
                ->select()
                ->fetch();

            return $this->success(__('获取成功'), [
                'categories' => $categories,
            ]);
        } catch (\Exception $e) {
            return $this->error(__('获取分类列表失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    /**
     * 检查模块更新
     * POST /rest/v1/platform/module/check-update
     */
    public function postCheckUpdate()
    {
        try {
            $installedModules = $this->request->getPost('modules');

            if (!is_array($installedModules)) {
                return $this->error(__('参数格式错误'), '', 400);
            }

            $updates = [];

            foreach ($installedModules as $module) {
                $moduleName = $module['name'] ?? null;
                $currentVersion = $module['version'] ?? '0.0.0';

                if (!$moduleName) {
                    continue;
                }

                /** @var PlatformModule $moduleModel */
                $moduleModel = ObjectManager::getInstance(PlatformModule::class);
                $moduleModel->load($moduleName, 'name');

                if (!$moduleModel->getId()) {
                    continue;
                }

                $latestVersion = $moduleModel->getCurrentVersion();

                if (version_compare($latestVersion, $currentVersion, '>')) {
                    $updates[] = [
                        'module_id' => $moduleModel->getId(),
                        'name' => $moduleName,
                        'display_name' => $moduleModel->getDisplayName(),
                        'current_version' => $currentVersion,
                        'latest_version' => $latestVersion,
                        'pricing_type' => $moduleModel->getPricingType(),
                    ];
                }
            }

            return $this->success(__('检查完成'), [
                'updates' => $updates,
                'count' => count($updates),
            ]);
        } catch (\Exception $e) {
            return $this->error(__('检查更新失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    /**
     * 下载模块
     * POST /rest/v1/platform/module/download
     */
    public function postDownload()
    {
        try {
            $licenseKey = $this->request->getPost('license_key');
            $moduleId = $this->request->getPost('module_id');
            $version = $this->request->getPost('version');
            $domain = $this->request->getPost('domain');

            if (!$licenseKey) {
                return $this->error(__('缺少许可证密钥'), '', 400);
            }

            if (!$moduleId) {
                return $this->error(__('缺少模块ID'), '', 400);
            }

            // 验证许可证
            /** @var \Weline\PlatformAppStore\Service\LicenseService $licenseService */
            $licenseService = ObjectManager::getInstance(\Weline\PlatformAppStore\Service\LicenseService::class);
            $validation = $licenseService->validateLicense($licenseKey, $domain ?: $this->request->getServer('HTTP_HOST'));

            if (!$validation['valid']) {
                return $this->error($validation['message'], '', 403);
            }

            // 验证许可证对应的模块
            if ($validation['module_id'] != $moduleId) {
                return $this->error(__('许可证与模块不匹配'), '', 403);
            }

            // 获取模块信息
            /** @var PlatformModule $moduleModel */
            $moduleModel = ObjectManager::getInstance(PlatformModule::class);
            $moduleModel->load($moduleId);

            if (!$moduleModel->getId()) {
                return $this->error(__('模块不存在'), '', 404);
            }

            // 获取版本信息
            /** @var PlatformModuleVersion $versionModel */
            $versionModel = ObjectManager::getInstance(PlatformModuleVersion::class);
            $versionModel->reset();

            if ($version) {
                $versionModel->where('module_id', $moduleId)
                    ->where('version', $version);
            } else {
                // 获取最新版本
                $versionModel->where('module_id', $moduleId)
                    ->where('status', PlatformModuleVersion::STATUS_PUBLISHED)
                    ->order('created_at', 'DESC');
            }

            $versionData = $versionModel->find();

            if (!$versionData) {
                return $this->error(__('版本不存在'), '', 404);
            }

            // 检查文件是否存在
            $filePath = $versionData->getFilePath();
            if (!$filePath || !file_exists($filePath)) {
                return $this->error(__('模块文件不存在'), '', 404);
            }

            // 更新下载次数
            $moduleModel->incrementDownloads();
            $moduleModel->save();

            // 生成临时下载链接（实际生产中应该生成带签名的临时 URL）
            $downloadUrl = $this->generateDownloadUrl($filePath, $licenseKey);

            return $this->success(__('获取下载链接成功'), [
                'module_name' => $moduleModel->getName(),
                'version' => $versionData->getVersion(),
                'download_url' => $downloadUrl,
                'file_hash' => $versionData->getFileHash(),
                'file_size' => $versionData->getFileSize(),
                'module_info' => [
                    'module_id' => $moduleModel->getId(),
                    'name' => $moduleModel->getName(),
                    'display_name' => $moduleModel->getDisplayName(),
                    'description' => $moduleModel->getDescription(),
                    'version' => $versionData->getVersion(),
                    'dependencies' => $versionData->getDependencies(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->error(__('下载失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    /**
     * 生成下载 URL
     *
     * @param string $filePath 文件路径
     * @param string $licenseKey 许可证密钥
     * @return string
     */
    private function generateDownloadUrl(string $filePath, string $licenseKey): string
    {
        // 实际生产中应该：
        // 1. 生成带签名的临时 URL
        // 2. 设置过期时间
        // 3. 使用 CDN 或对象存储服务

        // 这里简化处理，直接返回相对 URL
        $baseUrl = $this->request->getUrlBuilder()->getBaseUrl();
        $relativePath = str_replace(BP, '', $filePath);

        return $baseUrl . ltrim($relativePath, DS);
    }
}
