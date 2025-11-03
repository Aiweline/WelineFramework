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
use Weline\Cdn\Service\AdapterResolver;
use Weline\Cdn\Service\CachePurger;
use Weline\Cdn\Service\RuleManager;
use Weline\Cdn\Service\AccountManager;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Attribute\Acl as AclAttribute;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;

/**
 * CDN域名管理后台控制器
 * 
 * @package Weline_Cdn
 */
#[AclAttribute('Weline_Cdn::cdn_domain_manager', 'CDN域名管理', 'mdi-web', 'CDN域名管理', '')]
class Domain extends BackendController
{
    /**
     * 获取域名模型
     */
    private function getDomainModel(): DomainModel
    {
        return ObjectManager::getInstance(DomainModel::class);
    }

    /**
     * 获取适配器解析器
     */
    private function getAdapterResolver(): AdapterResolver
    {
        return ObjectManager::getInstance(AdapterResolver::class);
    }

    /**
     * 获取规则管理服务
     */
    private function getRuleManager(): RuleManager
    {
        return ObjectManager::getInstance(RuleManager::class);
    }

    /**
     * 获取缓存清理服务
     */
    private function getCachePurger(): CachePurger
    {
        return ObjectManager::getInstance(CachePurger::class);
    }

    /**
     * 获取账户管理服务
     */
    private function getAccountManager(): AccountManager
    {
        return ObjectManager::getInstance(AccountManager::class);
    }

