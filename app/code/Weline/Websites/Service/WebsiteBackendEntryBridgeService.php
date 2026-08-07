<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Backend\Api\Auth\BackendInteractiveAuthInterface;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\Session;
use Weline\Websites\Model\Website;

/**
 * 主站已登录操作员直进已授权子站后台：短时一次性令牌桥接。
 * 保持按站会话隔离，不复用 _w0 cookie。
 */
final class WebsiteBackendEntryBridgeService
{
    private const CACHE_IDENTITY = 'website_backend_entry';
    private const TOKEN_TTL_SECONDS = 90;

    public function __construct(
        private readonly WebsiteAclGrantService $grantService,
        private readonly WebsiteEntryUrlService $entryUrlService,
        private readonly BackendInteractiveAuthInterface $backendAuth,
    ) {
    }

    public function assertCanIssueFromCurrentSession(int $targetWebsiteId, int $userId): void
    {
        $targetWebsiteId = max(0, $targetWebsiteId);
        if ($targetWebsiteId === Website::ID_DEFAULT) {
            throw new \InvalidArgumentException((string)__('默认站无需跨站直进'));
        }
        if ($userId <= 0) {
            throw new \RuntimeException((string)__('请先登录主站后台'));
        }
        if (!$this->grantService->isDefaultWebsite()) {
            throw new \RuntimeException((string)__('仅主站后台可发起子站直进'));
        }
        if (!$this->grantService->hasAnyGrant($targetWebsiteId)) {
            throw new \RuntimeException((string)__('该站尚未配置功能授权，无法直进后台'));
        }
        if (!$this->isPlatformOperator($userId)) {
            throw new \RuntimeException((string)__('当前账号不能从主站直进子站后台'));
        }
        $website = ObjectManager::getInstance(Website::class, [], false)->load($targetWebsiteId);
        if ((int)$website->getId() !== $targetWebsiteId) {
            throw new \InvalidArgumentException((string)__('网站不存在'));
        }
    }

    public function issueToken(int $targetWebsiteId, int $userId): string
    {
        $this->assertCanIssueFromCurrentSession($targetWebsiteId, $userId);
        $token = \bin2hex(\random_bytes(32));
        $ok = w_cache(self::CACHE_IDENTITY)->set($token, [
            'user_id' => $userId,
            'website_id' => $targetWebsiteId,
            'issued_at' => \time(),
        ], self::TOKEN_TTL_SECONDS);
        if (!$ok) {
            throw new \RuntimeException((string)__('无法签发直进令牌'));
        }

        return $token;
    }

    /**
     * @return array{frontend_url: string, backend_login_url: string, consume_url: string}
     */
    public function buildConsumeUrl(int $targetWebsiteId, string $token): array
    {
        $website = ObjectManager::getInstance(Website::class, [], false)->load($targetWebsiteId);
        $row = $website->getData();
        if (!\is_array($row)) {
            $row = [];
        }
        $row[Website::schema_fields_ID] = $targetWebsiteId;
        $entry = $this->entryUrlService->resolveForListingRow($row);
        $frontendUrl = $this->withCurrentRequestPort((string)($entry['frontend_url'] ?? ''));
        $loginUrl = $this->withCurrentRequestPort((string)($entry['backend_url'] ?? ''));
        if ($frontendUrl === '' || $loginUrl === '') {
            throw new \RuntimeException((string)__('该站尚未绑定可访问域名'));
        }

        $parts = \parse_url($loginUrl);
        if (!\is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \RuntimeException((string)__('子站后台入口无效'));
        }
        $origin = $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
        $path = (string)($parts['path'] ?? '');
        // loginUrl ends with /{backendKey}/admin/login — replace with consume route
        $consumePath = \preg_replace('#/admin/login/?$#', '/websites/admin/website/consume-backend-entry', $path) ?? '';
        if ($consumePath === '' || $consumePath === $path) {
            throw new \RuntimeException((string)__('无法构造子站直进消费地址'));
        }

        return [
            'frontend_url' => $frontendUrl,
            'backend_login_url' => $loginUrl,
            'consume_url' => $origin . $consumePath . '?token=' . \rawurlencode($token),
        ];
    }

