<?php

declare(strict_types=1);

namespace Weline\Social\Extends\Module\Weline_Ai\Agent;

use Weline\Framework\Manager\ObjectManager;
use Weline\Social\Service\SocialWebsiteAccountService;

/**
 * 读取某个网站在融媒体里已经绑定的默认社媒账户。
 */
final class SocialScopePlatformCatalog
{
    /**
     * @return array{website_id:int,ok:bool,message:string,platform_codes:list<string>,accounts:list<array<string,mixed>>}
     */
    public function forWebsite(int $websiteId): array
    {
        if ($websiteId < 0) {
            return $this->emptyResult($websiteId, false, (string)__('缺少网站编号，不能猜测融媒体平台。'));
        }

        /** @var SocialWebsiteAccountService $accounts */
        $accounts = ObjectManager::getInstance(SocialWebsiteAccountService::class);
        $result = $accounts->getWebsiteDefaultAccounts($websiteId, false);
        if (empty($result['success'])) {
            return $this->emptyResult(
                $websiteId,
                false,
                (string)($result['message'] ?? __('读取该站融媒体账户失败。')),
            );
        }

        $items = [];
        $codes = [];
        foreach ((array)($result['accounts'] ?? []) as $account) {
            if (!\is_array($account)) {
                continue;
            }
            $code = trim((string)($account['platform_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $items[] = [
                'account_id' => (int)($account['account_id'] ?? 0),
                'platform_code' => $code,
                'account_name' => (string)($account['account_name'] ?? ''),
                'publish_enabled' => (int)($account['publish_enabled'] ?? 0),
                'widget_enabled' => (int)($account['widget_enabled'] ?? 0),
                'profile_url' => (string)($account['profile_url'] ?? ''),
                'status' => (string)($account['status'] ?? ''),
            ];
            if (!\in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        $message = $items === []
            ? (string)__('该站融媒体还没有绑定默认社媒账户。')
            : (string)__('已读取该站融媒体默认账户。');

        return [
            'website_id' => $websiteId,
            'ok' => true,
            'message' => $message,
            'platform_codes' => $codes,
            'accounts' => $items,
        ];
    }

    /**
     * @return array{website_id:int,ok:bool,message:string,platform_codes:list<string>,accounts:list<array<string,mixed>>}
     */
    private function emptyResult(int $websiteId, bool $ok, string $message): array
    {
        return [
            'website_id' => $websiteId,
            'ok' => $ok,
            'message' => $message,
            'platform_codes' => [],
            'accounts' => [],
        ];
    }
}
