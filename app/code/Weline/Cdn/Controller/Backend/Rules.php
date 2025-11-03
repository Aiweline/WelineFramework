<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Controller\Backend;

use Weline\Cdn\Model\Domain as DomainModel;
use Weline\Cdn\Service\RuleManager;
use Weline\Cdn\Service\AdapterResolver;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Attribute\Acl as AclAttribute;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;

/**
 * CDN规则管理后台控制器
 * 
 * @package Weline_Cdn
 */
#[AclAttribute('Weline_Cdn::cdn_rules_manager', 'CDN规则管理', 'mdi-file-document-edit', 'CDN规则管理', '')]
class Rules extends BackendController
{
    /**
     * 获取域名模型
     */
    private function getDomainModel(): DomainModel
    {
        return ObjectManager::getInstance(DomainModel::class);
    }

    /**
     * 获取规则管理服务
     */
    private function getRuleManager(): RuleManager
    {
        return ObjectManager::getInstance(RuleManager::class);
    }

    /**
     * 规则列表页面（按域名分组）
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_rules_list', '查看规则列表', 'mdi-view-list', '查看CDN规则列表')]
    public function index(): string
    {
        try {
            $domains = $this->getDomainModel()->reset()
                ->where(DomainModel::fields_ENABLED, 1)
                ->select()
                ->fetch()
                ->getItems();

            $rulesList = [];
            foreach ($domains as $domain) {
                $mergedRules = $this->getRuleManager()->getMergedRules($domain);
                $rulesList[] = [
                    'domain' => $domain,
                    'rules' => $mergedRules,
                    'rules_count' => count($mergedRules)
                ];
            }

            $this->assign('rulesList', $rulesList);
            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载规则列表失败：%{1}', $e->getMessage()));
            $this->assign('rulesList', []);
            return $this->fetch();
        }
    }

    /**
     * 全局规则编辑页
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_rules_global_edit', '编辑全局规则', 'mdi-cog', '编辑全局默认规则')]
    public function getGlobalRules(): string
    {
        $rules = $this->getRuleManager()->getDefaultRules();
        $this->assign('rules', $rules);
        $this->assign('rulesJson', json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $this->fetch();
    }

    /**
     * 保存全局规则
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_rules_global_save', '保存全局规则', 'mdi-content-save', '保存全局默认规则')]
    public function saveGlobalRules(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        $rulesJson = $this->request->getPost('rules', '[]');
        $rules = json_decode($rulesJson, true);

        if (!is_array($rules)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('规则格式错误')
            ]);
        }

        try {
            $result = $this->getRuleManager()->saveDefaultRules($rules);
            
            if ($result) {
                Message::success(__('全局规则保存成功'));
                return $this->jsonResponse([
                    'success' => true,
                    'message' => __('全局规则保存成功')
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('保存失败')
                ]);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('保存失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 域名规则编辑页
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_rules_domain_edit', '编辑域名规则', 'mdi-file-edit', '编辑域名规则覆盖')]
    public function getDomainRules(): string
    {
        $id = (int)$this->request->getGet('id');

        if (!$id) {
            Message::error(__('域名ID不能为空'));
            return $this->redirect('*/backend/rules/index');
        }

        try {
            $domain = $this->getDomainModel()->reset()->load($id);
            
            if (!$domain->getData(DomainModel::fields_DOMAIN_ID)) {
                Message::error(__('域名不存在'));
                return $this->redirect('*/backend/rules/index');
            }

            $overrideRules = $domain->getRulesOverrideArray();
            $defaultRules = $this->getRuleManager()->getDefaultRules();
            $mergedRules = $this->getRuleManager()->getMergedRules($domain);

            $this->assign('domain', $domain);
            $this->assign('overrideRules', $overrideRules);
            $this->assign('defaultRules', $defaultRules);
            $this->assign('mergedRules', $mergedRules);
            $this->assign('overrideRulesJson', json_encode($overrideRules, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->assign('defaultRulesJson', json_encode($defaultRules, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->assign('mergedRulesJson', json_encode($mergedRules, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载域名规则失败：%{1}', $e->getMessage()));
            return $this->redirect('*/backend/rules/index');
        }
    }

    /**
     * 保存域名规则
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_rules_domain_save', '保存域名规则', 'mdi-content-save', '保存域名规则覆盖')]
    public function saveDomainRules(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        $id = (int)$this->request->getPost('id');
        $rulesJson = $this->request->getPost('rules', '[]');

        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('域名ID不能为空')
            ]);
        }

        $rules = json_decode($rulesJson, true);

        if (!is_array($rules)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('规则格式错误')
            ]);
        }

        try {
            $domain = $this->getDomainModel()->reset()->load($id);
            
            if (!$domain->getData(DomainModel::fields_DOMAIN_ID)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('域名不存在')
                ]);
            }

            $domain->setRulesOverrideArray($rules);
            $domain->save();

            Message::success(__('域名规则保存成功'));

            return $this->jsonResponse([
                'success' => true,
                'message' => __('域名规则保存成功')
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('保存失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 从CDN导入规则表单页
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_rules_import_form', '导入规则表单', 'mdi-download', '从CDN导入规则表单')]
    public function import(): string
    {
        $id = (int)$this->request->getGet('id');

        if (!$id) {
            Message::error(__('域名ID不能为空'));
            return $this->redirect('*/backend/rules/index');
        }

        try {
            $domain = $this->getDomainModel()->reset()->load($id);
            
            if (!$domain->getData(DomainModel::fields_DOMAIN_ID)) {
                Message::error(__('域名不存在'));
                return $this->redirect('*/backend/rules/index');
            }

            $this->assign('domain', $domain);
            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载域名失败：%{1}', $e->getMessage()));
            return $this->redirect('*/backend/rules/index');
        }
    }

    /**
     * 执行规则导入
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_rules_import_do', '执行规则导入', 'mdi-download', '从CDN导入规则')]
    public function doImport(): string
    {
        $id = (int)$this->request->getPost('id');

        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('域名ID不能为空')
            ]);
        }

        try {
            $domain = $this->getDomainModel()->reset()->load($id);
            
            if (!$domain->getData(DomainModel::fields_DOMAIN_ID)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('域名不存在')
                ]);
            }

            $result = $this->getRuleManager()->importRules($domain);

            if ($result['success']) {
                // 将导入的规则设置为域名覆盖规则
                $domain->setRulesOverrideArray($result['rules']);
                $domain->save();

                Message::success(__('规则导入成功'));

                return $this->jsonResponse([
                    'success' => true,
                    'message' => __('规则导入成功'),
                    'rules' => $result['rules']
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $result['message'] ?? __('规则导入失败')
                ]);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('导入失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 推送规则到CDN
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_rules_push', '推送规则', 'mdi-upload', '推送规则到CDN')]
    public function push(): string
    {
        $id = (int)$this->request->getPost('id');

        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('域名ID不能为空')
            ]);
        }

        try {
            $domain = $this->getDomainModel()->reset()->load($id);
            
            if (!$domain->getData(DomainModel::fields_DOMAIN_ID)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('域名不存在')
                ]);
            }

            $result = $this->getRuleManager()->pushRules($domain);

            if ($result['success']) {
                Message::success(__('规则推送成功'));
                return $this->jsonResponse([
                    'success' => true,
                    'message' => $result['message'] ?? __('规则推送成功')
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $result['message'] ?? __('规则推送失败')
                ]);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('推送失败：%{1}', $e->getMessage())
            ]);
        }
    }
}

