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
use Weline\Seo\Service\PlatformVisual;

/**
 * SEO 账户 API 控制器
 * 为 w:seo:account:select 标签提供数据
 */
#[AclAttribute('Weline_Seo::seo_account', 'SEO账户API', 'mdi-api', 'SEO账户API接口', 'Weline_Backend::seo_group')]
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
            /** @var PlatformVisual $platformVisual */
            $platformVisual = ObjectManager::getInstance(PlatformVisual::class);
            
            $query = $accountModel->reset()
                ->where(SeoAccount::schema_fields_IS_ACTIVE, 1)
                ->order(SeoAccount::schema_fields_NAME, 'ASC');
            
            if ($search !== '') {
                $query->where(SeoAccount::schema_fields_NAME, "%{$search}%", 'LIKE');
            }
            
            if ($limit > 0) {
                $query->limit($limit);
            }
            
            $accounts = $query->select()->fetchArray();
            
            // 格式化输出
            $data = [];
            foreach ($accounts as $account) {
                $platform = (string)($account[SeoAccount::schema_fields_PLATFORM] ?? $account[SeoAccount::schema_fields_PROVIDER] ?? '');
                $provider = (string)($account[SeoAccount::schema_fields_PROVIDER] ?? '');
                $data[] = [
                    'account_id' => (int)$account[SeoAccount::schema_fields_ACCOUNT_ID],
                    'name' => $account[SeoAccount::schema_fields_NAME] ?? '',
                    'platform' => $platform,
                    'provider' => $provider,
                    'scope' => $account[SeoAccount::schema_fields_SCOPE] ?? '',
                    'module' => $account[SeoAccount::schema_fields_MODULE] ?? '',
                    'is_active' => (int)($account[SeoAccount::schema_fields_IS_ACTIVE] ?? 0),
                    'platform_icon_svg' => $platformVisual->renderIcon($platform ?: $provider, $platform ?: $provider, null, 26),
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
