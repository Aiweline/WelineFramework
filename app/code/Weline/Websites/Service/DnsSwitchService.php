<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\DomainRegistrarAccount;

/**
 * DNS/CDN 切换统一服务
 *
 * 封装 DNS/CDN 切换的完整编排逻辑，所有入口（SSE 手动切换、定时任务、购买后自动）
 * 统一调用本服务，保证行为与标记一致。
 *
 * 职责：
 *  1. 从源（注册商）同步 DNS 记录到本地
 *  2. 在目标 DNS 服务商添加域名（Zone）并获取 NS
 *  3. 在注册商处修改 NS 指向目标
 *  4. 可选：等待 NS 生效（公网或注册商侧校验）
 *  5. 将本地记录推送到目标 DNS 服务商
 *  6. 同步/校验 DNS 记录，更新 Domain 与 DomainPool
     *  7. 可选：通过目标 DNS 适配器 {@see DomainRegistrarInterface::verifyCdnConfiguration} 校验 CDN/边缘
 *
 * @see executeDnsSwitch 主入口，通过 options 控制是否等待 NS、进度回调、CDN 等
 */
class DnsSwitchService
{
    private DomainRegistrarResolverService $registrarResolver;
    private DomainResolveService $resolveService;
    private DnsProviderDetector $dnsDetector;

    public function __construct(
        DomainRegistrarResolverService $registrarResolver,
        DomainResolveService $resolveService,
        DnsProviderDetector $dnsDetector
    ) {
        $this->registrarResolver = $registrarResolver;
        $this->resolveService = $resolveService;
        $this->dnsDetector = $dnsDetector;
    }

