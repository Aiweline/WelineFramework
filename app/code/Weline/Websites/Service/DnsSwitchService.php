<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\DomainRegistrarAccount;

/**
 * DNS/CDN 切换统一服务
 *
 * **权威 NS 委派（注册局登记的 Nameserver）**：只能、且必须由**域名注册商**修改（本服务 Step3：
 * `sourceAdapter->updateNameservers`，凭证必须来自 **`Domain.account_id`**（注册商账户；勿用 `dns_account_id`）。
 * **目标 DNS 账户**（`targetAccount`＝`dns_account_id`，如 Cloudflare）**绝不**调用 `updateNameservers`：仅用于 Step2
 * `getProviderNameservers` / addZone、Step4 推送解析记录等；域名不在 CF 注册时，CF 只是托管方，无权改注册局委派。
 *
 * 封装 DNS/CDN 切换的完整编排逻辑。
 *
 * **入口约定（由新到旧）：**
 * - 业务侧（Cron / 后台批量切换）优先 {@see executeDnsSwitchWithStandardOptions}：等同 executeDnsSwitch +
 *   {@see buildStandardSwitchOptions}（env 公网 NS 等待 + 根域已绑 CDN 时 verify_cdn）。
 * - 底层唯一完整流水线 {@see executeDnsSwitch}（同步→改 NS→等待→推送→校验→写 Domain/Pool/cutover）。
 * - PageBuilder SSE 等在 {@see buildStandardSwitchOptions} 上 {@see array_merge} 自定义项（更长等待等）；`records_to_push` 仅作 Step1 后本地仍空时的兜底。
 * - **非完整切换**：部分旧接口仅调注册商 {@see DomainRegistrarInterface::updateNameservers} 并 save Domain，不推送记录、
 *   不写 dns_cutover_complete；需全量迁移请走上述入口（如 Admin {@see \Weline\Websites\Controller\Admin\Domain::postSwitchDnsAccount}）。
 *
 * 职责：
 *  1. 将**当前托管侧**记录同步到本地：优先 {@see Domain::getDnsAccountId}（≠目标时）再 {@see Domain::getAccountId}（注册商）
 *  2. 在目标 DNS 服务商添加域名（Zone）并获取 NS
 *  3. 在注册商处修改 NS 指向目标
 *  4. 可选：等待 NS 生效（公网或注册商侧校验）
 *  5. 将本地记录推送到目标 DNS 服务商
 *  6. 同步/校验 DNS 记录，更新 Domain 与 DomainPool
 *  7. 可选：通过目标 DNS 适配器 {@see DomainRegistrarInterface::verifyCdnConfiguration} 校验 CDN/边缘
 *
 * @see executeDnsSwitch 主入口，通过 options 控制是否等待 NS、进度回调、CDN 等
 *
 * 公网权威 NS：waitForNameservers 在「注册商 API 已为目标 NS」时可能提前通过（verified_by=registrar），
 * 但全球递归仍可能指向旧 NS（如 share-dns）。对 Cloudflare 等目标，Step6 以 validateAuthoritativeDnsMatchesProvider
 * 为准决定是否置 dns_cutover_complete=1，避免误判「已切换完成」。证书 DNS-01 的写入门闸另见
 * {@see DomainResolveService::validateAcmeDns01HostingViaAdapters}（仅用注册商 + DNS 托管适配器 API）。
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
     *                       ns_probe_cloudflare_doh (bool|null 默认读 env websites.dns_switch),
     *                       is_alive (callable), cdn_account_id (int), cdn_account (DomainRegistrarAccount|null),
     *                       verify_cdn (bool), verify_cdn_wait_max_seconds (int), verify_cdn_wait_interval_seconds (int).
     *                       Step8（verify_cdn）：适配器 verifyCdnConfiguration；不支持则跳过。
     *                       after_sync_records (callable(Domain, array $dnsRecords): void)
     *                       records_to_push（可选）：仅当 Step1 后 {@see DomainResolveService::getRecordsForPush} 仍为空时作兜底，避免页面前置快照覆盖流水线内刷新后的本地库。
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
        $probeDoh = $options['ns_probe_cloudflare_doh'] ?? null;
        if ($probeDoh === null) {
            $ds = Env::module_env('Weline_Websites', 'dns_switch') ?? [];
            $probeDoh = (bool) ($ds['ns_probe_use_cloudflare_doh'] ?? true);
        } else {
            $probeDoh = (bool) $probeDoh;
        }

        $notify = static function (string $event, array $data) use ($onStep): void {
            if ($onStep !== null) {
                $onStep($event, $data);
            }
        };

        $targetAdapter = $this->registrarResolver->getAdapter($targetCode);
        if ($targetAdapter === null || !$targetAdapter->supportsDnsManagement()) {
            return $this->fail(__('目标账户 %{1} 不支持 DNS 管理', [$targetCode]));
        }
        // 目标适配器永不用于 updateNameservers；委派仅 sourceAdapter（注册商）.

        // ── Step 1: 当前托管侧 → 本地库（优先 dns_account_id≠目标，再注册商），供后续推送到目标账户 ──
        // 注册商账户：改 NS 与兜底拉取；与 dns_account_id（旧/新托管）区分。
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

        $this->resolveService->ensureDnsAccountIdPersisted($domain);

        $syncResult = null;
        $syncSourceLabel = '';
        $priorDnsAccountId = (int) $domain->getDnsAccountId();
        if ($priorDnsAccountId > 0 && $priorDnsAccountId !== $targetAccountId) {
            $priorAccount = $this->loadAccount($priorDnsAccountId);
            if ($priorAccount !== null) {
                $notify('sync_records', [
                    'domain' => $domainName,
                    'message' => (string) __(
                        '从原 DNS 托管账户拉取记录到本地（将推送到目标账户 %{1}，源账户 ID %{2}）',
                        [(string) $targetCode, (string) $priorDnsAccountId]
                    ),
                ]);
                $tryPrior = $this->syncRecordsFromSource($domain, $priorAccount, true);
                $priorErr = (string) ($tryPrior['error'] ?? '');
                $priorSkipped = (bool) ($tryPrior['skipped_empty'] ?? false);
                if ($priorErr === '' && !$priorSkipped) {
                    $syncResult = $tryPrior;
                    $syncSourceLabel = (string) $priorAccount->getRegistrarCode();
                } elseif ($priorErr !== '') {
                    $notify('sync_records_result', [
                        'error' => $priorErr,
                        'message' => (string) __('[步骤1] 原 DNS 托管账户拉取失败，将改从注册商同步：%{1}', [$priorErr]),
                    ]);
                } elseif ($priorSkipped) {
                    $notify('sync_records', [
                        'domain' => $domainName,
                        'message' => (string) __('原 DNS 托管账户未返回可写入记录，改为从注册商同步'),
                    ]);
                }
            }
        }

        if ($syncResult === null) {
            $notify('sync_records', ['domain' => $domainName, 'message' => (string) __('从注册商同步 DNS 记录到本地')]);
            $syncResult = $this->syncRecordsFromSource($domain, $sourceAccount, false);
            $syncSourceLabel = (string) $sourceAccount->getRegistrarCode();
        }

        $syncErr = (string) ($syncResult['error'] ?? '');
        $syncAdded = (int) ($syncResult['added'] ?? 0);
        $syncUpdated = (int) ($syncResult['updated'] ?? 0);
        if ($syncErr !== '') {
            $notify('sync_records_result', ['error' => $syncErr, 'message' => __('[步骤1] 同步记录（非致命）：%{1}', [$syncErr])]);
        } else {
            $notify('sync_records_done', ['domain' => $domainName, 'added' => $syncAdded, 'updated' => $syncUpdated, 'message' => __('DNS 记录同步完成，新增 %{1} 条，更新 %{2} 条', [(string) $syncAdded, (string) $syncUpdated])]);
        }
        w_log_info(__('[DnsSwitchService] %{1} Step1: 从 %{2} 同步记录完成，added=%{3}，updated=%{4}', [$domainName, $syncSourceLabel, (string) $syncAdded, (string) $syncUpdated]), [], $logCh);

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

        // 同服务商不执行 NS 修改；异注册商时若注册商侧 NS 已与目标一致（用户已手动切 NS），则跳过 updateNameservers
        $sourceCode = \strtolower((string) $sourceAccount->getRegistrarCode());
        $sameProvider = ($sourceCode !== '' && $sourceCode === \strtolower($targetCode));
        $skipRegistrarNsUpdate = $sameProvider;
        if (!$skipRegistrarNsUpdate) {
            $preNs = $this->getRegistrarNameserversWithDetail($sourceAdapter, $domainName, $sourceAccount->getCredentials());
            if ($preNs['supported'] && $preNs['error'] === '' && $preNs['normalized'] === $targetNsNormalized) {
                $skipRegistrarNsUpdate = true;
            }
        }

        if ($skipRegistrarNsUpdate) {
            $skipMsg = $sameProvider
                ? __('当前已是同注册商 DNS（%{1}），无需调用修改 NS 接口；直接同步/推送记录。', [$targetCode])
                : __('注册商登记的 NS 已与目标 DNS 一致，跳过修改 NS 接口；直接同步/推送记录。');
            $notify('switch_ns_skip', [
                'domain' => $domainName,
                'message' => $skipMsg,
            ]);
            if ($sameProvider) {
                w_log_info(__('[DnsSwitchService] %{1} Step3: 同服务商跳过 NS 修改（注册商=%{2}）', [$domainName, $targetCode]), [], $logCh);
            } else {
                w_log_info(__('[DnsSwitchService] %{1} Step3: 注册商 NS 已与目标一致，跳过改 NS 接口', [$domainName]), [], $logCh);
            }
        } else {
            // ── Step 3: 仅在注册商侧修改注册局委派的 NS（在 Cloudflare 建 Zone 不能替代此步） ──
            $notify('switch_ns', ['domain' => $domainName, 'message' => __('在注册商 %{1} 切换 NS（注册局委派，仅此路径生效）', [$sourceAccount->getRegistrarCode()])]);
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
        }

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
                $notify,
                $probeDoh
            );
            if ($waitResult['aborted'] ?? false) {
                return \array_merge($this->fail(__('已由用户断开')), ['client_aborted' => true]);
            }
            if (!($waitResult['verified'] ?? false)) {
                return $this->fail(__('等待 NS 生效超时（%{1} 分钟），请稍后在「管理 DNS」中检查并手动搬迁记录', [(string) (\round($waitMaxSeconds / 60))]));
            }
            $publicNsPropagationPending = (($waitResult['verified_by'] ?? '') === 'registrar');
        }

        // ── Step 4: 推送 DNS 记录到目标账户（以 Step1 后本地库为准，避免调用方前置 records_to_push 快照过期） ──
        $notify('push_records', ['domain' => $domainName, 'message' => __('推送 DNS 记录到目标账户 %{1}', [$targetCode])]);
        $recordsToPush = $this->resolveService->getRecordsForPush($domain);
        if ($recordsToPush === [] && isset($options['records_to_push']) && \is_array($options['records_to_push']) && $options['records_to_push'] !== []) {
            $recordsToPush = $options['records_to_push'];
        }
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

        $authValidation = $this->resolveService->validateAuthoritativeDnsMatchesProvider($domainName, $targetCode);
        $requireLiveAuthorityForCutover = $this->targetRequiresPublicAuthoritativeNsForCutover($targetCode);
        $liveAuthorityOk = (bool) ($authValidation['ok'] ?? false);
        // 委派已由 Step3 注册商接口写入；此处 DoH 仅与 waitForNameservers 一致，用于观测全球解析是否已跟上（本机 libc 可能仍旧）
        if ($requireLiveAuthorityForCutover && !$liveAuthorityOk && $probeDoh) {
            $dohLive = $this->normalizeNameservers($this->resolveService->getLiveNameserversViaCloudflareDoH($domainName));
            $targetNormForCutover = $this->normalizeNameservers($targetNs);
            if ($dohLive !== [] && $dohLive === $targetNormForCutover) {
                $liveAuthorityOk = true;
                $notify('cutover_authority_ok_via_doh', [
                    'domain' => $domainName,
                    'message' => (string) __(
                        'Cutover：本机 NS 仍为「%{1}」，DoH 已指向目标「%{2}」。委派仅经注册商 Step3 生效；DoH 与等待步骤同为传播观测，准予 dns_cutover_complete。',
                        [
                            ($authValidation['live_ns'] ?? []) !== [] ? \implode(', ', $authValidation['live_ns']) : (string) __('(空)'),
                            \implode(', ', $targetNs),
                        ]
                    ),
                ]);
            }
        }
        $deferCutoverForPropagation = $requireLiveAuthorityForCutover && !$liveAuthorityOk;

        // ── Step 6: 更新 Domain 标记（含 CDN） ──
        $domain->setNameservers($targetNs);
        $domain->setDnsProvider($targetCode);
        $domain->setDnsAccountId($targetAccountId);
        if ($deferCutoverForPropagation) {
            $domain->setDnsSwitchPending(1);
            $domain->setDnsCutoverComplete(0);
            $detailMsg = (string) __(
                '注册商或目标面板可能已显示新 NS，但公网权威 NS（与 dig/nslookup 一致）尚未指向 %{1}。已保持 dns_switch_pending=1、dns_cutover_complete=0，DnsCdnAutoSwitch 将重试；证书申请在 cutover 完成前不会进入队列。',
                [$targetCode]
            );
            $notify('dns_cutover_waiting_public_ns', [
                'domain' => $domainName,
                'message' => (string) ($authValidation['message'] ?? ''),
                'detail' => $detailMsg,
            ]);
            $liveStr = ($authValidation['live_ns'] ?? []) !== [] ? \implode(', ', $authValidation['live_ns']) : '';
            w_log_warning(__('[DnsSwitchService] %{1} Step6: 推迟 cutover（公网权威 NS 未指向 %{2}）。live_ns=%{3}', [
                $domainName, $targetCode, $liveStr,
            ]), [], $logCh);
        } else {
            $domain->setDnsSwitchPending(0);
            $domain->setDnsCutoverComplete(1);
        }
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
        $notify('domain_switch_persisted', [
            'domain' => $domainName,
            'dns_switch_pending' => (int) $domain->getDnsSwitchPending(),
            'dns_cutover_complete' => (int) $domain->getDnsCutoverComplete(),
            'dns_migration_pending' => (int) $domain->getDnsMigrationPending(),
            'dns_account_id' => (int) $domain->getDnsAccountId(),
            'dns_provider' => (string) $domain->getDnsProvider(),
            'defer_cutover_public_ns' => $deferCutoverForPropagation,
            'message' => $deferCutoverForPropagation
                ? (string) __('落库摘要：待传播完成后再将 dns_cutover_complete 置 1；证书队列仍关闭。定时任务 DnsCdnAutoSwitch 会重试检测公网权威 NS。')
                : (string) __('落库摘要：切换流程已闭环（dns_switch_pending=0，dns_cutover_complete=1），可进行证书申请与建站相关流程。'),
        ]);
        w_log_info(__(
            '[DnsSwitchService] %{1} Step6 落库: dns_switch_pending=%{2}, dns_cutover_complete=%{3}, dns_migration_pending=%{4}, dns_account_id=%{5}, defer_public_ns=%{6}',
            [
                $domainName,
                (string) $domain->getDnsSwitchPending(),
                (string) $domain->getDnsCutoverComplete(),
                (string) $domain->getDnsMigrationPending(),
                (string) $domain->getDnsAccountId(),
                $deferCutoverForPropagation ? '1' : '0',
            ]
        ), [], $logCh);

        // ── Step 7: 更新 DomainPool 状态 ──
        $cdnProvider = $cdnAccount !== null ? (string) $cdnAccount->getRegistrarCode() : ($this->dnsDetector->isCdnProvider($targetCode) ? $targetCode : '');
        $this->updateDomainPoolStatus($domainName, $targetCode, $cdnProvider, !$deferCutoverForPropagation);

        // ── Step 8: 可选 CDN/边缘校验（由目标 DNS 适配器 API 实现，如 Cloudflare 代理状态） ──
        if ($verifyCdn && !$deferCutoverForPropagation) {
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
            'public_ns_propagation_pending' => $publicNsPropagationPending || $deferCutoverForPropagation,
            'dns_switch_pending' => (int) $domain->getDnsSwitchPending(),
            'dns_cutover_complete' => (int) $domain->getDnsCutoverComplete(),
            'defer_cutover_public_ns' => $deferCutoverForPropagation,
        ]);
        w_log_info(__('[DnsSwitchService] %{1} Step7: DomainPool 已更新，流程完成', [$domainName]), [], $logCh);

        $successMessage = $deferCutoverForPropagation
            ? (string) __(
                'DNS 已配置并推送至 %{1}，但公网权威 NS 尚未与该服务商一致（与证书 DNS-01 校验一致）。已保持待切换队列，传播完成后定时任务将自动完成 cutover。',
                [$targetCode]
            )
            : (string) __('DNS 切换成功：%{1} → %{2}', [$domainName, $targetCode]);

        return [
            'success' => true,
            'message' => $successMessage,
            'nameservers' => $targetNs,
            'push_success' => $pushSuccess,
            'push_added' => $pushAdded,
            'push_failed' => $pushFailed,
            'public_ns_pending' => $deferCutoverForPropagation,
        ];
    }

    /**
     * Cron / SSE 共用：是否等待公网 NS（读 websites.env dns_switch）
     *
     * @return array<string, mixed>
     */
    public function getEnvWaitOptionsForTarget(DomainRegistrarAccount $targetAccount): array
    {
        $ds = Env::module_env('Weline_Websites', 'dns_switch') ?? [];
        if (empty($ds['wait_public_ns_enabled'])) {
            return [];
        }
        $codes = $ds['wait_public_ns_provider_codes'] ?? ['cloudflare'];
        $codes = \is_array($codes) ? $codes : [];
        $codes = \array_map(static fn ($c) => \strtolower(\trim((string) $c)), $codes);
        $targetCode = \strtolower(\trim((string) $targetAccount->getRegistrarCode()));
        if ($targetCode === '' || !\in_array($targetCode, $codes, true)) {
            return [];
        }

        return [
            'wait_for_ns' => true,
            'wait_max_seconds' => (int) ($ds['wait_public_ns_max_seconds'] ?? 180),
            'wait_interval_seconds' => (int) ($ds['wait_public_ns_interval_seconds'] ?? 15),
            'ns_probe_cloudflare_doh' => (bool) ($ds['ns_probe_use_cloudflare_doh'] ?? true),
        ];
    }

    /**
     * 与 {@see DnsCdnAutoSwitch} 一致的默认 options：{@see getEnvWaitOptionsForTarget} + 根域已绑 CDN 账户时自动 verify_cdn。
     * 自定义 SSE 等在返回值上 {@see array_merge} 覆盖（勿用 null 覆盖已有 cdn_account，除非有意清空）。
     *
     * @return array<string, mixed>
     */
    public function buildStandardSwitchOptions(Domain $domain, DomainRegistrarAccount $targetAccount): array
    {
        $options = $this->getEnvWaitOptionsForTarget($targetAccount);
        $cdnId = (int) $domain->getCdnAccountId();
        if ($cdnId > 0) {
            $cdnAcc = ObjectManager::getInstance(DomainRegistrarAccount::class, [], false);
            $cdnAcc->load($cdnId);
            if ((int) $cdnAcc->getAccountId() > 0) {
                $options['cdn_account'] = $cdnAcc;
                $options['verify_cdn'] = true;
            }
        }

        return $options;
    }

    /**
     * 推荐业务入口：{@see executeDnsSwitch} + {@see buildStandardSwitchOptions}；$optionOverrides 与之合并（后者覆盖同名键）。
     *
     * @param array<string, mixed> $optionOverrides
     * @return array{success: bool, message: string, nameservers: array, push_success: bool, push_added: int, push_failed: int, client_aborted?: bool, public_ns_pending?: bool}
     */
    public function executeDnsSwitchWithStandardOptions(
        Domain $domain,
        DomainRegistrarAccount $targetAccount,
        ?callable $onStep = null,
        array $optionOverrides = []
    ): array {
        $options = \array_merge($this->buildStandardSwitchOptions($domain, $targetAccount), $optionOverrides);

        return $this->executeDnsSwitch($domain, $targetAccount, $onStep, $options);
    }

    /**
     * 对列表内目标 DNS（默认 cloudflare），仅当公网权威 NS 与 detectProvider 一致时才允许 dns_cutover_complete=1。
     * 可由 env dns_switch.cutover_requires_public_authoritative_ns=false 关闭。
     */
    private function targetRequiresPublicAuthoritativeNsForCutover(string $targetCode): bool
    {
        $ds = Env::module_env('Weline_Websites', 'dns_switch') ?? [];
        if (\array_key_exists('cutover_requires_public_authoritative_ns', $ds) && !(bool) $ds['cutover_requires_public_authoritative_ns']) {
            return false;
        }
        $codes = $ds['wait_public_ns_provider_codes'] ?? ['cloudflare'];
        $codes = \is_array($codes) ? $codes : [];
        $codes = \array_map(static fn ($c) => \strtolower(\trim((string) $c)), $codes);
        $targetCode = \strtolower(\trim($targetCode));

        return $targetCode !== '' && \in_array($targetCode, $codes, true);
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
        callable $notify,
        bool $probeCloudflareDoh = true
    ): array {
        $elapsed = 0;
        while ($elapsed < $maxWaitSeconds) {
            if ($isAlive !== null && !$isAlive()) {
                return ['verified' => false, 'aborted' => true, 'verified_by' => ''];
            }
            $liveNs = $this->resolveService->getLiveNameservers($domainName);
            $liveNormalized = $this->normalizeNameservers($liveNs);
            $dohNormalized = [];
            if ($probeCloudflareDoh) {
                $dohNormalized = $this->normalizeNameservers(
                    $this->resolveService->getLiveNameserversViaCloudflareDoH($domainName)
                );
            }
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
                'live_doh' => $dohNormalized,
                'target' => $targetNsNormalized,
            ]);
            $publicSystemMatch = ($liveNormalized === $targetNsNormalized);
            $publicDohMatch = ($dohNormalized !== [] && $dohNormalized === $targetNsNormalized);
            if ($publicSystemMatch || $publicDohMatch) {
                $notify('wait_ns_verified', [
                    'by' => 'public',
                    'detail' => $publicDohMatch && !$publicSystemMatch
                        ? __('公网判定：系统解析器仍为旧 NS，但 Cloudflare DoH 已返回目标 NS（可能本机 DNS 缓存滞后）。')
                        : '',
                ]);
                return ['verified' => true, 'aborted' => false, 'verified_by' => 'public'];
            }
            if ($registrarCheckNow['supported'] && $registrarCheckNow['error'] === '' && $regNormNow === $targetNsNormalized) {
                $pub = $liveNormalized === [] ? __('(暂无或仍为旧 NS)') : \implode(', ', $liveNormalized);
                $dohStr = $dohNormalized === [] ? '' : \implode(', ', $dohNormalized);
                $notify('wait_ns_public_stale', [
                    'message' => __(
                        '【注册商已改 NS，递归尚未全网一致】系统解析仍见「%{1}」%{2}；属注册局/缓存传播，非「被旧值永久覆盖」。证书与 DNS-01 需等权威 NS 在全球可见（常见数分钟～48 小时）。DoH 探测：%{3}。',
                        [
                            $pub,
                            $dohStr !== '' ? '；DoH「' . $dohStr . '」' : '',
                            $dohStr !== '' ? $dohStr : __('(未取到)'),
                        ]
                    ),
                ]);
                $notify('wait_ns_verified', ['by' => 'registrar']);
                return ['verified' => true, 'aborted' => false, 'verified_by' => 'registrar'];
            }
            $sleep = \min($intervalSeconds, $maxWaitSeconds - $elapsed);
            if ($sleep <= 0) {
                break;
            }
            \Weline\Framework\Runtime\SchedulerSystem::sleep((int) $sleep);
            $elapsed += $sleep;
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
                \Weline\Framework\Runtime\SchedulerSystem::sleep((int) $sleepSeconds);
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
            \Weline\Framework\Runtime\SchedulerSystem::sleep((int) $step);
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
     * 从指定账户拉取 DNS 记录并写入本地 DB
     *
     * 与 DomainResolveService::syncDnsRecords 不同：本方法显式指定账户，
     * 避免在 dns_account_id 已指向目标（尚未 addZone）时使用错误账户。
     *
     * @param bool $skipReplaceIfRemoteEmpty 为 true 且远端记录为空时：不调用 {@see \Weline\Websites\Model\DomainDnsRecord::syncRecords}，
     *                                       避免误删本地库（用于「先试原托管账户，再回退注册商」）。
     * @return array{synced: int, added: int, updated: int, deleted: int, error: string, skipped_empty?: bool}
     */
    public function syncRecordsFromSource(Domain $domain, DomainRegistrarAccount $sourceAccount, bool $skipReplaceIfRemoteEmpty = false): array
    {
        $adapter = $this->registrarResolver->getAdapter($sourceAccount->getRegistrarCode());
        if ($adapter === null || !$adapter->supportsDnsManagement()) {
            return [
                'synced' => 0,
                'added' => 0,
                'updated' => 0,
                'deleted' => 0,
                'error' => (string) __('源账户 %{1} 不支持 DNS 管理', [$sourceAccount->getRegistrarCode()]),
                'skipped_empty' => false,
            ];
        }

        try {
            $remoteRecords = $adapter->getDnsRecords($domain->getDomain(), $sourceAccount->getCredentials());
        } catch (\Throwable $e) {
            w_log_warning(__('[DnsSwitchService] syncRecordsFromSource %{1} 失败（非致命）：%{2}', [$domain->getDomain(), $e->getMessage()]), [], 'dns_cdn_switch');
            return [
                'synced' => 0,
                'added' => 0,
                'updated' => 0,
                'deleted' => 0,
                'error' => $e->getMessage(),
                'skipped_empty' => false,
            ];
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

        if ($skipReplaceIfRemoteEmpty && $records === []) {
            return [
                'synced' => 0,
                'added' => 0,
                'updated' => 0,
                'deleted' => 0,
                'error' => '',
                'skipped_empty' => true,
            ];
        }

        $dnsRecordModel = ObjectManager::getInstance(\Weline\Websites\Model\DomainDnsRecord::class);
        $result = $dnsRecordModel->syncRecords($domain->getDomainId(), $records);
        $result['error'] = '';
        $result['skipped_empty'] = false;

        return $result;
    }

    /**
     * @param bool $markInfraReady false：公网权威 NS 尚未切完，保持 switching，避免后台误以为 DNS 已可签发证书
     */
    private function updateDomainPoolStatus(string $rootDomain, string $dnsProvider, string $cdnProvider, bool $markInfraReady = true): void
    {
        $poolModel = ObjectManager::getInstance(DomainPool::class);
        $pools = $poolModel->clearQuery()
            ->where(DomainPool::schema_fields_ROOT_DOMAIN, \strtolower($rootDomain))
            ->select()
            ->fetch()
            ->getItems();

        foreach ($pools as $pool) {
            $pool->setDnsProvider($dnsProvider);
            if ($markInfraReady) {
                $pool->setDnsStatus(DomainPool::INFRA_STATUS_READY);
                if ($cdnProvider !== '') {
                    $pool->setCdnStatus(DomainPool::INFRA_STATUS_READY);
                }
            } else {
                $pool->setDnsStatus(DomainPool::INFRA_STATUS_SWITCHING);
                if ($cdnProvider !== '') {
                    $pool->setCdnStatus(DomainPool::INFRA_STATUS_SWITCHING);
                }
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
