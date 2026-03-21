<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainRegistrarAccount;

/**
 * NS 探测结果与「后台配置的 DNS 管理账户」不一致时的告警；默认开启自愈（可 env 关闭），白名单空=全部根域。
 *
 * @see DomainNsCheck
 */
class DomainNsMismatchNotifier
{
    private const CACHE_ALERT_PREFIX = 'weline_websites_ns_mismatch_alert:';

    private const CACHE_SELF_HEAL_PREFIX = 'weline_websites_ns_self_heal:';

    public function __construct(
        private readonly DnsProviderDetector $dnsDetector,
        private readonly CacheManager $cacheManager
    ) {
    }

    /**
     * @param array<int, string> $liveNameservers 已规范为小写的 NS 主机名列表
     * @return array{alerted: bool, self_heal_queued: bool}
     */
    public function handleAfterLiveProbe(Domain $domain, array $liveNameservers, string $detectedProvider): array
    {
        $out = ['alerted' => false, 'self_heal_queued' => false];
        $cfg = self::getNsCheckConfig();
        $alertOn = (bool) ($cfg['mismatch_alert_enabled'] ?? true);
        $healOn = (bool) ($cfg['self_heal_enabled'] ?? true);
        if (!$alertOn && !$healOn) {
            return $out;
        }

        $dnsAccountId = (int) $domain->getDnsAccountId();
        if ($dnsAccountId <= 0) {
            return $out;
        }
        if ((int) $domain->getDnsSwitchPending() === 1 || (int) $domain->getDnsSwitchDeferred() === 1) {
            return $out;
        }
        if ($liveNameservers === []) {
            return $out;
        }

        $detected = \strtolower(\trim($detectedProvider));
        if ($detected === '' || $detected === 'unknown') {
            return $out;
        }

        $account = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
        $account->load($dnsAccountId);
        if (!$account->getAccountId()) {
            return $out;
        }
        $expectedCode = (string) ($account->getRegistrarCode() ?? '');
        if ($expectedCode === '') {
            return $out;
        }

        if ($this->dnsDetector->liveProviderMatchesDnsAccountRegistrar($detected, $expectedCode)) {
            return $out;
        }

        $domainName = $domain->getDomain();
        $pool = $this->cacheManager->pool('default');
        $alertCooldown = (int) ($cfg['mismatch_alert_cooldown_seconds'] ?? 3600);
        if ($alertCooldown < 60) {
            $alertCooldown = 60;
        }

        $alertKey = self::CACHE_ALERT_PREFIX . \strtolower($domainName);
        if ($alertOn && $pool->get($alertKey) === null) {
            $pool->set($alertKey, 1, $alertCooldown);
            $out['alerted'] = true;
            $liveStr = \implode(', ', $liveNameservers);
            w_log_warning(__(
                '[NS 与配置 DNS 账户不一致] 域名 %{1}：公网识别为 %{2}，配置的 DNS 账户服务商为 %{3}（account_id=%{4}）。公网 NS：%{5}。请运营核对注册商 NS 或等待切换传播；此检查只做告警，不会在这里直接改 NS。',
                [$domainName, $detected, $expectedCode, (string) $dnsAccountId, $liveStr]
            ), [], 'domain_ns_check');
        }

        if (!$healOn) {
            return $out;
        }

        $whitelist = self::normalizeDomainWhitelist($cfg['self_heal_domain_whitelist'] ?? []);
        if ($whitelist !== [] && !\in_array(\strtolower($domainName), $whitelist, true)) {
            return $out;
        }

        $healCooldown = (int) ($cfg['self_heal_cooldown_seconds'] ?? 86400);
        if ($healCooldown < 300) {
            $healCooldown = 300;
        }
        $healKey = self::CACHE_SELF_HEAL_PREFIX . \strtolower($domainName);
        if ($pool->get($healKey) !== null) {
            return $out;
        }

        $domain->setDnsSwitchPending(1);
        $domain->setDnsSwitchDeferred(0);
        $domain->forceCheck(false)->save();
        $pool->set($healKey, 1, $healCooldown);
        $out['self_heal_queued'] = true;
        w_log_info(__(
            '[NS 自愈策略] 已为域名 %{1} 写入 dns_switch_pending=1（冷却内单次排队），将由 DnsCdnAutoSwitch 执行切换。',
            [$domainName]
        ), [], 'domain_ns_check');

        return $out;
    }

    /**
     * @return array{
     *   mismatch_alert_enabled?: bool,
     *   mismatch_alert_cooldown_seconds?: int,
     *   self_heal_enabled?: bool,
     *   self_heal_cooldown_seconds?: int,
     *   self_heal_domain_whitelist?: array<int, string>
     * }
     */
    public static function getNsCheckConfig(): array
    {
        $cfg = Env::getInstance()->getConfig();

        return (array) (($cfg['websites'] ?? [])['ns_check'] ?? []);
    }

    /**
     * @param array<int, mixed> $list
     * @return list<string>
     */
    private static function normalizeDomainWhitelist(array $list): array
    {
        $out = [];
        foreach ($list as $item) {
            $d = \strtolower(\trim((string) $item));
            if ($d !== '') {
                $out[] = $d;
            }
        }

        return \array_values(\array_unique($out));
    }
}