    /**
     * 执行 DNS/CDN 切换（统一入口，适用于 SSE 手动、定时任务、购买后自动）
     *
     * @param Domain $domain 域名模型（必须已持久化）
     * @param DomainRegistrarAccount $targetAccount 目标 DNS 服务商账户
     * @param callable|null $onStep 进度回调 fn(string $event, array $data): void，用于 SSE 等
     * @param array $options 可选：wait_for_ns (bool), wait_max_seconds (int), wait_interval_seconds (int),
     *                       is_alive (callable), cdn_account_id (int), cdn_account (DomainRegistrarAccount|null),
     *                       verify_cdn (bool), verify_cdn_wait_max_seconds (int), verify_cdn_wait_interval_seconds (int).
     *                       Step8（verify_cdn）：适配器 verifyCdnConfiguration；不支持则跳过。
     *                       after_sync_records (callable(Domain, array $dnsRecords): void)
     * @return array{success: bool, message: string, nameservers: string[], push_success: bool, push_added: int, push_failed: int, client_aborted?: bool}
     */
    public function executeDnsSwitch(
        Domain $domain,
        DomainRegistrarAccount $targetAccount,
        ?callable $onStep = null,
        array $options = []
    ): array {
        $domainName = $domain->getDomain();
        $targetCode = (string) $targetAccount->getRegistrarCode();
        $targetCredentials = $targetAccount->getCredentials();
        $targetAccountId = (int) $targetAccount->getAccountId();
        $logCh = 'dns_cdn_switch';
        $waitForNs = (bool) ($options['wait_for_ns'] ?? false);
        $waitMaxSeconds = (int) ($options['wait_max_seconds'] ?? 30 * 60);
        $waitIntervalSeconds = (int) ($options['wait_interval_seconds'] ?? 5);
        $isAlive = $options['is_alive'] ?? null;
        $cdnAccount = $options['cdn_account'] ?? null;
        $verifyCdn = (bool) ($options['verify_cdn'] ?? false);
        $afterSyncRecords = $options['after_sync_records'] ?? null;

        $notify = static function (string $event, array $data) use ($onStep): void {
            if ($onStep !== null) {
                $onStep($event, $data);
            }
        };

        $targetAdapter = $this->registrarResolver->getAdapter($targetCode);
        if ($targetAdapter === null || !$targetAdapter->supportsDnsManagement()) {
            return $this->fail(__('目标账户 %{1} 不支持 DNS 管理', [$targetCode]));
        }

        // ── Step 1: 从源（注册商）同步 DNS 记录到本地 ──
        $notify('sync_records', ['domain' => $domainName, 'message' => __('从注册商同步 DNS 记录到本地')]);
        $registrarAccountId = (int) $domain->getAccountId();
        if ($registrarAccountId <= 0) {
            return $this->fail(__('域名 %{1} 未关联注册商账户，无法切换', [$domainName]));
        }

        $sourceAccount = $this->loadAccount($registrarAccountId);
        if ($sourceAccount === null) {
            return $this->fail(__('注册商账户 ID %{1} 不存在', [$registrarAccountId]));
        }

        $sourceAdapter = $this->registrarResolver->getAdapter($sourceAccount->getRegistrarCode());
        if ($sourceAdapter === null) {
            return $this->fail(__('注册商适配器 %{1} 不存在', [$sourceAccount->getRegistrarCode()]));
        }

        $syncResult = $this->syncRecordsFromSource($domain, $sourceAccount);
        $syncErr = (string) ($syncResult['error'] ?? '');
        $syncAdded = (int) ($syncResult['added'] ?? 0);
        $syncUpdated = (int) ($syncResult['updated'] ?? 0);
        if ($syncErr !== '') {
            $notify('sync_records_result', ['error' => $syncErr, 'message' => __('[步骤1] 同步记录（非致命）：%{1}', [$syncErr])]);
        } else {
            $notify('sync_records_done', ['domain' => $domainName, 'added' => $syncAdded, 'updated' => $syncUpdated, 'message' => __('DNS 记录同步完成，新增 %{1} 条，更新 %{2} 条', [(string) $syncAdded, (string) $syncUpdated])]);
        }
        w_log_info(__('[DnsSwitchService] %{1} Step1: 从 %{2} 同步记录完成，added=%{3}，updated=%{4}', [$domainName, $sourceAccount->getRegistrarCode(), (string) $syncAdded, (string) $syncUpdated]), [], $logCh);

        // ── Step 2: 在目标获取 NS（会自动 addZone） ──
        $notify('add_zone', ['domain' => $domainName, 'message' => __('获取目标 Nameserver（%{1}）', [$targetCode])]);
        $nsResult = $targetAdapter->getProviderNameservers($targetCredentials, $domainName);
        if (!($nsResult['success'] ?? false) || empty($nsResult['nameservers'])) {
            $nsMsg = $nsResult['message'] ?? __('无法获取目标 Nameserver');
            w_log_error(__('[DnsSwitchService] %{1} 获取目标 NS 失败：%{2}', [$domainName, $nsMsg]), [], $logCh);
            return $this->fail($nsMsg);
        }
        $targetNs = $nsResult['nameservers'];
        $targetNsNormalized = $this->normalizeNameservers($targetNs);
        $notify('add_zone_done', ['domain' => $domainName, 'nameservers' => $targetNs, 'message' => __('目标 NS：%{1}', [\implode(', ', $targetNs)])]);
        w_log_info(__('[DnsSwitchService] %{1} Step2: 目标 NS=%{2}', [$domainName, \implode(', ', $targetNs)]), [], $logCh);

        // ── Step 3: 在注册商处修改 NS ──
        $notify('switch_ns', ['domain' => $domainName, 'message' => __('在注册商 %{1} 切换 NS', [$sourceAccount->getRegistrarCode()])]);
        $updateResult = $sourceAdapter->updateNameservers($domainName, $targetNs, $sourceAccount->getCredentials());
        $updateSuccess = (bool) ($updateResult['success'] ?? false);
        $updateMsg = (string) ($updateResult['message'] ?? __('NS 切换失败'));
        if (!$updateSuccess) {
            $notify('switch_ns_error', ['message' => $updateMsg, 'raw' => $updateResult]);
            w_log_error(__('[DnsSwitchService] %{1} NS 切换失败：%{2}', [$domainName, $updateMsg]), [], $logCh);
            return $this->fail($updateMsg);
        }
        $notify('switch_ns_done', ['domain' => $domainName, 'message' => __('NS 切换接口返回成功（%{1}），正在校验注册商侧是否已生效…', [$updateMsg])]);
        w_log_info(__('[DnsSwitchService] %{1} Step3: NS API 成功，开始注册商侧轮询校验', [$domainName]), [], $logCh);

        // 注册商接口可能返回成功但 NS 未实际变更（Gname 等）；此前仅提示仍继续会导致「显示切换成功、解析未切回」
        $registrarVerify = $this->pollRegistrarNsMatchesTarget(
            $sourceAdapter,
            $domainName,
            $sourceAccount->getCredentials(),
            $targetNsNormalized,
            6,
            5
        );
        if ($registrarVerify['supported'] === false) {
            $notify('registrar_ns_check', ['message' => __('注册商 getDomainDetail 不支持，无法二次校验 NS，请自行在注册商确认 NS 已为目标值')]);
        } elseif ($registrarVerify['error'] !== '') {
            $notify('registrar_ns_check', ['message' => __('注册商 NS 查询异常（已重试）：%{1}', [$registrarVerify['error']]), 'error' => $registrarVerify['error']]);
            w_log_error(__('[DnsSwitchService] %{1} 注册商 NS 校验异常：%{2}', [$domainName, $registrarVerify['error']]), [], $logCh);
            return $this->fail(__('NS 切换后无法从注册商读取当前 NS，请检查 API 权限或稍后重试：%{1}', [$registrarVerify['error']]));
        } elseif ($registrarVerify['match'] === false) {
            $curStr = $registrarVerify['normalized'] === [] ? (string) __('(空)') : \implode(', ', $registrarVerify['normalized']);
            $wantStr = \implode(', ', $targetNs);
            $failMsg = (string) __(
                'NS 修改接口返回成功，但注册商侧多次查询后 NS 仍未变为目标值（当前：%{1}，目标：%{2}）。Gname 等平台可能需人工在控制台确认；在注册商处真正改 NS 之前，勿认为已切换成功。',
                [$curStr, $wantStr]
            );
            $notify('switch_ns_verify_fail', ['message' => $failMsg, 'current_ns' => $registrarVerify['normalized'], 'target_ns' => $targetNs]);
            w_log_error(__('[DnsSwitchService] %{1} NS 注册商校验失败：current=%{2} target=%{3}', [$domainName, $curStr, $wantStr]), [], $logCh);
            return $this->fail($failMsg);
        }
        $notify('registrar_ns_updated', [
            'message' => __(
                '注册商处登记的 NS 已是目标值（注册局侧已更新或同步中）。公网 dig/nslookup 仍显示旧 NS（如 share-dns）很常见，不代表失败。'
            ),
        ]);

        $publicNsPropagationPending = false;

        // ── Step 3b: 可选等待 NS 生效（公网或注册商侧） ──
        if ($waitForNs) {
            $notify('wait_ns_start', ['message' => __('[步骤2] 开始等待 NS 生效，每 %{1} 秒检测，最多 %{2} 分钟', [(string) $waitIntervalSeconds, (string) (\round($waitMaxSeconds / 60))])]);
            $waitResult = $this->waitForNameservers(
                $domainName,
                $targetNsNormalized,
                $sourceAdapter,
                $sourceAccount->getCredentials(),
                $waitMaxSeconds,
                $waitIntervalSeconds,
                $isAlive,
                $notify
            );
            if ($waitResult['aborted'] ?? false) {
                return \array_merge($this->fail(__('已由用户断开')), ['client_aborted' => true]);
            }
            if (!($waitResult['verified'] ?? false)) {
                return $this->fail(__('等待 NS 生效超时（%{1} 分钟），请稍后在「管理 DNS」中检查并手动搬迁记录', [(string) (\round($waitMaxSeconds / 60))]));
            }
            $publicNsPropagationPending = (($waitResult['verified_by'] ?? '') === 'registrar');
        }

        // ── Step 4: 推送 DNS 记录到目标 ──
        $notify('push_records', ['domain' => $domainName, 'message' => __('推送 DNS 记录到 %{1}', [$targetCode])]);
        $recordsToPush = $options['records_to_push'] ?? null;
        $pushResult = $this->resolveService->pushRecordsToProvider($domain, $targetAccount, $recordsToPush);
        $pushSuccess = $pushResult['success'] ?? false;
        $pushAdded = $pushResult['added'] ?? 0;
        $pushFailed = $pushResult['failed'] ?? 0;
        $notify('push_records_done', [
            'domain' => $domainName,
            'push_success' => $pushSuccess,
            'added' => $pushAdded,
            'failed' => $pushFailed,
            'message' => __('推送完成：成功 %{1}，失败 %{2}', [(string) $pushAdded, (string) $pushFailed]),
            'errors' => $pushResult['errors'] ?? [],
        ]);
        w_log_info(__('[DnsSwitchService] %{1} Step4: push success=%{2}, added=%{3}, failed=%{4}', [
            $domainName, $pushSuccess ? 'true' : 'false', (string) $pushAdded, (string) $pushFailed,
        ]), [], $logCh);

        // ── Step 5: 同步/校验 DNS 记录 ──
        $notify('sync_verify', ['domain' => $domainName, 'message' => __('同步并校验 DNS 记录')]);
        $sync = $this->resolveService->syncDnsRecords($domain);
        $syncError = (string) ($sync['error'] ?? '');
        if ($syncError !== '') {
            w_log_error(__('[DnsSwitchService] %{1} 同步/校验记录失败：%{2}', [$domainName, $syncError]), [], $logCh);
            return $this->fail(__('同步/校验记录失败：%{1}', [$syncError]));
        }
        $dnsDetails = $this->resolveService->getDnsDetails($domain);
        $dnsRecords = \is_array($dnsDetails['records'] ?? null) ? $dnsDetails['records'] : [];
        $notify('sync_verify_done', [
            'domain' => $domainName,
            'record_count' => \count($dnsRecords),
            'message' => __(
                '已从目标 DNS 控制台同步记录，共 %{1} 条（核对的是 Cloudflare 等面板里的记录，不是「公网解析是否已走新 NS」）。',
                [(string) \count($dnsRecords)]
            ),
        ]);
        if ($afterSyncRecords !== null && $dnsRecords !== []) {
            $afterSyncRecords($domain, $dnsRecords);
        }

        // ── Step 6: 更新 Domain 标记（含 CDN） ──
        $domain->setNameservers($targetNs);
        $domain->setDnsProvider($targetCode);
        $domain->setDnsAccountId($targetAccountId);
        $domain->setDnsSwitchPending(0);
        $domain->setDnsMigrationPending($pushSuccess ? 0 : 1);
        if ($cdnAccount !== null && $cdnAccount->getAccountId()) {
            $domain->setCdnProvider((string) $cdnAccount->getRegistrarCode());
            $domain->setCdnAccountId((int) $cdnAccount->getAccountId());
        } elseif ($this->dnsDetector->isCdnProvider($targetCode)) {
            $domain->setCdnProvider($targetCode);
            $domain->setCdnAccountId($targetAccountId);
        }
        $domain->forceCheck(false)->save();
        w_log_info(__('[DnsSwitchService] %{1} Step6: 域名标记已更新', [$domainName]), [], $logCh);

        // ── Step 7: 更新 DomainPool 状态 ──
        $cdnProvider = $cdnAccount !== null ? (string) $cdnAccount->getRegistrarCode() : ($this->dnsDetector->isCdnProvider($targetCode) ? $targetCode : '');
        $this->updateDomainPoolStatus($domainName, $targetCode, $cdnProvider);

        // ── Step 8: 可选 CDN/边缘校验（由目标 DNS 适配器 API 实现，如 Cloudflare 代理状态） ──
        if ($verifyCdn) {
            $notify('verify_cdn', ['domain' => $domainName, 'message' => __('步骤 5/5：校验 CDN/边缘配置…')]);
            $cdnWaitMax = (int) ($options['verify_cdn_wait_max_seconds'] ?? 5 * 60);
            $cdnWaitInterval = (int) ($options['verify_cdn_wait_interval_seconds'] ?? 15);
            $cdnOk = true;
            $probe = $targetAdapter->verifyCdnConfiguration($domainName, $targetCredentials);
            if (!($probe['supported'] ?? false)) {
                $notify('verify_cdn_attempt', [
                    'domain' => $domainName,
                    'attempt' => 0,
                    'message' => __('当前 DNS 供应商未提供 CDN 接口校验，已跳过'),
                ]);
            } else {
                $cdnOk = (bool) ($probe['ok'] ?? false);
                $cdnElapsed = 0;
                $attempt = 0;
                while (!$cdnOk && $cdnElapsed < $cdnWaitMax) {
                    if ($isAlive !== null && !$isAlive()) {
                        break;
                    }
                    ++$attempt;
                    $msg = (string) ($probe['message'] ?? '');
                    $notify('verify_cdn_attempt', [
                        'domain' => $domainName,
                        'attempt' => $attempt,
                        'message' => $msg !== '' ? $msg : __('第 %{1} 次校验 CDN 配置…', [(string) $attempt]),
                    ]);
                    $notify('verify_cdn_retry', [
                        'domain' => $domainName,
                        'elapsed' => $cdnElapsed,
                        'message' => __('%{1} 秒后重试（已等待 %{2} 秒）', [(string) $cdnWaitInterval, (string) $cdnElapsed]),
                    ]);
                    $this->sleepWithCdnVerifyHeartbeat($cdnWaitInterval, $isAlive, $notify, $domainName);
                    $cdnElapsed += $cdnWaitInterval;
                    if ($isAlive !== null && !$isAlive()) {
                        break;
                    }
                    $probe = $targetAdapter->verifyCdnConfiguration($domainName, $targetCredentials);
                    $cdnOk = (bool) ($probe['ok'] ?? false);
                }
                if ($cdnOk && $attempt === 0 && ($probe['message'] ?? '') !== '') {
                    $notify('verify_cdn_attempt', [
                        'domain' => $domainName,
                        'attempt' => 1,
                        'message' => (string) $probe['message'],
                    ]);
                }
            }
            $notify('verify_cdn_done', ['domain' => $domainName, 'ok' => $cdnOk]);
        }

        $notify('complete', [
            'domain' => $domainName,
            'message' => __('DNS/CDN 切换流程已结束'),
            'public_ns_propagation_pending' => $publicNsPropagationPending,
        ]);
        w_log_info(__('[DnsSwitchService] %{1} Step7: DomainPool 已更新，流程完成', [$domainName]), [], $logCh);

        return [
            'success' => true,
            'message' => __('DNS 切换成功：%{1} → %{2}', [$domainName, $targetCode]),
            'nameservers' => $targetNs,
            'push_success' => $pushSuccess,
            'push_added' => $pushAdded,
            'push_failed' => $pushFailed,
        ];
    }