    /**
     * 域名列表页面
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_domain_list', '查看域名列表', 'mdi-view-list', '查看CDN域名列表')]
    public function index(): string
    {
        try {
            $page = (int)$this->request->getGet('page', 1);
            $pageSize = 20;
            $search = trim($this->request->getGet('search', ''));
            $adapter = trim($this->request->getGet('adapter', ''));
            $enabled = $this->request->getGet('enabled', '');

            $query = $this->getDomainModel()->reset()->select();

            // 搜索过滤
            if (!empty($search)) {
                $query->where(DomainModel::fields_DOMAIN_NAME, 'like', "%{$search}%");
            }

            // 适配器过滤
            if (!empty($adapter)) {
                $query->where(DomainModel::fields_ADAPTER, $adapter);
            }

            // 启用状态过滤
            if ($enabled !== '') {
                $query->where(DomainModel::fields_ENABLED, (int)$enabled);
            }

            // 统计
            $total = $query->count();

            // 分页查询
            $domains = $query
                ->limit($pageSize, ($page - 1) * $pageSize)
                ->order(DomainModel::fields_CREATED_AT, 'DESC')
                ->fetch()
                ->getItems();

            // 获取所有适配器
            $adapters = $this->getAdapterResolver()->getAllAdapters();

            // 计算分页信息
            $totalPages = ceil($total / $pageSize);

            $this->assign('domains', $domains);
            $this->assign('total', $total);
            $this->assign('page', $page);
            $this->assign('pageSize', $pageSize);
            $this->assign('totalPages', $totalPages);
            $this->assign('search', $search);
            $this->assign('adapter', $adapter);
            $this->assign('enabled', $enabled);
            $this->assign('adapters', $adapters);

            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载域名列表失败：%{1}', $e->getMessage()));
            $this->assign('domains', []);
            $this->assign('total', 0);
            $this->assign('page', 1);
            $this->assign('pageSize', 20);
            $this->assign('totalPages', 0);
            $this->assign('search', '');
            $this->assign('adapter', '');
            $this->assign('enabled', '');
            $this->assign('adapters', []);
            return $this->fetch();
        }
    }

    /**
     * 创建/编辑域名表单
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_domain_form', '域名表单', 'mdi-form', '创建/编辑CDN域名表单')]
    public function form(): string
    {
        $id = (int)$this->request->getGet('id');

            if ($id) {
                $domain = $this->getDomainModel()->reset()->load($id);
                
                if (!$domain->getData(DomainModel::fields_DOMAIN_ID)) {
                    Message::error(__('域名不存在'));
                    return $this->redirect('*/backend/domain/index');
                }
            
            $this->assign('domain', $domain);
        } else {
            $this->assign('domain', $this->getDomainModel()->reset());
        }

        // 获取所有适配器
        $adapters = $this->getAdapterResolver()->getAllAdapters();
        $this->assign('adapters', $adapters);

        // 获取所有账户（用于选择）
        // TODO: 需要根据适配器筛选账户
        
        // 获取网站列表（从Weline_Websites模块）
        // TODO: 需要获取网站列表供选择

        return $this->fetch();
    }

    /**
     * 保存域名
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_domain_save', '保存域名', 'mdi-content-save', '保存CDN域名')]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        $id = (int)$this->request->getPost('id');
        $data = $this->request->getPost();

        try {
            $domain = $this->getDomainModel()->reset();
            
            if ($id) {
                $domain->load($id);
                
                if (!$domain->getData(DomainModel::fields_DOMAIN_ID)) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => __('域名不存在')
                    ]);
                }
            }

            // 验证必填字段
            if (empty($data['site_id'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('网站不能为空')
                ]);
            }

            if (empty($data['adapter'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('适配器不能为空')
                ]);
            }

            if (empty($data['domain_name'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('域名名称不能为空')
                ]);
            }

            if (empty($data['zone_id'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('Zone ID不能为空')
                ]);
            }

            // 验证适配器是否存在
            $adapters = $this->getAdapterResolver()->getAllAdapters();
            if (!isset($adapters[$data['adapter']])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('无效的适配器')
                ]);
            }

            // 如果是新域名，检查是否已存在
            if (!$id) {
                $existing = $this->getDomainModel()->reset()
                    ->where(DomainModel::fields_SITE_ID, $data['site_id'])
                    ->find()
                    ->fetch();
                
                if ($existing->getData(DomainModel::fields_DOMAIN_ID)) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => __('该网站已配置CDN域名')
                    ]);
                }
            }

            // 设置数据
            $domain->setData(DomainModel::fields_SITE_ID, $data['site_id']);
            $domain->setData(DomainModel::fields_ADAPTER, $data['adapter']);
            $domain->setData(DomainModel::fields_DOMAIN_NAME, $data['domain_name']);
            $domain->setData(DomainModel::fields_ZONE_ID, $data['zone_id']);
            $domain->setData(DomainModel::fields_ACCOUNT_ID, $data['account_id'] ?? null);
            $domain->setData(DomainModel::fields_INHERIT_DEFAULT, isset($data['inherit_default']) ? (int)$data['inherit_default'] : 1);
            $domain->setData(DomainModel::fields_WARMUP_INTERVAL_SECONDS, (int)($data['warmup_interval_seconds'] ?? 300));
            $domain->setData(DomainModel::fields_ENABLED, isset($data['enabled']) ? (int)$data['enabled'] : 1);

            // 处理自定义凭据
            if (isset($data['credentials']) && !empty($data['credentials'])) {
                $credentials = is_array($data['credentials']) 
                    ? $data['credentials'] 
                    : json_decode($data['credentials'], true);
                if (is_array($credentials)) {
                    $domain->setCredentialsArray($credentials);
                }
            }

            // 处理规则覆盖
            if (isset($data['rules_override']) && !empty($data['rules_override'])) {
                $rules = is_array($data['rules_override']) 
                    ? $data['rules_override'] 
                    : json_decode($data['rules_override'], true);
                if (is_array($rules)) {
                    $domain->setRulesOverrideArray($rules);
                }
            }

            $domain->save();

            // 如果是新域名，自动推送默认规则
            if (!$id) {
                try {
                    $this->getRuleManager()->pushRules($domain);
                } catch (\Exception $e) {
                    // 规则推送失败不影响域名保存，记录日志即可
                    error_log("推送默认规则失败: " . $e->getMessage());
                }
            }

            Message::success(__('域名保存成功'));

            return $this->jsonResponse([
                'success' => true,
                'message' => __('域名保存成功'),
                'redirect' => $this->request->getUrlBuilder()->getBackendUrl('*/backend/domain/index')
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('保存失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 删除域名
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_domain_delete', '删除域名', 'mdi-delete', '删除CDN域名')]
    public function delete(): string
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

            $domain->delete();

            Message::success(__('域名删除成功'));

            return $this->jsonResponse([
                'success' => true,
                'message' => __('域名删除成功')
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('删除失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 启用/禁用域名
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_domain_toggle_enable', '启用/禁用域名', 'mdi-toggle-switch', '启用/禁用CDN域名')]
    public function toggleEnable(): string
    {
        $id = (int)$this->request->getPost('id');
        $enabled = (int)$this->request->getPost('enabled', 1);

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

            $domain->setData(DomainModel::fields_ENABLED, $enabled ? 1 : 0);
            $domain->save();

            Message::success($enabled ? __('域名已启用') : __('域名已禁用'));

            return $this->jsonResponse([
                'success' => true,
                'message' => $enabled ? __('域名已启用') : __('域名已禁用')
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('操作失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 导入规则
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_domain_import_rules', '导入规则', 'mdi-download', '从CDN导入规则')]
    public function importRules(): string
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
     * 推送规则
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_domain_push_rules', '推送规则', 'mdi-upload', '推送规则到CDN')]
    public function pushRules(): string
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

    /**
     * 清理缓存
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_domain_clear_cache', '清理缓存', 'mdi-delete-sweep', '清理CDN缓存')]
    public function clearCache(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        $id = (int)$this->request->getPost('id');
        $mode = trim($this->request->getPost('mode', 'everything'));
        $data = $this->request->getPost('data', []);

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

            $result = $this->getCachePurger()->purge($id, $mode, $data);

            if ($result['success']) {
                Message::success(__('缓存清理成功'));
                return $this->jsonResponse([
                    'success' => true,
                    'message' => $result['message'] ?? __('缓存清理成功')
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $result['message'] ?? __('缓存清理失败')
                ]);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('清理失败：%{1}', $e->getMessage())
            ]);
        }
    }
}

