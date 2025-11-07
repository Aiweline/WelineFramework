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
use Weline\Framework\Acl\Acl as AclAttribute;
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
     * 获取全局规则（JSON API）
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_rules_global_edit', '编辑全局规则', 'mdi-cog', '编辑全局默认规则')]
    public function getGlobalRules(): string
    {
        try {
            $rules = $this->getRuleManager()->getDefaultRules();
            return $this->jsonResponse([
                'success' => true,
                'data' => $rules
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('获取全局规则失败：%{1}', $e->getMessage())
            ]);
        }
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
     * 获取域名规则（JSON API）
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_rules_domain_edit', '编辑域名规则', 'mdi-file-edit', '编辑域名规则覆盖')]
    public function getDomainRules(): string
    {
        $id = (int)$this->request->getGet('id') ?: (int)$this->request->getGet('domain_id');

        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('域名ID不能为空')
            ]);
        }

        try {
            $domain = $this->getDomainModel()->reset()->load($id);
            
            if (!$domain->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('域名不存在')
                ]);
            }

            $overrideRules = $domain->getRulesOverrideArray();
            $defaultRules = $this->getRuleManager()->getDefaultRules();
            $mergedRules = $this->getRuleManager()->getMergedRules($domain);

            return $this->jsonResponse([
                'success' => true,
                'data' => [
                    'overrideRules' => $overrideRules,
                    'defaultRules' => $defaultRules,
                    'mergedRules' => $mergedRules
                ]
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('加载域名规则失败：%{1}', $e->getMessage())
            ]);
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

        // 支持 JSON 和表单数据
        $params = $this->request->getParams();
        $id = (int)($params['id'] ?? $params['domain_id'] ?? 0);
        $rulesJson = $params['rules'] ?? '[]';

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
            
            if (!$domain->getId()) {
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
     * 获取所有启用的域名列表（用于前端逐个推送）
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_rules_list', '获取域名列表', 'mdi-view-list', '获取所有启用的域名列表')]
    public function getDomains(): string
    {
        try {
            $domains = $this->getDomainModel()->reset()
                ->where(DomainModel::fields_ENABLED, 1)
                ->select()
                ->fetch()
                ->getItems();

            $domainList = [];
            foreach ($domains as $domain) {
                $domainList[] = [
                    'domain_id' => $domain->getId(),
                    'domain_name' => $domain->getData(DomainModel::fields_DOMAIN_NAME)
                ];
            }

            return $this->jsonResponse([
                'success' => true,
                'domains' => $domainList
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('获取域名列表失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 推送规则到CDN（单个域名）
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_rules_push', '推送规则', 'mdi-upload', '推送规则到CDN')]
    public function push(): string
    {
        // 支持 JSON 和表单数据
        $params = $this->request->getParams();
        $id = (int)($params['id'] ?? 0);

        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('域名ID不能为空')
            ]);
        }

        try {
            $domain = $this->getDomainModel()->reset()->load($id);
            
            if (!$domain->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('域名不存在')
                ]);
            }

            $domainName = $domain->getData(DomainModel::fields_DOMAIN_NAME);
            $result = $this->getRuleManager()->pushRules($domain);

            if ($result['success']) {
                return $this->jsonResponse([
                    'success' => true,
                    'domain_id' => $id,
                    'domain_name' => $domainName,
                    'message' => $result['message'] ?? __('规则推送成功')
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'domain_id' => $id,
                    'domain_name' => $domainName,
                    'message' => $result['message'] ?? __('规则推送失败')
                ]);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'domain_id' => $id,
                'domain_name' => '',
                'message' => __('推送失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 推送规则到所有域名（逐个推送，输出详细日志）
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_rules_push_all', '推送所有域名', 'mdi-upload-multiple', '推送规则到所有域名')]
    public function pushAll(): string
    {
        try {
            // 获取所有启用的域名
            $domains = $this->getDomainModel()->reset()
                ->where(DomainModel::fields_ENABLED, 1)
                ->select()
                ->fetch()
                ->getItems();

            if (empty($domains)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('没有可推送的域名')
                ]);
            }

            $results = [];
            $logs = [];
            $total = count($domains);
            $successCount = 0;
            $failCount = 0;

            // 添加开始日志
            $logs[] = [
                'timestamp' => date('H:i:s'),
                'type' => 'info',
                'message' => __('开始推送规则到 %{1} 个域名...', [$total])
            ];

            // 逐个推送域名
            foreach ($domains as $index => $domain) {
                $domainName = $domain->getData(DomainModel::fields_DOMAIN_NAME);
                $domainId = $domain->getId();
                $currentIndex = $index + 1;
                $progress = round($currentIndex / $total * 100, 2);
                
                // 添加开始推送日志
                $logs[] = [
                    'timestamp' => date('H:i:s'),
                    'type' => 'info',
                    'message' => __('[%{1}/%{2}] 正在推送域名: %{3} (ID: %{4})', [$currentIndex, $total, $domainName, $domainId])
                ];
                
                try {
                    // 推送规则
                    $result = $this->getRuleManager()->pushRules($domain);
                    
                    if ($result['success']) {
                        $successCount++;
                        $logs[] = [
                            'timestamp' => date('H:i:s'),
                            'type' => 'success',
                            'message' => __('[%{1}/%{2}] ✓ 推送成功: %{3} - %{4}', [$currentIndex, $total, $domainName, $result['message'] ?? __('推送成功')])
                        ];
                    } else {
                        $failCount++;
                        $errorMsg = $result['message'] ?? __('推送失败');
                        $logs[] = [
                            'timestamp' => date('H:i:s'),
                            'type' => 'error',
                            'message' => __('[%{1}/%{2}] ✗ 推送失败: %{3} - %{4}', [$currentIndex, $total, $domainName, $errorMsg])
                        ];
                    }
                    
                    $results[] = [
                        'domain_id' => $domainId,
                        'domain_name' => $domainName,
                        'success' => $result['success'],
                        'message' => $result['message'] ?? ($result['success'] ? __('推送成功') : __('推送失败')),
                        'progress' => $progress,
                        'index' => $currentIndex,
                        'total' => $total
                    ];
                } catch (\Exception $e) {
                    $failCount++;
                    $errorMsg = $e->getMessage();
                    $logs[] = [
                        'timestamp' => date('H:i:s'),
                        'type' => 'error',
                        'message' => __('[%{1}/%{2}] ✗ 推送失败: %{3} - %{4}', [$currentIndex, $total, $domainName, __('推送失败：%{1}', $errorMsg)])
                    ];
                    
                    $results[] = [
                        'domain_id' => $domainId,
                        'domain_name' => $domainName,
                        'success' => false,
                        'message' => __('推送失败：%{1}', $errorMsg),
                        'progress' => $progress,
                        'index' => $currentIndex,
                        'total' => $total
                    ];
                }
            }

            // 添加完成日志
            $logs[] = [
                'timestamp' => date('H:i:s'),
                'type' => $failCount === 0 ? 'success' : 'warning',
                'message' => __('推送完成：总共 %{1} 个，成功 %{2} 个，失败 %{3} 个', [$total, $successCount, $failCount])
            ];

            return $this->jsonResponse([
                'success' => true,
                'total' => $total,
                'success_count' => $successCount,
                'fail_count' => $failCount,
                'results' => $results,
                'logs' => $logs,
                'message' => __('推送完成：成功 %{1}，失败 %{2}', [$successCount, $failCount])
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('推送失败：%{1}', $e->getMessage()),
                'logs' => [[
                    'timestamp' => date('H:i:s'),
                    'type' => 'error',
                    'message' => __('错误：%{1}', $e->getMessage())
                ]]
            ]);
        }
    }

    /**
     * JSON响应
     * 
     * @param array $data
     * @return string
     */
    private function jsonResponse(array $data): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}