    /**
     * 等待 NS 生效：公网解析或注册商侧已更新即通过
     *
     * @return array{verified: bool, aborted: bool, verified_by?: string}
     */
    private function waitForNameservers(
        string $domainName,
        array $targetNsNormalized,
        object $sourceAdapter,
        array $sourceCredentials,
        int $maxWaitSeconds,
        int $intervalSeconds,
        ?callable $isAlive,
        callable $notify
    ): array {
        $elapsed = 0;
        while ($elapsed < $maxWaitSeconds) {
            \sleep($intervalSeconds);
            if ($isAlive !== null && !$isAlive()) {
                return ['verified' => false, 'aborted' => true, 'verified_by' => ''];
            }
            $elapsed += $intervalSeconds;
            $liveNs = $this->resolveService->getLiveNameservers($domainName);
            $liveNormalized = $this->normalizeNameservers($liveNs);
            $registrarCheckNow = $this->getRegistrarNameserversWithDetail($sourceAdapter, $domainName, $sourceCredentials);
            $regNormNow = $registrarCheckNow['normalized'] ?? [];
            if ($registrarCheckNow['supported'] && $registrarCheckNow['error'] === '') {
                $regStr = $regNormNow === [] ? __('(空)') : \implode(', ', $regNormNow);
                $notify('wait_ns_registrar_detail', ['elapsed' => $elapsed, 'registrar_ns' => $regStr, 'message' => __('  注册商侧 NS：%{1}', [$regStr])]);
            } elseif ($registrarCheckNow['error'] !== '') {
                $notify('wait_ns_registrar_detail', ['elapsed' => $elapsed, 'error' => $registrarCheckNow['error'], 'message' => __('  注册商侧查询异常：%{1}', [$registrarCheckNow['error']])]);
            }
            $notify('wait_ns_progress', [
                'elapsed' => $elapsed,
                'live' => $liveNormalized,
                'target' => $targetNsNormalized,
            ]);
            if ($liveNormalized === $targetNsNormalized) {
                $notify('wait_ns_verified', ['by' => 'public']);
                return ['verified' => true, 'aborted' => false, 'verified_by' => 'public'];
            }
            if ($registrarCheckNow['supported'] && $registrarCheckNow['error'] === '' && $regNormNow === $targetNsNormalized) {
                if ($liveNormalized !== $targetNsNormalized) {
                    $pub = $liveNormalized === [] ? __('(暂无或仍为旧 NS)') : \implode(', ', $liveNormalized);
                    $notify('wait_ns_public_stale', [
                        'message' => __(
                            '【公网尚未跟上】递归 DNS 仍显示「%{1}」，注册商处已是 Cloudflare NS。后续搬迁/同步会照常进行；外网访问与证书需等公网 NS 变为 *.ns.cloudflare.com（常见数分钟～48 小时）。',
                            [$pub]
                        ),
                    ]);
                }
                $notify('wait_ns_verified', ['by' => 'registrar']);
                return ['verified' => true, 'aborted' => false, 'verified_by' => 'registrar'];
            }
        }
        return ['verified' => false, 'aborted' => false, 'verified_by' => ''];
    }

