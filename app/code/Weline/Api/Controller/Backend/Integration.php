<?php 

declare(strict_types=1);

namespace Weline\Api\Controller\Backend;

use Weline\Api\Model\ApiUser;
use Weline\Api\Model\ApiUserToken;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_Api::integration', 'API闆嗘垚', 'mdi-api', 'API闆嗘垚', 'Weline_Backend::system_service')]
class Integration extends BackendController
{
    #[Acl('Weline_Api::integration_index', '鏌ョ湅API闆嗘垚闈㈡澘', 'mdi-api', '鏌ョ湅API闆嗘垚闈㈡澘')]
    public function index(): string
    {
        try {
            $stats = $this->collectStats();
            $recentUsers = $this->getRecentUsers();
            $sandboxKey = Env::getInstance()->getConfig('sandbox_key') ?? '';
            $links = [
                'user_list' => $this->_url->getBackendUrl('*/api/backend/user'),
                'user_add' => $this->_url->getBackendUrl('*/api/backend/user/add'),
                'api_docs' => $this->_url->getUrl('dev/docs/api'),
                'api_docs_sandbox' => $sandboxKey
                    ? $this->_url->getUrl('dev/docs/api?sandbox=' . $sandboxKey)
                    : $this->_url->getUrl('dev/docs/api'),
            ];

            $this->assign('stats', $stats);
            $this->assign('recent_users', $recentUsers);
            $this->assign('links', $links);
            $this->assign('sandbox_key', $sandboxKey);
        } catch (\Throwable $e) {
            Message::error(__('鍔犺浇API闆嗘垚闈㈡澘澶辫触锛?{1}', [$e->getMessage()]));
            $this->assign('stats', []);
            $this->assign('recent_users', []);
            $this->assign('links', []);
            $this->assign('sandbox_key', '');
        }

        return $this->fetch();
    }

    private function collectStats(): array
    {
        /** @var ApiUser $apiUser */
        $apiUser = ObjectManager::getInstance(ApiUser::class, [], false);
        /** @var ApiUserToken $tokenModel */
        $tokenModel = ObjectManager::getInstance(ApiUserToken::class, [], false);

        $totalUsers = (int)$apiUser->reset()->count();
        $enabledUsers = (int)$apiUser->reset()
            ->where(ApiUser::fields_is_enabled, 1)
            ->count();
        $sandboxUsers = (int)$apiUser->reset()
            ->where(ApiUser::fields_is_sandbox, 1)
            ->count();
        $activeTokens = (int)$tokenModel->reset()
            ->where(ApiUserToken::fields_type, ApiUserToken::TYPE_ACCESS_TOKEN)
            ->where(ApiUserToken::fields_token_expire_time, time(), '>')
            ->count();

        return [
            'total_users' => $totalUsers,
            'enabled_users' => $enabledUsers,
            'sandbox_users' => $sandboxUsers,
            'active_tokens' => $activeTokens,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getRecentUsers(): array
    {
        /** @var ApiUser $apiUser */
        $apiUser = ObjectManager::getInstance(ApiUser::class, [], false);
        $items = $apiUser->reset()
            ->order(ApiUser::fields_ID, 'DESC')
            ->pagination(1, 5)
            ->select()
            ->fetch()
            ->getItems() ?? [];

        return array_map(static function ($item) {
            $data = is_object($item) ? $item->getData() : (array)$item;
            return [
                'user_id' => $data[ApiUser::fields_ID] ?? null,
                'username' => $data[ApiUser::fields_username] ?? '',
                'email' => $data[ApiUser::fields_email] ?? '',
                'is_enabled' => (int)($data[ApiUser::fields_is_enabled] ?? 0),
                'is_sandbox' => (int)($data[ApiUser::fields_is_sandbox] ?? 0),
                'created_at' => $data['created_at'] ?? '',
            ];
        }, $items);
    }
}



