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
use Weline\Framework\Acl\Acl;
use Weline\Framework\DateTime\Timezone;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Model\Rule\LocalDescription;
use Weline\Marketing\Model\Rule\Rule as RuleModel;
use Weline\Marketing\Service\ExternalManagedRuleOwnership;
use Weline\Marketing\Service\RuleEngine;

/**
 * 营销规则管理控制器
 */
#[Acl('Weline_Marketing::commerce:marketing:rules', '万能优惠规则', 'circle', '万能优惠规则管理', 'Weline_Backend::marketing_group')]
class Rule extends BackendController
{
    /**
     * 规则列表
     */
    #[Acl('Weline_Marketing::commerce:marketing:rules_index', '万能优惠规则列表', 'list', '查看万能优惠规则列表')]
    public function index(): string
    {
        try {
            $search = trim((string)$this->request->getGet('search'));
            $items = $this->fetchRuleListItems($search);

            /** @var ExternalManagedRuleOwnership $ownership */
            $ownership = ObjectManager::getInstance(ExternalManagedRuleOwnership::class);
            $rows = [];
            foreach ($items as $item) {
                $row = is_array($item) ? $item : (method_exists($item, 'getData') ? $item->getData() : []);
                if (!is_array($row)) {
                    continue;
                }
                $meta = $ownership->describe($row);
                $row['external_managed'] = $meta['managed'];
                $row['external_manage_hint'] = $meta['hint'];
                $row['external_module_label'] = $meta['module_label'];
                $rows[] = $row;
            }
            $this->assign('rules', $rows);
            $this->assign('pagination', $this->lastRuleListPagination);

            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载规则列表失败：%{1}', $e->getMessage()));
            $this->assign('rules', []);
            return $this->fetch();
        }
    }

    /** @var mixed */
    private mixed $lastRuleListPagination = null;

    /**
     * @return list<array<string, mixed>|object>
     */
    private function fetchRuleListItems(string $search): array
    {
        $attempts = [true, false];
        $lastError = null;
        foreach ($attempts as $withLocal) {
            try {
                /** @var RuleModel $rule */
                $rule = ObjectManager::getInstance(RuleModel::class);
                $rule->clear();
                if ($search !== '') {
                    $rule->where('name', "%{$search}%", 'like');
                }
                if ($withLocal) {
                    $rule->loadLocalDescription('', LocalDescription::class);
                }
                $rule->pagination()->select()->fetch();
                $this->lastRuleListPagination = $rule->getPagination();

                return $rule->getItems() ?: [];
            } catch (\Throwable $e) {
                $lastError = $e;
                if ($withLocal) {
                    continue;
                }
                throw $e;
            }
        }

        throw $lastError instanceof \Throwable
            ? $lastError
            : new \RuntimeException((string)__('加载规则列表失败'));
    }

    /**
     * 添加规则（URL：…/rule/add；方法名 getAdd 仅表示 GET）
     */
    #[Acl('Weline_Marketing::rule_add', '添加规则', 'plus', '添加营销规则')]
    public function getAdd(): string
    {
        return $this->renderForm();
    }

    /**
     * 编辑规则（URL：…/rule/edit?id=；方法名 getEdit 仅表示 GET）
     */
    #[Acl('Weline_Marketing::rule_edit', '编辑规则', 'edit', '编辑营销规则')]
    public function getEdit(): string
    {
        $id = (int)$this->request->getParam('id', 0);
        /** @var RuleModel $rule */
        $rule = ObjectManager::getInstance(RuleModel::class);
        $rule->load($id);
        if (!$rule->getId()) {
            Message::error(__('规则不存在'));

            return $this->redirect('marketing/backend/rule/index');
        }

        /** @var ExternalManagedRuleOwnership $ownership */
        $ownership = ObjectManager::getInstance(ExternalManagedRuleOwnership::class);
        if ($ownership->isExternallyManaged($rule)) {
            Message::warning($ownership->mutationDeniedMessage($rule, 'save'));
        }

        return $this->renderForm($rule);
    }