    private function normalizeNameservers(array $nameservers): array
    {
        $out = [];
        foreach ($nameservers as $ns) {
            $n = \strtolower(\trim((string) $ns));
            if ($n !== '') {
                $out[] = \rtrim($n, '.');
            }
        }
        $out = \array_values(\array_unique($out));
        \sort($out);
        return $out;
    }

    /**
     * 轮询注册商侧 NS 是否与目标一致（应对接口乐观成功、控制台「等待生效」等）
     *
     * @return array{supported: bool, match: bool|null, normalized: array<string>, error: string}
     */
    private function pollRegistrarNsMatchesTarget(
        object $sourceAdapter,
        string $domainName,
        array $credentials,
        array $targetNsNormalized,
        int $attempts,
        int $sleepSeconds
    ): array {
        $probe = $this->getRegistrarNameserversWithDetail($sourceAdapter, $domainName, $credentials);
        if ($probe['supported'] === false) {
            return ['supported' => false, 'match' => null, 'normalized' => [], 'error' => ''];
        }
        $lastNorm = [];
        $lastErr = '';
        for ($i = 0; $i < $attempts; $i++) {
            if ($i > 0 && $sleepSeconds > 0) {
                \sleep($sleepSeconds);
            }
            $check = $this->getRegistrarNameserversWithDetail($sourceAdapter, $domainName, $credentials);
            if (($check['error'] ?? '') !== '') {
                $lastErr = (string) $check['error'];
                continue;
            }
            $lastErr = '';
            $lastNorm = $check['normalized'];
            if ($lastNorm === $targetNsNormalized) {
                return ['supported' => true, 'match' => true, 'normalized' => $lastNorm, 'error' => ''];
            }
        }
        if ($lastErr !== '') {
            return ['supported' => true, 'match' => null, 'normalized' => $lastNorm, 'error' => $lastErr];
        }

        return ['supported' => true, 'match' => false, 'normalized' => $lastNorm, 'error' => ''];
    }

