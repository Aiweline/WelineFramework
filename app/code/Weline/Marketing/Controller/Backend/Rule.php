<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\Message;
use Weline\Framework\Acl\Acl;
use Weline\Marketing\Model\Rule\LocalDescription;
use Weline\Marketing\Model\Rule\Rule as RuleModel;
use Weline\Marketing\Service\RuleEngine;

/**
 * 营销规则管理控制器
 */
#[Acl('Weline_Marketing::rule', '营销规则', 'circle', '营销规则管理', 'Weline_Backend::marketing_group')]
class Rule extends BackendController
{
    /**
     * 规则列表
     */
    #[Acl('Weline_Marketing::rule_list', '规则列表', 'list', '查看营销规则列表')]
    public function index(): string
    {
        try {
            /** @var RuleModel $rule */
            $rule = ObjectManager::getInstance(RuleModel::class);
            
            if ($search = $this->request->getGet('search')) {
                $rule->where('name', "%{$search}%", 'like');
            }
            
            // 加载多语言翻译数据
            $rule->loadLocalDescription('', LocalDescription::class);
            
            $rule->pagination()->select()->fetch();
            $this->assign('rules', $rule->getItems());
            $this->assign('pagination', $rule->getPagination());
            
            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载规则列表失败：%{1}', $e->getMessage()));
            $this->assign('rules', []);
            return $this->fetch();
        }
    }

    /**
     * 添加规则
     */
    #[Acl('Weline_Marketing::rule_add', '添加规则', 'plus', '添加营销规则')]
    public function getAdd(): string
    {
        try {
            /** @var RuleEngine $ruleEngine */
            $ruleEngine = ObjectManager::getInstance(RuleEngine::class);
            $this->assign('conditions', $ruleEngine->getAvailableConditions());
            $this->assign('actions', $ruleEngine->getAvailableActions());
            return $this->fetch('form');
        } catch (\Exception $e) {
            Message::error(__('加载规则表单失败：%{1}', $e->getMessage()));
            return $this->fetch('form');
        }
    }

    /**
     * 保存规则
     */
    #[Acl('Weline_Marketing::rule_save', '保存规则', '', '保存营销规则')]
    public function postSave(): string
    {
        try {
            $input = $this->request->getPost();
            $input = is_array($input) ? $input : [];
            $conditions = isset($input['conditions']) && is_array($input['conditions']) ? $input['conditions'] : null;
            $actions = isset($input['actions']) && is_array($input['actions']) ? $input['actions'] : null;
            $data = array_intersect_key($input, array_flip([
                RuleModel::schema_fields_ID,
                RuleModel::schema_fields_NAME,
                RuleModel::schema_fields_DESCRIPTION,
                RuleModel::schema_fields_RULE_TYPE,
                RuleModel::schema_fields_STATUS,
                RuleModel::schema_fields_PRIORITY,
                RuleModel::schema_fields_START_DATE,
                RuleModel::schema_fields_END_DATE,
                RuleModel::schema_fields_USAGE_LIMIT,
                RuleModel::schema_fields_CUSTOMER_LIMIT,
                RuleModel::schema_fields_IS_STOP_PROCESSING,
                RuleModel::schema_fields_SORT_ORDER,
            ]));
            $data['name'] = trim((string)($data['name'] ?? ''));
            $data['rule_type'] = trim((string)($data['rule_type'] ?? ''));
            $data['status'] = trim((string)($data['status'] ?? RuleModel::STATUS_INACTIVE));
            if ($data['name'] === '' || !in_array($data['rule_type'], [
                RuleModel::RULE_TYPE_COUPON,
                RuleModel::RULE_TYPE_CAMPAIGN,
                RuleModel::RULE_TYPE_AUTOMATIC,
            ], true)) {
                throw new \InvalidArgumentException((string)__('规则名称和有效类型不能为空'));
            }
            if (!in_array($data['status'], [
                RuleModel::STATUS_ACTIVE,
                RuleModel::STATUS_INACTIVE,
                RuleModel::STATUS_EXPIRED,
            ], true)) {
                throw new \InvalidArgumentException((string)__('规则状态无效'));
            }
            $now = date('Y-m-d H:i:s');
            $data[RuleModel::schema_fields_UPDATED_AT] = $now;
            
            /** @var RuleModel $rule */
            $rule = ObjectManager::getInstance(RuleModel::class);
            
            if (!empty($data['id'])) {
                $rule->load($data['id']);
            } else {
                $data[RuleModel::schema_fields_CREATED_AT] = $now;
            }
            
            // 处理条件和动作
            if ($conditions !== null) {
                $rule->setConditions($conditions);
            }
            if ($actions !== null) {
                $rule->setActions($actions);
            }
            
            $rule->setData($data);
            $rule->save();
            
            Message::success(__('规则保存成功'));
        } catch (\Exception $e) {
            Message::error(__('保存规则失败：%{1}', $e->getMessage()));
            return $this->redirect('marketing/backend/rule/getAdd');
        }

        return $this->redirect('marketing/backend/rule/index');
    }
}
