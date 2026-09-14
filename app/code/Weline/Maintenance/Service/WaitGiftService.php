<?php

declare(strict_types=1);

namespace Weline\Maintenance\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Marketing\Api\Coupon\RandomCouponCampaignProviderInterface;
use Weline\Marketing\Service\MarketingCheckoutCouponSession;

/**
 * Orchestrates wait-gift issue / heartbeat / abandon / redeem.
 */
final class WaitGiftService
{
    public const COOKIE_WAIT = 'weline_mw_wait';
    public const COOKIE_GATE = 'weline_mw_gate';
    public const COOKIE_BROWSER = 'weline_mw_browser';

    public function __construct(
        private readonly UpgradeWaveService $waves = new UpgradeWaveService(),
        private readonly WaitLedger $ledger = new WaitLedger(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function issue(array $context = []): array
    {
        if (!Env::system('maintenance') && !(\defined('WLS_MAINTENANCE_WORKER') && WLS_MAINTENANCE_WORKER)) {
            return $this->fail('maintenance_required', '维护未开启，无法签发等待凭证');
        }
        $wave = $this->waves->readWave();
        if ($wave === null || (string)($wave['status'] ?? '') === '') {
            return $this->fail('wave_missing', '升级波次未就绪');
        }
        if (!(bool)($wave['wait_gift_enabled'] ?? false)) {
            return $this->fail('gift_disabled', '本轮未开启维护礼金');
        }
        if (!$this->isTocAudience($context)) {
            return $this->fail('audience_tob', '维护礼金仅面向零售客户');
        }
        if (!$this->hasValidGate($context)) {
            return $this->fail('gate_required', '缺少维护门禁，无法签发');
        }

        $waveId = (string)($wave['wave_id'] ?? '');
        $browserKey = $this->resolveBrowserKey($context);
        $existing = $this->ledger->findActiveByCookieKey($browserKey, $waveId);
        if ($existing !== null) {
            $opaque = (string)($context['opaque_token'] ?? '');
            // Client may have lost opaque; re-issue only when we cannot prove possession.
            if ($opaque !== '' && \hash_equals((string)$existing['token_hash'], $this->hashToken($opaque))) {
                $this->ledger->touchHeartbeat((string)$existing['token_hash']);

                return $this->ok([
                    'token' => $opaque,
                    'wave_id' => $waveId,
                    'resumed' => true,
                    'system_version' => (string)($wave['system_version_to'] ?? ''),
                    'theme_version' => (string)($wave['theme_version_to'] ?? ''),
                    'min_wait_sec' => (int)($existing['min_wait_sec'] ?? UpgradeWaveService::MIN_WAIT_SECONDS_DEFAULT),
                ]);
            }
        }

        $opaque = $this->generateOpaque();
        $hash = $this->hashToken($opaque);
        $this->ledger->createWaiting($hash, $waveId, [
            'ip_hash' => $this->hashPii((string)($context['ip'] ?? '')),
            'ua_hash' => $this->hashPii((string)($context['user_agent'] ?? '')),
            'guest_token_hash' => $this->hashPii((string)($context['guest_token'] ?? '')),
            'customer_id' => (string)($context['customer_id'] ?? ''),
            'cookie_key' => $browserKey,
            'min_wait_sec' => (int)($wave['min_wait_sec'] ?? UpgradeWaveService::MIN_WAIT_SECONDS_DEFAULT),
        ]);

        return $this->ok([
            'token' => $opaque,
            'wave_id' => $waveId,
            'resumed' => false,
            'system_version' => (string)($wave['system_version_to'] ?? ''),
            'theme_version' => (string)($wave['theme_version_to'] ?? ''),
            'min_wait_sec' => (int)($wave['min_wait_sec'] ?? UpgradeWaveService::MIN_WAIT_SECONDS_DEFAULT),
            'browser_key' => $browserKey,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function heartbeat(string $opaque, array $context = []): array
    {
        $hash = $this->hashToken($opaque);
        $record = $this->ledger->findByTokenHash($hash);
        if ($record === null) {
            return $this->fail('token_not_found', '等待凭证无效');
        }
        if ((string)($record['status'] ?? '') !== WaitLedger::STATUS_WAITING) {
            return $this->fail('token_inactive', '等待凭证已失效');
        }
        $now = \time();
        $lastSeen = (int)($record['last_seen_at'] ?? 0);
        if ($lastSeen > 0 && ($now - $lastSeen) > UpgradeWaveService::HIDDEN_ABANDON_SECONDS) {
            $this->ledger->markAbandoned($hash);

            return $this->fail('abandoned', '离开过久，礼金资格已取消');
        }
        $this->ledger->touchHeartbeat($hash, $now);

        return $this->ok([
            'status' => WaitLedger::STATUS_WAITING,
            'last_seen_at' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function abandon(string $opaque): array
    {
        $hash = $this->hashToken($opaque);
        $this->ledger->markAbandoned($hash);

        return $this->ok(['status' => WaitLedger::STATUS_ABANDONED]);
    }

    /**
     * Redeem after maintenance is off; issues a one-time random coupon.
     *
     * @return array<string, mixed>
     */
    public function redeem(string $opaque, array $context = []): array
    {
        if (Env::system('maintenance') || (\defined('WLS_MAINTENANCE_WORKER') && WLS_MAINTENANCE_WORKER)) {
            return $this->fail('still_maintaining', '维护尚未结束');
        }

        $wave = $this->waves->markRecovered();
        if ($wave === null) {
            return $this->fail('wave_missing', '升级波次不存在');
        }
        if (!(bool)($wave['wait_gift_enabled'] ?? false)) {
            return $this->fail('gift_disabled', '本轮未开启维护礼金');
        }
        if (!$this->isTocAudience($context)) {
            return $this->fail('audience_tob', '维护礼金仅面向零售客户');
        }
        if (!$this->waves->isRedeemWindowOpen($wave)) {
            $hashEarly = $this->hashToken($opaque);
            $this->ledger->markExpired($hashEarly);

            return $this->fail('redeem_window_closed', '兑礼窗口已关闭（恢复后 10 分钟内有效）');
        }

        $hash = $this->hashToken($opaque);
        $record = $this->ledger->findByTokenHash($hash);
        if ($record === null) {
            return $this->fail('token_not_found', '等待凭证无效');
        }
        if ((string)($record['status'] ?? '') === WaitLedger::STATUS_REDEEMED) {
            $code = (string)($record['redeemed_coupon_code'] ?? '');

            return $this->ok([
                'coupon_code' => $code,
                'idempotent' => true,
                'wave_id' => (string)($wave['wave_id'] ?? ''),
            ]);
        }
        if ((string)($record['status'] ?? '') !== WaitLedger::STATUS_WAITING) {
            return $this->fail('token_inactive', '等待凭证已失效');
        }
        if ((string)($record['wave_id'] ?? '') !== (string)($wave['wave_id'] ?? '')) {
            return $this->fail('wave_mismatch', '凭证不属于本轮升级');
        }

        $now = \time();
        $issuedAt = (int)($record['issued_at'] ?? 0);
        $minWait = (int)($record['min_wait_sec'] ?? UpgradeWaveService::MIN_WAIT_SECONDS_DEFAULT);
        if ($issuedAt > 0 && ($now - $issuedAt) < $minWait) {
            return $this->fail('min_wait', '等待时间不足');
        }
        $lastSeen = (int)($record['last_seen_at'] ?? 0);
        if ($lastSeen > 0 && ($now - $lastSeen) > UpgradeWaveService::HIDDEN_ABANDON_SECONDS) {
            $this->ledger->markAbandoned($hash);

            return $this->fail('abandoned', '离开过久，礼金资格已取消');
        }

        // Version check: recovered site should match wave targets (framework always; theme best-effort).
        $systemNow = $this->waves->resolveFrameworkVersion();
        if ($systemNow !== (string)($wave['system_version_to'] ?? $systemNow)) {
            return $this->fail('version_mismatch', '系统版本与本轮升级不一致');
        }

        $ruleId = (int)($wave['marketing_rule_id'] ?? 0);
        if ($ruleId <= 0) {
            return $this->fail('campaign_missing', '礼金活动未配置');
        }

        $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(RandomCouponCampaignProviderInterface::class);
        if (!$provider instanceof RandomCouponCampaignProviderInterface) {
            return $this->fail('provider_missing', '折扣随机券能力不可用');
        }

        $issued = $provider->issueRandomCoupon($ruleId, [
            'source' => WaitGiftCampaignSyncService::SOURCE_TYPE,
            'source_module' => WaitGiftCampaignSyncService::SOURCE_MODULE,
            'source_type' => WaitGiftCampaignSyncService::SOURCE_TYPE,
            'source_id' => WaitGiftCampaignSyncService::SOURCE_ID,
            'source_key' => 'wait_gift',
            'wave_id' => (string)($wave['wave_id'] ?? ''),
            'token_hash' => $hash,
            'discount_type' => (string)($wave['discount_type'] ?? 'fixed_amount'),
            'discount_value' => (float)($wave['discount_value'] ?? 0),
        ]);
        $code = \strtoupper(\trim((string)($issued['coupon_code'] ?? '')));
        if ($code === '') {
            return $this->fail('coupon_issue_failed', '礼金券发放失败');
        }

        $this->ledger->markRedeemed($hash, $code);

        try {
            /** @var MarketingCheckoutCouponSession $couponSession */
            $couponSession = ObjectManager::getInstance(MarketingCheckoutCouponSession::class);
            $couponSession->applyCoupon($code);
        } catch (\Throwable) {
            // Session apply is best-effort; frontend will still call applyCoupon.
        }

        return $this->ok([
            'coupon_code' => $code,
            'idempotent' => false,
            'wave_id' => (string)($wave['wave_id'] ?? ''),
        ]);
    }

    public function generateOpaque(): string
    {
        return \rtrim(\strtr(\base64_encode(\random_bytes(32)), '+/', '-_'), '=');
    }

    public function hashToken(string $opaque): string
    {
        return \hash('sha256', $opaque);
    }

    public function mintGateToken(): string
    {
        return $this->generateOpaque();
    }

    /**
     * Redeem HTTP status: 200 ok, 503 still maintaining, otherwise 404 (invalid/expired token).
     *
     * @param array<string, mixed> $result
     */
    public static function redeemHttpStatus(array $result): int
    {
        if (!empty($result['success'])) {
            return 200;
        }
        if ((string)($result['error'] ?? '') === 'still_maintaining') {
            return 503;
        }

        return 404;
    }

    /**
     * Wait-gift is retail (ToC) only. Wholesale (tob) cookie / context is rejected.
     * Soft cookie probe — no hard dependency on Weline_B2B.
     *
     * @param array<string, mixed> $context
     */
    public function isTocAudience(array $context = []): bool
    {
        return $this->resolveSellingMode($context) !== 'tob';
    }

    /**
     * @param array<string, mixed> $context
     */
    public function resolveSellingMode(array $context = []): string
    {
        $fromContext = \strtolower(\trim((string)($context['selling_mode'] ?? $context['cart_type'] ?? '')));
        if ($fromContext === 'tob' || $fromContext === 'toc') {
            return $fromContext;
        }

        $cookies = $context['cookies'] ?? null;
        if (!\is_array($cookies)) {
            $cookies = $_COOKIE ?? [];
        }
        if (!\is_array($cookies)) {
            return 'toc';
        }

        $names = [];
        foreach (\array_keys($cookies) as $name) {
            $key = (string)$name;
            if ($key === 'weline_selling_mode' || \preg_match('/^weline_selling_mode_w\d+$/', $key) === 1) {
                $names[] = $key;
            }
        }
        // Prefer site-scoped cookies before the legacy unscoped name.
        \usort($names, static function (string $a, string $b): int {
            $score = static fn(string $n): int => \str_starts_with($n, 'weline_selling_mode_w') ? 0 : 1;

            return $score($a) <=> $score($b) ?: \strcmp($a, $b);
        });
        foreach ($names as $name) {
            $value = \strtolower(\trim((string)($cookies[$name] ?? '')));
            if ($value === 'tob' || $value === 'toc') {
                return $value;
            }
        }

        return 'toc';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function hasValidGate(array $context): bool
    {
        $gate = \trim((string)($context['gate'] ?? ''));

        return $gate !== '' && \strlen($gate) >= 16;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveBrowserKey(array $context): string
    {
        $key = \trim((string)($context['browser_key'] ?? ''));
        if ($key !== '') {
            return $key;
        }

        return 'bk_' . \substr(\hash('sha256', $this->generateOpaque()), 0, 24);
    }

    private function hashPii(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }

        return \hash('sha256', $value);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function ok(array $data): array
    {
        return \array_merge(['success' => true], $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function fail(string $code, string $message): array
    {
        return [
            'success' => false,
            'error' => $code,
            'message' => (string)\__($message),
        ];
    }
}
