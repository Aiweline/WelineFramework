<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Controller\Backend;

use Weline\Cdn\Model\ApiRule;
use Weline\Cdn\Service\CdnRuleCollector;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl as AclAttribute;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;

/**
 * API规则管理后台控制器
 * 
 * 管理从Api和Controller方法注释中收集到的CDN缓存规则
 * 
 * @package Weline_Cdn
 */
#[AclAttribute('Weline_Cdn::cdn_api_rules_manager', 'API规则管理', 'mdi-code-tags', 'API规则管理', '')]
class ApiRules extends BackendController
{
    /**
     * 获取API规则模型
     */
    private function getApiRuleModel(): ApiRule
    {
        return ObjectManager::getInstance(ApiRule::class);
    }

    /**
     * 获取规则收集器
     */
    private function getRuleCollector(): CdnRuleCollector
    {
        return ObjectManager::getInstance(CdnRuleCollector::class);
    }

    /**
     * API规则列表页面
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_api_rules_list', '查看API规则列表', 'mdi-view-list', '查看API规则列表')]
    public function index(): string
    {
        try {
            $module = $this->request->getParam('module', '');
            $trigger = $this->request->getParam('trigger', '');
            
            $query = $this->getApiRuleModel()->clear();
            
            // 按模块筛选
            if (!empty($module)) {
                $query->where(ApiRule::fields_MODULE, $module);
            }
            
            // 按触发方式筛选
            if (!empty($trigger)) {
                $query->where(ApiRule::fields_TRIGGER, $trigger);
            }
            
            $rules = $query->order(ApiRule::fields_CREATED_AT, 'DESC')
                ->select()
                ->fetch()
                ->getItems();
            
            // 按模块分组
            $groupedRules = [];
            foreach ($rules as $rule) {
                $moduleName = $rule->getData(ApiRule::fields_MODULE);
                if (!isset($groupedRules[$moduleName])) {
                    $groupedRules[$moduleName] = [];
                }
                $groupedRules[$moduleName][] = $rule;
            }
            
            $this->assign('groupedRules', $groupedRules);
            $this->assign('rules', $rules);
            $this->assign('module', $module);
            $this->assign('trigger', $trigger);
            
            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载API规则列表失败：%{1}', $e->getMessage()));
            $this->assign('groupedRules', []);
            $this->assign('rules', []);
            return $this->fetch();
        }
    }

    /**
     * 重新收集规则
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_api_rules_collect', '收集API规则', 'mdi-refresh', '重新收集API规则')]
    public function collect(): string
    {
        try {
            $collector = $this->getRuleCollector();
            $collected = $collector->collectAll();
            
            return $this->jsonResponse([
                'success' => true,
                'message' => __('规则收集完成，共收集 %{1} 条规则', [count($collected)]),
                'data' => [
                    'count' => count($collected)
                ]
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('规则收集失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 查看规则详情
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_api_rules_view', '查看规则详情', 'mdi-eye', '查看API规则详情')]
    public function view(): string
    {
        try {
            $ruleId = (int)$this->request->getParam('id');
            
            if (!$ruleId) {
                Message::error(__('规则ID不能为空'));
                return $this->redirect('*/backend/api-rules/index');
            }
            
            $rule = $this->getApiRuleModel()->reset()->load($ruleId);
            
            if (!$rule->getId()) {
                Message::error(__('规则不存在'));
                return $this->redirect('*/backend/api-rules/index');
            }
            
            $this->assign('rule', $rule);
            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载规则详情失败：%{1}', $e->getMessage()));
            return $this->redirect('*/backend/api-rules/index');
        }
    }

    /**
     * 启用/禁用规则
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_api_rules_toggle', '启用/禁用规则', 'mdi-toggle-switch', '启用或禁用API规则')]
    public function toggle(): string
    {
        try {
            $ruleId = (int)$this->request->getParam('id');
            $enabled = (int)$this->request->getParam('enabled', 1);
            
            if (!$ruleId) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('规则ID不能为空')
                ]);
            }
            
            $rule = $this->getApiRuleModel()->reset()->load($ruleId);
            
            if (!$rule->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('规则不存在')
                ]);
            }
            
            $rule->setData(ApiRule::fields_ENABLED, $enabled ? 1 : 0)
                ->save();
            
            return $this->jsonResponse([
                'success' => true,
                'message' => $enabled ? __('规则已启用') : __('规则已禁用')
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('操作失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 删除规则
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_api_rules_delete', '删除规则', 'mdi-delete', '删除API规则')]
    public function delete(): string
    {
        try {
            $ruleId = (int)$this->request->getParam('id');
            
            if (!$ruleId) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('规则ID不能为空')
                ]);
            }
            
            $rule = $this->getApiRuleModel()->reset()->load($ruleId);
            
            if (!$rule->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('规则不存在')
                ]);
            }
            
            $rule->delete();
            
            return $this->jsonResponse([
                'success' => true,
                'message' => __('规则已删除')
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('删除失败：%{1}', $e->getMessage())
            ]);
        }
    }
}
