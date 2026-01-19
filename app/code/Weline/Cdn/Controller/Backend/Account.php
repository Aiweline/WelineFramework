<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Controller\Backend;

use Weline\Cdn\Model\Account as AccountModel;
use Weline\Cdn\Service\AccountManager;
use Weline\Cdn\Service\AdapterResolver;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl as AclAttribute;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;

/**
 * CDN账户管理后台控制器
 * 
 * @package Weline_Cdn
 */
#[AclAttribute('Weline_Cdn::cdn_account_manager', 'CDN账户管理', 'mdi-account-circle', 'CDN账户管理', '')]
class Account extends BackendController
{
    /**
     * 获取账户模型
     */
    private function getAccountModel(): AccountModel
    {
        return ObjectManager::getInstance(AccountModel::class);
    }

    /**
     * 获取账户管理服务
     */
    private function getAccountManager(): AccountManager
    {
        return ObjectManager::getInstance(AccountManager::class);
    }

    /**
     * 获取适配器解析器
     */
    private function getAdapterResolver(): AdapterResolver
    {
        return ObjectManager::getInstance(AdapterResolver::class);
    }

    /**
     * 账户列表页面
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_account_list', '查看账户列表', 'mdi-view-list', '查看CDN账户列表')]
    public function index(): string
    {
        try {
            $page = (int)$this->request->getGet('page', 1);
            $pageSize = 20;
            $search = trim($this->request->getGet('search', ''));
            $adapter = trim($this->request->getGet('adapter', ''));

            $query = $this->getAccountModel()->reset()->select();

            // 搜索过滤
            if (!empty($search)) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            }

            // 适配器过滤
            if (!empty($adapter)) {
                $query->where(AccountModel::fields_ADAPTER, $adapter);
            }

            // 排序
            $query->order(AccountModel::fields_CREATED_AT, 'DESC');

            // 分页查询（Model会自动计算总数）
            $result = $query->pagination($page, $pageSize)->fetch();
            $accounts = $result->getItems();
            
            // 从分页信息中获取总数和总页数
            $pagination = $query->getPaginationData();
            $total = $pagination['totalSize'] ?? 0;
            $totalPages = $pagination['lastPage'] ?? 0;

            // 获取所有适配器
            $adapters = $this->getAdapterResolver()->getAllAdapters();

            $this->assign('accounts', $accounts);
            $this->assign('total', $total);
            $this->assign('page', $page);
            $this->assign('pageSize', $pageSize);
            $this->assign('totalPages', $totalPages);
            $this->assign('search', $search);
            $this->assign('adapter', $adapter);
            $this->assign('adapters', $adapters);

            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载账户列表失败：%{1}', $e->getMessage()));
            $this->assign('accounts', []);
            $this->assign('total', 0);
            $this->assign('page', 1);
            $this->assign('pageSize', 20);
            $this->assign('totalPages', 0);
            $this->assign('search', '');
            $this->assign('adapter', '');
            $this->assign('adapters', []);
            return $this->fetch();
        }
    }

    /**
     * 创建/编辑账户表单
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_account_form', '账户表单', 'mdi-form', '创建/编辑CDN账户表单')]
    public function form(): string
    {
        $id = (int)$this->request->getGet('id');

        if ($id) {
            $account = $this->getAccountModel()->reset()->load($id);
            
            if (!$account->getData(AccountModel::fields_ACCOUNT_ID)) {
                Message::error(__('账户不存在'));
                return $this->redirect('*/backend/account/index');
            }
            
            $this->assign('account', $account);
        } else {
            $this->assign('account', $this->getAccountModel()->reset());
        }

        // 获取所有适配器
        $adapters = $this->getAdapterResolver()->getAllAdapters();
        $this->assign('adapters', $adapters);

        return $this->fetch();
    }

    /**
     * 保存账户
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_account_save', '保存账户', 'mdi-content-save', '保存CDN账户')]
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
            $account = $this->getAccountModel()->reset();
            
            if ($id) {
                $account->load($id);
                
                if (!$account->getData(AccountModel::fields_ACCOUNT_ID)) {
                    $errorMsg = __('账户不存在');
                    if ($this->request->isIframe()) {
                        return $this->redirect('/component/offcanvas/error', [
                            'msg' => $errorMsg,
                            'reload' => 0
                        ]);
                    }
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => $errorMsg
                    ]);
                }
            }

            // 验证必填字段
            if (empty($data['name'])) {
                $errorMsg = __('账户名称不能为空');
                if ($this->request->isIframe()) {
                    return $this->redirect('/component/offcanvas/error', [
                        'msg' => $errorMsg,
                        'reload' => 0
                    ]);
                }
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $errorMsg
                ]);
            }

            if (empty($data['adapter'])) {
                $errorMsg = __('适配器不能为空');
                if ($this->request->isIframe()) {
                    return $this->redirect('/component/offcanvas/error', [
                        'msg' => $errorMsg,
                        'reload' => 0
                    ]);
                }
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $errorMsg
                ]);
            }

            // 验证适配器是否存在
            $adapters = $this->getAdapterResolver()->getAllAdapters();
            if (!isset($adapters[$data['adapter']])) {
                $errorMsg = __('无效的适配器');
                if ($this->request->isIframe()) {
                    return $this->redirect('/component/offcanvas/error', [
                        'msg' => $errorMsg,
                        'reload' => 0
                    ]);
                }
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $errorMsg
                ]);
            }

            // 设置数据
            $account->setData(AccountModel::fields_NAME, $data['name']);
            $account->setData(AccountModel::fields_ADAPTER, $data['adapter']);
            $account->setData(AccountModel::fields_DESCRIPTION, $data['description'] ?? '');
            $account->setData(AccountModel::fields_STATUS, $data['status'] ?? AccountModel::STATUS_ACTIVE);

            // 处理凭据
            $credentials = [];
            if (isset($data['credentials'])) {
                if (is_array($data['credentials'])) {
                    $credentials = $data['credentials'];
                } else {
                    $credentials = json_decode($data['credentials'], true) ?: [];
                }
            }
            $account->setCredentialsArray($credentials);

            // 处理默认账户标记
            $shouldSetDefault = isset($data['is_default']) && $data['is_default'] == 1;
            
            if (!$shouldSetDefault) {
                $account->setData(AccountModel::fields_IS_DEFAULT, 0);
            }

            // 先保存获取ID（如果是新账户）
            $account->save();
            $accountId = $account->getData(AccountModel::fields_ACCOUNT_ID);

            // 如果是新账户或者是标记为默认且当前不是默认，则设置为默认
            if ($shouldSetDefault && (!$id || $account->getData(AccountModel::fields_IS_DEFAULT) != 1)) {
                if ($accountId) {
                    $this->getAccountManager()->setDefaultAccount($accountId);
                }
            }

            Message::success(__('账户保存成功'));

            // 如果是 iframe 模式（OffCanvas），重定向到成功页面
            if ($this->request->isIframe()) {
                return $this->redirect('/component/offcanvas/success', [
                    'msg' => __('账户保存成功'),
                    'reload' => 1
                ]);
            }

            return $this->jsonResponse([
                'success' => true,
                'message' => __('账户保存成功'),
                'redirect' => $this->request->getUrlBuilder()->getBackendUrl('*/backend/account/index')
            ]);
        } catch (\Exception $e) {
            // 如果是 iframe 模式（OffCanvas），重定向到错误页面
            if ($this->request->isIframe()) {
                return $this->redirect('/component/offcanvas/error', [
                    'msg' => __('保存失败：%{1}', $e->getMessage()),
                    'reload' => 0
                ]);
            }

            return $this->jsonResponse([
                'success' => false,
                'message' => __('保存失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 删除账户
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_account_delete', '删除账户', 'mdi-delete', '删除CDN账户')]
    public function postDelete(): string
    {
        // 支持 JSON 和表单数据
        $params = $this->request->getParams();
        $id = (int)($params['id'] ?? 0);

        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('账户ID不能为空')
            ]);
        }

        try {
            $account = $this->getAccountModel()->reset()->load($id);
            
            if (!$account->getData(AccountModel::fields_ACCOUNT_ID)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('账户不存在')
                ]);
            }

            // 检查是否有域名使用此账户
            $domains = $this->getAccountManager()->getAccountDomains($id);
            if (!empty($domains)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('该账户正在被使用，无法删除')
                ]);
            }

            $account->delete()->fetch();

            Message::success(__('账户删除成功'));

            return $this->jsonResponse([
                'success' => true,
                'message' => __('账户删除成功')
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('删除失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 设置默认账户
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_account_set_default', '设置默认账户', 'mdi-star', '设置CDN默认账户')]
    public function setDefault(): string
    {
        $id = (int)$this->request->getPost('id');

        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('账户ID不能为空')
            ]);
        }

        try {
            $account = $this->getAccountModel()->reset()->load($id);
            
            if (!$account->getData(AccountModel::fields_ACCOUNT_ID)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('账户不存在')
                ]);
            }

            $this->getAccountManager()->setDefaultAccount($id);

            Message::success(__('默认账户设置成功'));

            return $this->jsonResponse([
                'success' => true,
                'message' => __('默认账户设置成功')
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('设置失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 查看账户关联的域名列表
     * 
     * @return string
     */
    #[AclAttribute('Weline_Cdn::cdn_account_domains', '查看关联域名', 'mdi-web', '查看账户关联的域名列表')]
    public function domains(): string
    {
        $id = (int)$this->request->getGet('id');

        if (!$id) {
            Message::error(__('账户ID不能为空'));
            return $this->redirect('*/backend/account/index');
        }

        try {
            $account = $this->getAccountModel()->reset()->load($id);
            
            if (!$account->getData(AccountModel::fields_ACCOUNT_ID)) {
                Message::error(__('账户不存在'));
                return $this->redirect('*/backend/account/index');
            }

            $domains = $this->getAccountManager()->getAccountDomains($id);

            $this->assign('account', $account);
            $this->assign('domains', $domains);

            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载域名列表失败：%{1}', $e->getMessage()));
            return $this->redirect('*/backend/account/index');
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