    private function getRegistrarNameservers(object $sourceAdapter, string $domainName, array $credentials): ?array
    {
        $r = $this->getRegistrarNameserversWithDetail($sourceAdapter, $domainName, $credentials);
        return $r['supported'] && $r['error'] === '' ? $r['raw'] : null;
    }

    /**
     * 获取注册商当前 NS，带详细结果便于日志
     *
     * @return array{supported: bool, raw: array<string>|null, normalized: array<string>, error: string}
     */
    private function getRegistrarNameserversWithDetail(object $sourceAdapter, string $domainName, array $credentials): array
    {
        if (!\method_exists($sourceAdapter, 'getDomainDetail')) {
            return ['supported' => false, 'raw' => null, 'normalized' => [], 'error' => __('适配器无 getDomainDetail 方法')];
        }
        try {
            $detail = $sourceAdapter->getDomainDetail($domainName, $credentials);
            $ns = $detail['nameservers'] ?? null;
            $raw = \is_array($ns) ? $ns : [];
            $normalized = $this->normalizeNameservers($raw);
            return ['supported' => true, 'raw' => $raw, 'normalized' => $normalized, 'error' => ''];
        } catch (\Throwable $e) {
            return ['supported' => true, 'raw' => null, 'normalized' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * 分段 sleep 并推送心跳，避免 SSE 长时间无包被网关/浏览器断开。
     */
    private function sleepWithCdnVerifyHeartbeat(int $totalSeconds, ?callable $isAlive, callable $notify, string $domainName): void
    {
        if ($totalSeconds <= 0) {
            return;
        }
        $chunk = 5;
        $left = $totalSeconds;
        while ($left > 0) {
            $step = \min($chunk, $left);
            \sleep($step);
            $left -= $step;
            if ($isAlive !== null && !$isAlive()) {
                return;
            }
            if ($left > 0) {
                $notify('verify_cdn_heartbeat', [
                    'domain' => $domainName,
                    'message' => __('CDN 校验等待中（保持连接）…'),
                ]);
            }
        }
    }

    /**
     * 标记域名需要 DNS 切换（购买后 / 手动设定目标但不立即执行）
     *
     * 仅写标记，不执行实际切换。由 DnsCdnAutoSwitch 定时任务消费。
     */
    public function markPendingSwitch(Domain $domain, int $targetDnsAccountId, string $targetDnsProvider): void
    {
        $domain->setDnsAccountId($targetDnsAccountId);
        $domain->setDnsProvider($targetDnsProvider);
        $domain->setDnsSwitchPending(1);
        $domain->forceCheck(false)->save();

        w_log_info(__('[DnsSwitchService] 域名 %{1} 已标记 dns_switch_pending=1, target=%{2}, account_id=%{3}', [
            $domain->getDomain(), $targetDnsProvider, (string) $targetDnsAccountId,
        ]), [], 'dns_cdn_switch');
    }

    /**
     * 标记 DomainPool 为 switching（切换前调用）
     */
    public function markPoolSwitching(string $rootDomain, string $targetCode): void
    {
        $poolModel = ObjectManager::getInstance(DomainPool::class);
        $detector = ObjectManager::getInstance(DnsProviderDetector::class);
        $isCdn = $detector->isCdnProvider($targetCode);

        $pools = $poolModel->clearQuery()
            ->where(DomainPool::schema_fields_ROOT_DOMAIN, \strtolower($rootDomain))
            ->select()
            ->fetch()
            ->getItems();

        foreach ($pools as $pool) {
            $pool->setDnsStatus(DomainPool::INFRA_STATUS_SWITCHING);
            if ($isCdn) {
                $pool->setCdnStatus(DomainPool::INFRA_STATUS_SWITCHING);
            }
            $pool->save();
        }
    }

    /**
     * 从源（注册商）同步 DNS 记录到本地 DB
     *
     * 与 DomainResolveService::syncDnsRecords 不同：本方法显式指定账户，
     * 避免在 dns_account_id 已指向目标（尚未 addZone）时使用错误账户。
     */
    public function syncRecordsFromSource(Domain $domain, DomainRegistrarAccount $sourceAccount): array
    {
        $adapter = $this->registrarResolver->getAdapter($sourceAccount->getRegistrarCode());
        if ($adapter === null || !$adapter->supportsDnsManagement()) {
            return ['synced' => 0, 'added' => 0, 'updated' => 0, 'deleted' => 0, 'error' => __('源账户 %{1} 不支持 DNS 管理', [$sourceAccount->getRegistrarCode()])];
        }

        try {
            $remoteRecords = $adapter->getDnsRecords($domain->getDomain(), $sourceAccount->getCredentials());
        } catch (\Throwable $e) {
            w_log_warning(__('[DnsSwitchService] syncRecordsFromSource %{1} 失败（非致命）：%{2}', [$domain->getDomain(), $e->getMessage()]), [], 'dns_cdn_switch');
            return ['synced' => 0, 'added' => 0, 'updated' => 0, 'deleted' => 0, 'error' => $e->getMessage()];
        }

        $records = [];
        foreach ($remoteRecords as $r) {
            $records[] = [
                'type' => $r['type'] ?? 'A',
                'host' => $r['host'] ?? '@',
                'value' => $r['value'] ?? '',
                'ttl' => $r['ttl'] ?? 600,
                'priority' => $r['priority'] ?? 0,
                'remote_record_id' => $r['record_id'] ?? '',
            ];
        }

        $dnsRecordModel = ObjectManager::getInstance(\Weline\Websites\Model\DomainDnsRecord::class);
        $result = $dnsRecordModel->syncRecords($domain->getDomainId(), $records);
        $result['error'] = '';
        return $result;
    }

    private function updateDomainPoolStatus(string $rootDomain, string $dnsProvider, string $cdnProvider): void
    {
        $poolModel = ObjectManager::getInstance(DomainPool::class);
        $pools = $poolModel->clearQuery()
            ->where(DomainPool::schema_fields_ROOT_DOMAIN, \strtolower($rootDomain))
            ->select()
            ->fetch()
            ->getItems();

        foreach ($pools as $pool) {
            $pool->setDnsProvider($dnsProvider);
            $pool->setDnsStatus(DomainPool::INFRA_STATUS_READY);
            if ($cdnProvider !== '') {
                $pool->setCdnStatus(DomainPool::INFRA_STATUS_READY);
            }
            $pool->save();
        }
    }

    private function loadAccount(int $accountId): ?DomainRegistrarAccount
    {
        if ($accountId <= 0) {
            return null;
        }
        $account = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
        $account->load($accountId);
        return $account->getAccountId() ? $account : null;
    }

    /**
     * @return array{success: false, message: string, nameservers: string[], push_success: false, push_added: 0, push_failed: 0}
     */
    private function fail(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
            'nameservers' => [],
            'push_success' => false,
            'push_added' => 0,
            'push_failed' => 0,
        ];
    }
}
