<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Controller\Api;

use Weline\Framework\Controller\AbstractRestController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Service\CouponService;
use Weline\Marketing\Service\RuleEngine;
use Weline\Marketing\Model\Rule\Rule;

/**
 * 营销API控制器
 */
class Marketing extends AbstractRestController
{
    /**
     * 验证优惠券
     */
    public function validateCoupon(): string
    {
        try {
            $code = $this->request->getPost('code');
            $context = $this->request->getPost('context', []);

            if (empty($code)) {
                return $this->error(__('优惠券代码不能为空'));
            }

            /** @var CouponService $couponService */
            $couponService = ObjectManager::getInstance(CouponService::class);
            $result = $couponService->validateCoupon($code, $context);

            if (!$result) {
                return $this->error(__('优惠券无效或已过期'));
            }

            return $this->success($result);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 应用规则
     */
    public function applyRules(): string
    {
        try {
            $context = $this->request->getPost('context', []);
            $ruleType = $this->request->getPost('rule_type', 'automatic');

            /** @var Rule $rule */
            $rule = ObjectManager::getInstance(Rule::class);
            $rule->where(Rule::schema_fields_RULE_TYPE, $ruleType)
                ->where(Rule::schema_fields_STATUS, Rule::STATUS_ACTIVE)
                ->order(Rule::schema_fields_PRIORITY, 'DESC');

            $rules = $rule->select()->fetch()->getItems();
            
            /** @var RuleEngine $ruleEngine */
            $ruleEngine = ObjectManager::getInstance(RuleEngine::class);

            $results = [];
            foreach ($rules as $ruleItem) {
                $result = $ruleEngine->applyRule($ruleItem, $context);
                if ($result) {
                    $results[] = $result;
                    if ($ruleItem->getData(Rule::schema_fields_IS_STOP_PROCESSING)) {
                        break;
                    }
                }
            }

            return $this->success($results);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}

