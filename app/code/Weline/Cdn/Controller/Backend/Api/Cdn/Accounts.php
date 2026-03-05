<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Controller\Backend\Api\Cdn;

use Weline\Cdn\Model\Account;
use Weline\Cdn\Service\ProviderManager;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl as AclAttribute;

/**
 * CDN 账户 API 控制器
 * 为 w:select:account 标签提供数据
 */
#[AclAttribute('Weline_Cdn::cdn_account_api', 'CDN账户API', 'mdi-api', 'CDN账户API接口', 'Weline_Cdn::cdn_manager')]
class Accounts extends BackendController
{
    private ProviderManager $providerManager;

    public function __construct(
        ProviderManager $providerManager
    ) {
        $this->providerManager = $providerManager;
    }

    /**
     * 获取账户列表
     */
    #[AclAttribute('Weline_Cdn::cdn_account_api_index', '获取CDN账户列表', 'mdi-view-list', '获取CDN账户列表API')]
    public function index(): string
    {
        try {
            $limit = (int)($this->request->getGet('limit') ?? 50);
            $search = trim((string)($this->request->getGet('search') ?? ''));
            $adapter = trim((string)($this->request->getGet('adapter') ?? ''));

            $accounts = $this->providerManager->getAccounts($adapter);
            if ($search !== '') {
                $accounts = array_filter($accounts, static function (array $item) use ($search) {
                    $haystack = strtolower(($item['name'] ?? '') . ' ' . ($item['adapter'] ?? ''));
                    return str_contains($haystack, strtolower($search));
                });
            }
            $accounts = array_values($accounts);
            if ($limit > 0) {
                $accounts = array_slice($accounts, 0, $limit);
            }

            $data = [];
            foreach ($accounts as $account) {
                $data[] = [
                    'account_id' => (int)($account[Account::schema_fields_ACCOUNT_ID] ?? 0),
                    'name' => $account[Account::schema_fields_NAME] ?? '',
                    'adapter' => $account[Account::schema_fields_ADAPTER] ?? '',
                    'is_default' => (int)($account[Account::schema_fields_IS_DEFAULT] ?? 0),
                    'is_active' => ($account[Account::schema_fields_STATUS] ?? '') === Account::STATUS_ACTIVE,
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
