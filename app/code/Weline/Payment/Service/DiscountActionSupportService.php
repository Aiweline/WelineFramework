<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Service\RuleEngine;
use Weline\Payment\Model\PaymentMethod;

/**
 * 优惠方式支持服务
 * 
 * 负责发现所有可用的优惠方式，并检查支付方式是否支持特定优惠方式
 * 
 * @package Weline_Payment
 */
class DiscountActionSupportService
{
    private ?array $allActionsCache = null;
    
    /**
     * 获取所有可用的优惠方式
     * 
     * 从营销模块的RuleEngine获取所有Action（包括扩展的）
     * 
     * @return array 返回格式：['code' => ['code' => '...', 'name' => '...', 'description' => '...'], ...]
     */
    public function getAllDiscountActions(): array
    {
        if ($this->allActionsCache !== null) {
            return $this->allActionsCache;
        }
        
        try {
            /** @var RuleEngine $ruleEngine */
            $ruleEngine = ObjectManager::getInstance(RuleEngine::class);
            $this->allActionsCache = $ruleEngine->getAvailableActions();
        } catch (\Exception $e) {
            // 如果营销模块不可用，返回空数组
            $this->allActionsCache = [];
        }
        
        return $this->allActionsCache;
    }
    
    /**
     * 获取所有优惠方式的代码和名称映射
     * 
     * @return array 返回格式：['code' => 'name', ...]
     */
    public function getDiscountActionList(): array
    {
        $actions = $this->getAllDiscountActions();
        $list = [];
        
        foreach ($actions as $code => $action) {
            $list[$code] = $action['name'] ?? $code;
        }
        
        return $list;
    }
    
    /**
     * 检查支付方式是否支持优惠方式
     * 
     * @param string $paymentMethodCode 支付方式代码
     * @param string $actionCode 优惠方式代码
     * @return bool
     */
    public function checkSupport(string $paymentMethodCode, string $actionCode): bool
    {
        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = ObjectManager::getInstance(PaymentMethod::class);
        $paymentMethod->load(PaymentMethod::schema_fields_CODE, $paymentMethodCode);
        
        if (!$paymentMethod->getId()) {
            // 如果支付方式不存在，默认支持所有
            return true;
        }
        
        // 使用PaymentMethod的方法检查支持
        return $paymentMethod->supportsDiscountAction($actionCode);
    }
    
    /**
     * 获取支付方式支持的所有优惠方式
     * 
     * @param string $paymentMethodCode 支付方式代码
     * @return array 支持的优惠方式代码数组
     */
    public function getSupportedActions(string $paymentMethodCode): array
    {
        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = ObjectManager::getInstance(PaymentMethod::class);
        $paymentMethod->load(PaymentMethod::schema_fields_CODE, $paymentMethodCode);
        
        if (!$paymentMethod->getId()) {
            // 如果支付方式不存在，返回所有可用优惠方式
            return array_keys($this->getAllDiscountActions());
        }
        
        $supported = $paymentMethod->getSupportedDiscountActions();
        
        // 如果为空数组，表示支持所有
        if (empty($supported)) {
            return array_keys($this->getAllDiscountActions());
        }
        
        return $supported;
    }
    
    /**
     * 验证支付方式是否支持优惠方式列表
     * 
     * @param string $paymentMethodCode 支付方式代码
     * @param array $actionCodes 优惠方式代码数组
     * @return array 返回不支持的优惠方式代码数组
     */
    public function validateActions(string $paymentMethodCode, array $actionCodes): array
    {
        $unsupported = [];
        
        foreach ($actionCodes as $actionCode) {
            if (!$this->checkSupport($paymentMethodCode, $actionCode)) {
                $unsupported[] = $actionCode;
            }
        }
        
        return $unsupported;
    }
}

