<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl as AclAttribute;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoAccount;

/**
 * SEO 账户管理后台控制器
 */
#[AclAttribute('Weline_Seo::seo_account', 'SEO账户管理', 'mdi-account-key', 'SEO账户管理', '')]
class Account extends BackendController
{
    private function getAccountModel(): SeoAccount
    {
        return ObjectManager::getInstance(SeoAccount::class);
    }

    #[AclAttribute('Weline_Seo::seo_account_index', '查看SEO账户列表', 'mdi-view-list', '查看SEO账户列表')]
    public function index(): string
    {
        $scope = trim((string)$this->request->getGet('scope', ''));
        $module = trim((string)$this->request->getGet('module', ''));

        $query = $this->getAccountModel()->reset()->select();

        if ($scope !== '') {
            $query->where(SeoAccount::fields_SCOPE, $scope);
        }
        if ($module !== '') {
            $query->where(SeoAccount::fields_MODULE, $module);
        }

        $query->order(SeoAccount::fields_CREATED_AT, 'DESC');

        $accounts = $query->fetchArray();

        $this->assign('accounts', $accounts);
        $this->assign('scope', $scope);
        $this->assign('module', $module);

        return $this->fetch();
    }

    #[AclAttribute('Weline_Seo::seo_account_form', 'SEO账户表单', 'mdi-form-select', '创建/编辑SEO账户表单')]
    public function form(): string
    {
        $id = (int)$this->request->getGet('id');
        $scope = trim((string)$this->request->getGet('scope', ''));
        $module = trim((string)$this->request->getGet('module', ''));

        $account = $this->getAccountModel()->reset();
        if ($id) {
            $account->load($id);
            if (!$account->getId()) {
                Message::error(__('账户不存在'));
                return $this->redirect('seo/backend/account/index');
            }
        } else {
            if ($scope !== '') {
                $account->setData(SeoAccount::fields_SCOPE, $scope);
            }
            if ($module !== '') {
                $account->setData(SeoAccount::fields_MODULE, $module);
            }
        }

        $this->assign('account', $account);
        $this->assign('scope', $scope);
        $this->assign('module', $module);

        return $this->fetch();
    }

    #[AclAttribute('Weline_Seo::seo_account_save', '保存SEO账户', 'mdi-content-save', '保存SEO账户')]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法'),
            ]);
        }

        $id = (int)$this->request->getPost('id');
        $data = $this->request->getPost();

        try {
            $account = $this->getAccountModel()->reset();
            if ($id) {
                $account->load($id);
                if (!$account->getId()) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => __('账户不存在'),
                    ]);
                }
            }

            $name = trim((string)($data['name'] ?? ''));
            $provider = trim((string)($data['provider'] ?? ''));

            if ($name === '') {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('账户名称不能为空'),
                ]);
            }
            if ($provider === '') {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('供应商(provider)不能为空'),
                ]);
            }

            $scope = trim((string)($data['scope'] ?? ''));
            $module = trim((string)($data['module'] ?? ''));

            $account->setData(SeoAccount::fields_NAME, $name)
                ->setData(SeoAccount::fields_PROVIDER, $provider)
                ->setData(SeoAccount::fields_SCOPE, $scope)
                ->setData(SeoAccount::fields_MODULE, $module)
                ->setData(SeoAccount::fields_DESCRIPTION, (string)($data['description'] ?? ''))
                ->setData(SeoAccount::fields_IS_ACTIVE, (int)($data['is_active'] ?? SeoAccount::STATUS_ACTIVE))
                ->setData(SeoAccount::fields_ENABLE_CRON_PUSH_URLS, (int)($data['enable_cron_push_urls'] ?? 1))
                ->setData(SeoAccount::fields_ENABLE_CRON_SITEMAP, (int)($data['enable_cron_sitemap'] ?? 0));

            $configJson = (string)($data['config_json'] ?? '');
            if ($configJson !== '') {
                $decoded = json_decode($configJson, true);
                $config = is_array($decoded) ? $decoded : [];
                $account->setConfigArray($config);
            }

            $account->save();

            return $this->jsonResponse([
                'success' => true,
                'message' => __('账户保存成功'),
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('账户保存失败：%{1}', $e->getMessage()),
            ]);
        }
    }

    /**
     * JSON 响应工具
     */
    private function jsonResponse(array $data): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}

