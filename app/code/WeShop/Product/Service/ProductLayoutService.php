<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/12/20
 * 描述：产品布局服务 - 管理产品布局和计划
 */

namespace WeShop\Product\Service;

use WeShop\Product\Model\Product;
use WeShop\Product\Model\ProductLayout;
use WeShop\Product\Model\ProductLayoutSchedule;
use Weline\Framework\Cache\CacheFactory;
use Weline\Framework\Manager\ObjectManager;

class ProductLayoutService
{
    private const CACHE_KEY_PREFIX = 'weshop_product_layout_';
    private const CACHE_TTL = 3600;
    private ProductLayout $productLayoutModel;
    private ProductLayoutSchedule $scheduleModel;

    public function __construct(
        ProductLayout        $productLayoutModel,
        ProductLayoutSchedule $scheduleModel
    ) {
        $this->productLayoutModel = $productLayoutModel;
        $this->scheduleModel = $scheduleModel;
    }

    /**
     * 获取产品布局
     * 优先级：活动计划 > 产品专属布局 > 默认布局
     */
    public function getProductLayout(int $productId, string $layoutType): ?string
    {
        // 1. 检查是否有活动的布局计划
        $activeSchedule = $this->scheduleModel->getActiveScheduleByProduct($productId, $layoutType);
        if ($activeSchedule) {
            return $activeSchedule->getLayoutCode();
        }

        // 2. 检查产品专属布局
        $productLayout = $this->productLayoutModel->getByProductAndType($productId, $layoutType);
        if ($productLayout) {
            return $productLayout->getLayoutCode();
        }

        // 3. 返回null，使用默认布局
        return null;
    }

