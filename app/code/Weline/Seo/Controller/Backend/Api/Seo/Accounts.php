<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Controller\Backend\Api\Seo;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl as AclAttribute;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoAccount;

/**
 * SEO 账户 API 控制器
 * 为 w:seo:account:select 标签提供数据
 */
#[AclAttribute('Weline_Seo::seo_account', 'SEO账户API', 'mdi-api', 'SEO账户API接口', '')]
class Accounts extends BackendController
{
    /**
     * 获取 SEO 账户列表
     *
     * @return string JSON响应
     */
    #[AclAttribute('Weline_Seo::seo_account_index', '获取SEO账户列表', 'mdi-view-list', '获取SEO账户列表API')]
    public function index(): string
    {
        try {
            $limit = (int)($this->request->getGet('limit') ?? 50);
            $search = trim((string)($this->request->getGet('search') ?? ''));
            
            /** @var SeoAccount $accountModel */
            $accountModel = ObjectManager::getInstance(SeoAccount::class);
            
            $query = $accountModel->reset()
                ->where(SeoAccount::fields_IS_ACTIVE, 1)
                ->order(SeoAccount::fields_NAME, 'ASC');
            
            if ($search !== '') {
                $query->where(SeoAccount::fields_NAME, "%{$search}%", 'LIKE');
            }
            
            if ($limit > 0) {
                $query->limit($limit);
            }
            
            $accounts = $query->select()->fetchArray();
            
            // 格式化输出
            $data = [];
            foreach ($accounts as $account) {
                $data[] = [
                    'account_id' => (int)$account[SeoAccount::fields_ACCOUNT_ID],
                    'name' => $account[SeoAccount::fields_NAME] ?? '',
                    'provider' => $account[SeoAccount::fields_PROVIDER] ?? '',
                    'scope' => $account[SeoAccount::fields_SCOPE] ?? '',
                    'module' => $account[SeoAccount::fields_MODULE] ?? '',
                    'is_active' => (int)($account[SeoAccount::fields_IS_ACTIVE] ?? 0),
                ];
            }
            
            return $this->fetchJson([
                'success' => true,
                'data' => $data,
                'total' => count($data),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [],
            ]);
        }
    }
}
