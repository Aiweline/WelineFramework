<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Interface\Rule\ConditionInterface;
use Weline\Marketing\Interface\Rule\ActionInterface;
use Weline\Marketing\Model\Rule\Rule;
use Weline\Framework\Extends\ExtendsRegistry;

/**
 * 规则引擎服务
 * 
 * 负责规则的加载、条件验证和动作执行
 * 
 * @package Weline_Marketing
 */
class RuleEngine
{
    /**
     * @var array 条件类缓存
     */
    protected array $conditionClasses = [];

    /**
     * @var array 动作类缓存
     */
    protected array $actionClasses = [];

    /**
     * @var ExtendsRegistry
     */
    protected ExtendsRegistry $extendsRegistry;

    public function __construct(ExtendsRegistry $extendsRegistry)
    {
        $this->extendsRegistry = $extendsRegistry;
        $this->loadConditionClasses();
        $this->loadActionClasses();
    }

    /**
     * 加载所有条件类
     *
     * @return void
     */
    protected function loadConditionClasses(): void
    {
        // 加载内置条件类
        $builtinConditions = [
            \Weline\Marketing\Model\Rule\Condition\Customer\CustomerGroup::class,
            \Weline\Marketing\Model\Rule\Condition\Customer\CustomerTags::class,
            \Weline\Marketing\Model\Rule\Condition\Customer\RegistrationDate::class,
            \Weline\Marketing\Model\Rule\Condition\Customer\TotalSpent::class,
            \Weline\Marketing\Model\Rule\Condition\Customer\OrderCount::class,
            \Weline\Marketing\Model\Rule\Condition\Product\Category::class,
            \Weline\Marketing\Model\Rule\Condition\Product\Brand::class,
            \Weline\Marketing\Model\Rule\Condition\Product\Sku::class,
            \Weline\Marketing\Model\Rule\Condition\Product\Price::class,
            \Weline\Marketing\Model\Rule\Condition\Order\Subtotal::class,
            \Weline\Marketing\Model\Rule\Condition\Order\ItemCount::class,
            \Weline\Marketing\Model\Rule\Condition\Geographic\Country::class,
            \Weline\Marketing\Model\Rule\Condition\Geographic\Region::class,
            \Weline\Marketing\Model\Rule\Condition\Geographic\City::class,
            \Weline\Marketing\Model\Rule\Condition\Currency\CurrencyCode::class,
            \Weline\Marketing\Model\Rule\Condition\Time\DateRange::class,
            \Weline\Marketing\Model\Rule\Condition\Time\DayOfWeek::class,
        ];

        foreach ($builtinConditions as $class) {
            if (class_exists($class)) {
                try {
                    $instance = ObjectManager::getInstance($class);
                    if ($instance instanceof ConditionInterface) {
                        $this->conditionClasses[$instance->getCode()] = $class;
                    }
                } catch (\Exception $e) {
                    // 忽略不存在的类
                }
            }
        }

        // 加载扩展条件类
        $this->loadExtendedConditions();
    }

    /**
     * 加载扩展条件类
     *
     * @return void
     */
    protected function loadExtendedConditions(): void
    {
        $extends = $this->extendsRegistry->getModuleExtends('Weline_Marketing');
        if (isset($extends['Condition']) && is_array($extends['Condition'])) {
            foreach ($extends['Condition'] as $extend) {
                if (isset($extend['class']) && class_exists($extend['class'])) {
                    try {
                        $instance = ObjectManager::getInstance($extend['class']);
                        if ($instance instanceof ConditionInterface) {
                            $this->conditionClasses[$instance->getCode()] = $extend['class'];
                        }
                    } catch (\Exception $e) {
                        // 忽略错误
                    }
                }
            }
        }
    }

    /**
     * 加载所有动作类
     *
     * @return void
     */
    protected function loadActionClasses(): void
    {
        // 加载内置动作类
        $builtinActions = [
            \Weline\Marketing\Model\Rule\Action\Discount\Percentage::class,
            \Weline\Marketing\Model\Rule\Action\Discount\FixedAmount::class,
            \Weline\Marketing\Model\Rule\Action\Discount\BuyXGetY::class,
            \Weline\Marketing\Model\Rule\Action\Shipping\FreeShipping::class,
            \Weline\Marketing\Model\Rule\Action\Gift\GiftProduct::class,
        ];

        foreach ($builtinActions as $class) {
            if (class_exists($class)) {
                try {
                    $instance = ObjectManager::getInstance($class);
                    if ($instance instanceof ActionInterface) {
                        $this->actionClasses[$instance->getCode()] = $class;
                    }
                } catch (\Exception $e) {
                    // 忽略不存在的类
                }
            }
        }

        // 加载扩展动作类
        $this->loadExtendedActions();
    }

    /**
     * 加载扩展动作类
     *
     * @return void
     */
    protected function loadExtendedActions(): void
    {
        $extends = $this->extendsRegistry->getModuleExtends('Weline_Marketing');
        if (isset($extends['Action']) && is_array($extends['Action'])) {
            foreach ($extends['Action'] as $extend) {
                if (isset($extend['class']) && class_exists($extend['class'])) {
                    try {
                        $instance = ObjectManager::getInstance($extend['class']);
                        if ($instance instanceof ActionInterface) {
                            $this->actionClasses[$instance->getCode()] = $extend['class'];
                        }
                    } catch (\Exception $e) {
                        // 忽略错误
                    }
                }
            }
        }
    }