    /**
     * 应用产品布局
     */
    public function applyProductLayout(int $productId, string $layoutType, string $layoutCode, array $config = []): bool
    {
        try {
            // 查找或创建产品布局记录
            $productLayout = $this->productLayoutModel->getByProductAndType($productId, $layoutType);
            
            if (!$productLayout) {
                $productLayout = ObjectManager::getInstance(ProductLayout::class);
                $productLayout->setProductId($productId)
                    ->setLayoutType($layoutType);
            }

            $productLayout->setLayoutCode($layoutCode)
                ->setIsActive(true);
            
            if (!empty($config)) {
                $productLayout->setConfig($config);
            }

            $productLayout->save();

            // 清除缓存
            $this->clearProductLayoutCache($productId, $layoutType);

            return true;
        } catch (\Exception $e) {
            // 记录错误日志
            if (function_exists('w_log')) {
                w_log("应用产品布局失败: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * 获取产品布局计划
     */
    public function getProductLayoutSchedule(int $productId, string $layoutType): ?ProductLayoutSchedule
    {
        return $this->scheduleModel->getActiveScheduleByProduct($productId, $layoutType);
    }

    /**
     * 创建产品布局计划
     */
    public function createProductLayoutSchedule(
        int $productId,
        string $layoutType,
        string $layoutCode,
        string $startTime,
        ?string $endTime = null,
        bool $isRecurring = false,
        string $cronExpression = '',
        string $description = ''
    ): ?ProductLayoutSchedule {
        try {
            $schedule = ObjectManager::getInstance(ProductLayoutSchedule::class);
            $schedule->setProductId($productId)
                ->setLayoutType($layoutType)
                ->setLayoutCode($layoutCode)
                ->setStartTime($startTime)
                ->setEndTime($endTime)
                ->setIsRecurring($isRecurring)
                ->setCronExpression($cronExpression)
                ->setStatus(ProductLayoutSchedule::STATUS_PENDING)
                ->setDescription($description)
                ->save();

            return $schedule;
        } catch (\Exception $e) {
            // 记录错误日志
            if (function_exists('w_log')) {
                w_log("创建产品布局计划失败: " . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * 更新产品布局计划
     */
    public function updateProductLayoutSchedule(
        int $scheduleId,
        array $data
    ): bool {
        try {
            $schedule = ObjectManager::getInstance(ProductLayoutSchedule::class);
            $schedule->load($scheduleId);

            if (!$schedule->getId()) {
                return false;
            }

            if (isset($data['layout_code'])) {
                $schedule->setLayoutCode($data['layout_code']);
            }
            if (isset($data['start_time'])) {
                $schedule->setStartTime($data['start_time']);
            }
            if (isset($data['end_time'])) {
                $schedule->setEndTime($data['end_time']);
            }
            if (isset($data['is_recurring'])) {
                $schedule->setIsRecurring((bool)$data['is_recurring']);
            }
            if (isset($data['cron_expression'])) {
                $schedule->setCronExpression($data['cron_expression']);
            }
            if (isset($data['status'])) {
                $schedule->setStatus($data['status']);
            }
            if (isset($data['description'])) {
                $schedule->setDescription($data['description']);
            }

            $schedule->save();

            // 清除相关缓存
            $this->clearProductLayoutCache($schedule->getProductId(), $schedule->getLayoutType());

            return true;
        } catch (\Exception $e) {
            if (function_exists('w_log')) {
                w_log("更新产品布局计划失败: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * 删除产品布局计划
     */
    public function deleteProductLayoutSchedule(int $scheduleId): bool
    {
        try {
            $schedule = ObjectManager::getInstance(ProductLayoutSchedule::class);
            $schedule->load($scheduleId);

            if (!$schedule->getId()) {
                return false;
            }

            $productId = $schedule->getProductId();
            $layoutType = $schedule->getLayoutType();

            $schedule->delete();

            // 清除相关缓存
            $this->clearProductLayoutCache($productId, $layoutType);

            return true;
        } catch (\Exception $e) {
            if (function_exists('w_log')) {
                w_log("删除产品布局计划失败: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * 获取产品的所有布局计划
     */
    public function getProductSchedules(int $productId, ?string $layoutType = null): array
    {
        return $this->scheduleModel->getByProduct($productId, $layoutType);
    }

    /**
     * 清除产品布局缓存
     */
    public function clearProductLayoutCache(int $productId, string $layoutType): void
    {
        // 按需创建缓存实例，避免通过构造函数注入导致类型不匹配
        /** @var CacheFactory $cacheFactory */
        $cacheFactory = ObjectManager::getInstance(CacheFactory::class);
        $cache = $cacheFactory->create();
        $cacheKey = self::CACHE_KEY_PREFIX . $layoutType . '_' . $productId;
        $cache->delete($cacheKey);
    }

    /**
     * 激活布局计划
     */
    public function activateSchedule(ProductLayoutSchedule $schedule): bool
    {
        try {
            // 应用布局到产品
            $this->applyProductLayout(
                $schedule->getProductId(),
                $schedule->getLayoutType(),
                $schedule->getLayoutCode()
            );

            // 更新计划状态
            $schedule->setStatus(ProductLayoutSchedule::STATUS_ACTIVE)->save();

            return true;
        } catch (\Exception $e) {
            if (function_exists('w_log')) {
                w_log("激活布局计划失败: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * 停用布局计划
     */
    public function deactivateSchedule(ProductLayoutSchedule $schedule): bool
    {
        try {
            if ($schedule->isRecurring()) {
                // 循环任务：重新计算下次执行时间
                $schedule->setStatus(ProductLayoutSchedule::STATUS_PENDING);
                // TODO: 根据 cron 表达式计算下次执行时间
            } else {
                // 非循环任务：标记为已完成
                $schedule->setStatus(ProductLayoutSchedule::STATUS_COMPLETED);
            }

            $schedule->save();

            // 清除相关缓存
            $this->clearProductLayoutCache($schedule->getProductId(), $schedule->getLayoutType());

            return true;
        } catch (\Exception $e) {
            if (function_exists('w_log')) {
                w_log("停用布局计划失败: " . $e->getMessage());
            }
            return false;
        }
    }
}