    /**
     * Local WLS often stores website.url without :port while the live request has one.
     */
    private function withCurrentRequestPort(string $url): string
    {
        $url = \trim($url);
        if ($url === '') {
            return '';
        }
        $parts = \parse_url($url);
        if (!\is_array($parts) || empty($parts['host']) || isset($parts['port'])) {
            return $url;
        }
        $httpHost = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($httpHost === '' || !\preg_match('/:(\d+)\z/', $httpHost, $m)) {
            return $url;
        }
        $port = (int)$m[1];
        if ($port <= 0) {
            return $url;
        }
        $scheme = (string)($parts['scheme'] ?? 'http');
        $httpsOn = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $forwarded = \strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($httpsOn || $forwarded === 'https') {
            $scheme = 'https';
        }
        $rebuild = $scheme . '://' . $parts['host'] . ':' . $port;
        if (!empty($parts['path'])) {
            $rebuild .= $parts['path'];
        }
        if (isset($parts['query']) && $parts['query'] !== '') {
            $rebuild .= '?' . $parts['query'];
        }

        return $rebuild;
    }

    /**
     * Consume token on the target website host and install backend session.
     *
     * @return array{redirect_url: string, user_id: int, website_id: int}
     */
    public function consumeAndLogin(string $token, AuthenticatedSessionInterface $session, string $clientIp): array
    {
        $token = \trim($token);
        if ($token === '' || \preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            throw new \InvalidArgumentException((string)__('直进令牌无效'));
        }

        $cache = w_cache(self::CACHE_IDENTITY);
        $payload = $cache->get($token);
        $cache->delete($token);
        if (!\is_array($payload)) {
            throw new \RuntimeException((string)__('直进令牌已失效，请从主站重新进入'));
        }

        $userId = (int)($payload['user_id'] ?? 0);
        $websiteId = (int)($payload['website_id'] ?? -1);
        $currentWebsiteId = $this->grantService->currentWebsiteId();
        if ($userId <= 0 || $websiteId <= Website::ID_DEFAULT) {
            throw new \RuntimeException((string)__('直进令牌数据损坏'));
        }
        if ($websiteId !== $currentWebsiteId) {
            throw new \RuntimeException((string)__('直进令牌与当前站点不匹配'));
        }
        if (!$this->grantService->hasAnyGrant($websiteId)) {
            throw new \RuntimeException((string)__('该站授权已收回，无法进入后台'));
        }

        $account = $this->backendAuth->find($userId);
        if ($account === null) {
            throw new \RuntimeException((string)__('管理员不存在'));
        }

        $session->start('');
        $this->backendAuth->installSessionIdentity($session, $account);
        $hasRole = $account->getRoleId() > 0;
        $isSuperAdminById = $userId === 1;
        if (!$hasRole && !$isSuperAdminById) {
            $session->logout();
            throw new \RuntimeException((string)__('您的账户尚未分配角色，无法登录后台'));
        }
        $aclRoleId = $hasRole ? $account->getRoleId() : ($isSuperAdminById ? 1 : 0);
        $session->getSession()->set('backend_acl_role_id', $aclRoleId);
        $session->getSession()->set('backend_acl_is_enabled', $account->getIsEnabled() ? 1 : 0);
        $this->backendAuth->completeLogin($userId, (string)$session->getId(), $clientIp);
        $this->persistBackendSessionCookie($session);

        /** @var Url $url */
        $url = ObjectManager::getInstance(Url::class);
        $redirectUrl = $url->getBackendUrl('admin');
        if ($redirectUrl === '') {
            $redirectUrl = $url->getBackendUrl('weline_dashboard/backend/dashboard');
        }

        return [
            'redirect_url' => $redirectUrl,
            'user_id' => $userId,
            'website_id' => $websiteId,
        ];
    }

    private function isPlatformOperator(int $userId): bool
    {
        if ($userId === 1) {
            return true;
        }
        /** @var BackendUser $user */
        $user = ObjectManager::getInstance(BackendUser::class, [], false)->load($userId);
        if ((int)$user->getId() !== $userId) {
            return false;
        }

        return $user->getWebsiteId() === Website::ID_DEFAULT;
    }

    private function persistBackendSessionCookie(AuthenticatedSessionInterface $session): void
    {
        $rawSession = $session->getSession();
        $rawSession->save();
        if ($rawSession instanceof Session) {
            $rawSession->getStrategy()->writeClose();
        }
        Session::flushRequestSessions();
    }
}