    /**
     * 验证条件
     *
     * @param array $conditions 条件配置
     * @param array $context 上下文数据
     * @return bool
     */
    public function validateConditions(array $conditions, array $context): bool
    {
        if (empty($conditions)) {
            return true;
        }

        $type = $conditions['type'] ?? 'and';
        $conditionList = $conditions['conditions'] ?? [];

        if (empty($conditionList)) {
            return true;
        }

        $results = [];
        foreach ($conditionList as $condition) {
            if (isset($condition['type']) && in_array($condition['type'], ['and', 'or'])) {
                // 嵌套条件
                $results[] = $this->validateConditions($condition, $context);
            } else {
                // 具体条件
                $results[] = $this->validateSingleCondition($condition, $context);
            }
        }

        if ($type === 'and') {
            return !in_array(false, $results, true);
        } else {
            return in_array(true, $results, true);
        }
    }

    /**
     * 验证单个条件
     *
     * @param array $condition 条件配置
     * @param array $context 上下文数据
     * @return bool
     */
    protected function validateSingleCondition(array $condition, array $context): bool
    {
        $type = $condition['type'] ?? '';
        if (empty($type) || !isset($this->conditionClasses[$type])) {
            return false;
        }

        try {
            $conditionClass = $this->conditionClasses[$type];
            $conditionInstance = ObjectManager::getInstance($conditionClass);
            return $conditionInstance->validate($condition, $context);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 执行动作
     *
     * @param array $actions 动作配置
     * @param array $context 上下文数据
     * @return array 执行结果
     */
    public function executeActions(array $actions, array $context): array
    {
        $result = [
            'discount_amount' => 0,
            'shipping_discount' => 0,
            'free_shipping' => false,
            'gifts' => [],
            'messages' => [],
        ];

        if (empty($actions) || !is_array($actions)) {
            return $result;
        }

        // 支持多个动作
        if (isset($actions['type'])) {
            $actions = [$actions];
        }

        foreach ($actions as $action) {
            $type = $action['type'] ?? '';
            if (empty($type) || !isset($this->actionClasses[$type])) {
                continue;
            }

            try {
                $actionClass = $this->actionClasses[$type];
                $actionInstance = ObjectManager::getInstance($actionClass);
                $actionResult = $actionInstance->execute($action, $context);
                
                // 合并结果
                if (isset($actionResult['discount_amount'])) {
                    $result['discount_amount'] += $actionResult['discount_amount'];
                }
                if (isset($actionResult['shipping_discount'])) {
                    $result['shipping_discount'] += $actionResult['shipping_discount'];
                }
                if (isset($actionResult['free_shipping'])) {
                    $result['free_shipping'] = true;
                }
                if (isset($actionResult['gifts'])) {
                    $result['gifts'] = array_merge($result['gifts'], $actionResult['gifts']);
                }
                if (isset($actionResult['messages'])) {
                    $result['messages'] = array_merge($result['messages'], $actionResult['messages']);
                }
            } catch (\Exception $e) {
                // 忽略错误
            }
        }

        return $result;
    }

    /**
     * 应用规则
     *
     * @param Rule $rule 规则对象
     * @param array $context 上下文数据
     * @return array|null 返回执行结果，如果规则不适用则返回null
     */
    public function applyRule(Rule $rule, array $context): ?array
    {
        // 检查规则是否激活
        if (!$rule->isActive()) {
            return null;
        }

        // 验证条件
        $conditions = $rule->getConditions();
        if ($conditions && !$this->validateConditions($conditions, $context)) {
            return null;
        }

        // 执行动作
        $actions = $rule->getActions();
        if (empty($actions)) {
            return null;
        }

        $result = $this->executeActions($actions, $context);
        $result['rule_id'] = $rule->getId();
        $result['rule_name'] = $rule->getData(Rule::fields_NAME);

        return $result;
    }

    /**
     * 获取所有可用的条件类
     *
     * @return array
     */
    public function getAvailableConditions(): array
    {
        $conditions = [];
        foreach ($this->conditionClasses as $code => $class) {
            try {
                $instance = ObjectManager::getInstance($class);
                $conditions[$code] = [
                    'code' => $code,
                    'name' => $instance->getName(),
                    'description' => $instance->getDescription(),
                    'form_fields' => $instance->getFormFields(),
                ];
            } catch (\Exception $e) {
                // 忽略错误
            }
        }
        return $conditions;
    }

    /**
     * 获取所有可用的动作类
     *
     * @return array
     */
    public function getAvailableActions(): array
    {
        $actions = [];
        foreach ($this->actionClasses as $code => $class) {
            try {
                $instance = ObjectManager::getInstance($class);
                $actions[$code] = [
                    'code' => $code,
                    'name' => $instance->getName(),
                    'description' => $instance->getDescription(),
                    'form_fields' => $instance->getFormFields(),
                ];
            } catch (\Exception $e) {
                // 忽略错误
            }
        }
        return $actions;
    }
}