    private function renderForm(?RuleModel $rule = null): string
    {
        try {
            /** @var RuleEngine $ruleEngine */
            $ruleEngine = ObjectManager::getInstance(RuleEngine::class);
            $this->assign('conditions', $ruleEngine->getAvailableConditions());
            $this->assign('actions', $ruleEngine->getAvailableActions());
        } catch (\Exception $e) {
            Message::error(__('加载规则表单失败：%{1}', $e->getMessage()));
            $this->assign('conditions', []);
            $this->assign('actions', []);
        }

        $this->assign('rule', $rule);
        /** @var ExternalManagedRuleOwnership $ownership */
        $ownership = ObjectManager::getInstance(ExternalManagedRuleOwnership::class);
        $meta = $rule ? $ownership->describe($rule) : ['managed' => false, 'hint' => ''];
        $this->assign('external_managed', !empty($meta['managed']));
        $this->assign('external_manage_hint', (string)($meta['hint'] ?? ''));

        return $this->fetch('form');
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
            $data[RuleModel::schema_fields_START_DATE] = $this->optionalLocalToUtc(
                (string)($data[RuleModel::schema_fields_START_DATE] ?? ''),
            );
            $data[RuleModel::schema_fields_END_DATE] = $this->optionalLocalToUtc(
                (string)($data[RuleModel::schema_fields_END_DATE] ?? ''),
            );
            $now = Timezone::utcNowSql();
            $data[RuleModel::schema_fields_UPDATED_AT] = $now;
            
            /** @var RuleModel $rule */
            $rule = ObjectManager::getInstance(RuleModel::class);
            
            if (!empty($data['id'])) {
                $rule->load($data['id']);
                /** @var ExternalManagedRuleOwnership $ownership */
                $ownership = ObjectManager::getInstance(ExternalManagedRuleOwnership::class);
                if ($ownership->isExternallyManaged($rule)) {
                    throw new \RuntimeException($ownership->mutationDeniedMessage($rule, 'save'));
                }
            } else {
                $data[RuleModel::schema_fields_CREATED_AT] = $now;
            }
            
            // 处理条件和动作
            if ($conditions !== null) {
                $rule->setConditions($this->normalizeConditionWindows($conditions));
            }
            if ($actions !== null) {
                $rule->setActions($actions);
            }
            
            $rule->setData($data);
            $rule->save();
            
            Message::success(__('规则保存成功'));
        } catch (\Exception $e) {
            Message::error(__('保存规则失败：%{1}', $e->getMessage()));
            $failId = (int)($this->request->getPost('id') ?? 0);

            return $failId > 0
                ? $this->redirect('marketing/backend/rule/edit', ['id' => $failId])
                : $this->redirect('marketing/backend/rule/add');
        }

        return $this->redirect('marketing/backend/rule/index');
    }

    /**
     * 删除规则（外部托管规则拒绝删除）
     */
    #[Acl('Weline_Marketing::rule_delete', '删除规则', 'trash', '删除营销规则')]
    public function postDelete(): string
    {
        try {
            $id = (int)($this->request->getPost('id') ?? $this->request->getParam('id') ?? 0);
            if ($id <= 0) {
                throw new \InvalidArgumentException((string)__('规则ID不能为空'));
            }

            /** @var RuleModel $rule */
            $rule = ObjectManager::getInstance(RuleModel::class);
            $rule->load($id);
            if (!$rule->getId()) {
                throw new \RuntimeException((string)__('规则不存在'));
            }

            /** @var ExternalManagedRuleOwnership $ownership */
            $ownership = ObjectManager::getInstance(ExternalManagedRuleOwnership::class);
            if ($ownership->isExternallyManaged($rule)) {
                throw new \RuntimeException($ownership->deletionDeniedMessage($rule));
            }

            $rule->delete();
            Message::success(__('规则已删除'));
        } catch (\Exception $e) {
            Message::error(__('删除规则失败：%{1}', $e->getMessage()));
        }

        return $this->redirect('marketing/backend/rule/index');
    }

    private function optionalLocalToUtc(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        return Timezone::localInputToUtcSql($input);
    }

    /**
     * @param array<string, mixed> $conditions
     * @return array<string, mixed>
     */
    private function normalizeConditionWindows(array $conditions): array
    {
        $type = strtolower(trim((string)($conditions['type'] ?? '')));
        if ($type === 'date_range') {
            foreach (['start_date', 'end_date'] as $key) {
                if (!isset($conditions[$key])) {
                    continue;
                }
                $raw = trim((string)$conditions[$key]);
                if ($raw === '') {
                    $conditions[$key] = null;
                    continue;
                }
                $utc = Timezone::localInputToUtcSql($raw);
                if ($utc !== null) {
                    $conditions[$key] = $utc;
                }
            }
        }
        if (isset($conditions['conditions']) && is_array($conditions['conditions'])) {
            foreach ($conditions['conditions'] as $idx => $child) {
                if (is_array($child)) {
                    $conditions['conditions'][$idx] = $this->normalizeConditionWindows($child);
                }
            }
        }

        return $conditions;
    }
}
