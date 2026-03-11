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
use Weline\Marketing\Model\Rule\Rule as RuleModel;
use Weline\Marketing\Service\RuleEngine;

/**
 * 营销规则管理控制器
 */
#[Acl('Weline_Marketing::rule', '营销规则', 'mdi-rule', '营销规则管理', 'Weline_Backend::marketing_group')]
class Rule extends BackendController
{
    /**
     * 规则列表
     */
    #[Acl('Weline_Marketing::rule_list', '规则列表', 'mdi-format-list-bulleted', '查看营销规则列表')]
    public function index(): string
    {
        try {
            /** @var RuleModel $rule */
            $rule = ObjectManager::getInstance(RuleModel::class);
            
            if ($search = $this->request->getGet('search')) {
                $rule->where('name', "%{$search}%", 'like');
            }
            
            // 加载多语言翻译数据
            $rule->loadLocalDescription();
            
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
    #[Acl('Weline_Marketing::rule_add', '添加规则', 'mdi-plus', '添加营销规则')]
    public function getAdd(): string
    {
        try {
            /** @var RuleEngine $ruleEngine */
            $ruleEngine = ObjectManager::getInstance(RuleEngine::class);
            $this->assign('conditions', $ruleEngine->getAvailableConditions());
            $this->assign('actions', $ruleEngine->getAvailableActions());
            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载规则表单失败：%{1}', $e->getMessage()));
            return $this->fetch();
        }
    }

    /**
     * 保存规则
     */
    #[Acl('Weline_Marketing::rule_save', '保存规则', '', '保存营销规则')]
    public function postSave(): string
    {
        try {
            $data = $this->request->getPost();
            
            /** @var RuleModel $rule */
            $rule = ObjectManager::getInstance(RuleModel::class);
            
            if (!empty($data['id'])) {
                $rule->load($data['id']);
            }
            
            // 处理条件和动作
            if (isset($data['conditions'])) {
                $rule->setConditions($data['conditions']);
            }
            if (isset($data['actions'])) {
                $rule->setActions($data['actions']);
            }
            
            $rule->setData($data);
            $rule->save();
            
            Message::success(__('规则保存成功'));
            $this->redirect('marketing/backend/rule');
        } catch (\Exception $e) {
            Message::error(__('保存规则失败：%{1}', $e->getMessage()));
            $this->redirect('marketing/backend/rule/getAdd');
        }
    }
}

