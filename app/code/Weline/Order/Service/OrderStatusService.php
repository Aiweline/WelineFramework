<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Service;

use Weline\Order\Model\OrderStatus;
use Weline\Order\Model\OrderStatusTranslation;
use Weline\Framework\Manager\ObjectManager;

/**
 * 订单状态服务
 * 
 * 管理订单状态的获取、翻译等功能
 */
class OrderStatusService
{
    private ObjectManager $objectManager;
    private ?array $statusCache = null;
    private ?array $translationCache = null;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * 获取所有启用的订单状态
     * 
     * @return OrderStatus[]
     */
    public function getActiveStatuses(): array
    {
        if ($this->statusCache === null) {
            /** @var OrderStatus $statusModel */
            $statusModel = $this->objectManager->getInstance(OrderStatus::class);
            $this->statusCache = $statusModel->where(OrderStatus::fields_IS_ACTIVE, 1)
                ->order(OrderStatus::fields_SORT_ORDER, 'ASC')
                ->select()
                ->fetch();
        }
        
        return $this->statusCache;
    }

    /**
     * 根据代码获取订单状态
     * 
     * @param string $code
     * @return OrderStatus|null
     */
    public function getStatusByCode(string $code): ?OrderStatus
    {
        /** @var OrderStatus $statusModel */
        $statusModel = $this->objectManager->getInstance(OrderStatus::class);
        $statusModel->load(OrderStatus::fields_CODE, $code);
        
        if (!$statusModel->getId()) {
            return null;
        }
        
        return $statusModel;
    }

    /**
     * 获取状态的翻译名称
     * 
     * @param string $code 状态代码
     * @param string|null $locale 语言代码，如果为null则使用当前语言
     * @return string
     */
    public function getStatusName(string $code, ?string $locale = null): string
    {
        // 获取状态定义
        $status = $this->getStatusByCode($code);
        if (!$status) {
            // 如果状态不存在，尝试从翻译文件获取
            $translationKey = 'order_status_' . $code;
            $translated = __($translationKey);
            if ($translated !== $translationKey) {
                return $translated;
            }
            // 如果翻译也不存在，返回代码本身
            return $code;
        }

        // 如果指定了语言，从翻译表获取
        if ($locale !== null) {
            $translation = $this->getTranslation($code, $locale);
            if ($translation) {
                return $translation->getData(OrderStatusTranslation::fields_NAME);
            }
        }

        // 使用翻译系统获取翻译
        $translationKey = 'order_status_' . $code;
        $translated = __($translationKey);
        
        // 如果翻译不存在，返回状态默认名称
        if ($translated === $translationKey) {
            return $status->getData(OrderStatus::fields_NAME);
        }
        
        return $translated;
    }

    /**
     * 获取状态翻译
     * 
     * @param string $code 状态代码
     * @param string $locale 语言代码
     * @return OrderStatusTranslation|null
     */
    public function getTranslation(string $code, string $locale): ?OrderStatusTranslation
    {
        $cacheKey = $code . '_' . $locale;
        
        if ($this->translationCache === null) {
            $this->translationCache = [];
        }
        
        if (isset($this->translationCache[$cacheKey])) {
            return $this->translationCache[$cacheKey];
        }
        
        /** @var OrderStatusTranslation $translationModel */
        $translationModel = $this->objectManager->getInstance(OrderStatusTranslation::class);
        $translationModel->where(OrderStatusTranslation::fields_STATUS_CODE, $code)
            ->where(OrderStatusTranslation::fields_LOCALE, $locale)
            ->find()
            ->fetch();
        
        if ($translationModel->getId()) {
            $this->translationCache[$cacheKey] = $translationModel;
            return $translationModel;
        }
        
        return null;
    }

    /**
     * 获取状态的CSS类名（用于UI显示）
     * 
     * @param string $code
     * @return string
     */
    public function getStatusClass(string $code): string
    {
        $status = $this->getStatusByCode($code);
        if ($status) {
            return $status->getData(OrderStatus::fields_COLOR) ?? 'secondary';
        }
        
        // 默认映射
        $defaultClasses = [
            'pending' => 'warning',
            'processing' => 'info',
            'paid' => 'primary',
            'fulfilled' => 'success',
            'completed' => 'success',
            'cancelled' => 'danger',
            'refunded' => 'secondary',
        ];
        
        return $defaultClasses[$code] ?? 'secondary';
    }

    /**
     * 获取状态的图标
     * 
     * @param string $code
     * @return string|null
     */
    public function getStatusIcon(string $code): ?string
    {
        $status = $this->getStatusByCode($code);
        if ($status) {
            return $status->getData(OrderStatus::fields_ICON);
        }
        
        return null;
    }

    /**
     * 初始化默认状态
     * 
     * 在模块安装时调用，创建默认的订单状态
     */
    public function initDefaultStatuses(): void
    {
        $defaultStatuses = [
            [
                'code' => 'pending',
                'name' => '待处理',
                'description' => '订单已创建，等待处理',
                'color' => 'warning',
                'icon' => 'mdi-clock-outline',
                'is_system' => 1,
                'sort_order' => 1,
            ],
            [
                'code' => 'processing',
                'name' => '处理中',
                'description' => '订单正在处理中',
                'color' => 'info',
                'icon' => 'mdi-cog',
                'is_system' => 1,
                'sort_order' => 2,
            ],
            [
                'code' => 'paid',
                'name' => '已支付',
                'description' => '订单已支付',
                'color' => 'primary',
                'icon' => 'mdi-check-circle',
                'is_system' => 1,
                'sort_order' => 3,
            ],
            [
                'code' => 'fulfilled',
                'name' => '已发货',
                'description' => '订单已发货',
                'color' => 'success',
                'icon' => 'mdi-truck',
                'is_system' => 1,
                'sort_order' => 4,
            ],
            [
                'code' => 'completed',
                'name' => '已完成',
                'description' => '订单已完成',
                'color' => 'success',
                'icon' => 'mdi-check-all',
                'is_system' => 1,
                'sort_order' => 5,
            ],
            [
                'code' => 'cancelled',
                'name' => '已取消',
                'description' => '订单已取消',
                'color' => 'danger',
                'icon' => 'mdi-close-circle',
                'is_system' => 1,
                'sort_order' => 6,
            ],
            [
                'code' => 'refunded',
                'name' => '已退款',
                'description' => '订单已退款',
                'color' => 'secondary',
                'icon' => 'mdi-cash-refund',
                'is_system' => 1,
                'sort_order' => 7,
            ],
        ];

        /** @var OrderStatus $statusModel */
        $statusModel = $this->objectManager->getInstance(OrderStatus::class);
        
        foreach ($defaultStatuses as $statusData) {
            $statusModel->reset();
            $statusModel->load(OrderStatus::fields_CODE, $statusData['code']);
            
            if (!$statusModel->getId()) {
                $statusModel->setData($statusData)
                    ->setData(OrderStatus::fields_IS_ACTIVE, 1)
                    ->save();
            }
        }
    }
}

