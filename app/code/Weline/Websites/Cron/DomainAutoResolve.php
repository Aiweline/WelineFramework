<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名自动解析定时任务
 *
 * 处理两种场景：
 * 1. 购买域名时勾选了"自动解析"，创建了 DomainAutoResolveTask 任务
 * 2. 全局开启自动解析时，为未解析的域名添加记录
 */

namespace Weline\Websites\Cron;

use Weline\Cron\Attribute\CronTestHelp;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainAutoResolveTask;
use Weline\Websites\Model\DomainConfig;
use Weline\Websites\Model\DomainRegistrar;
use Weline\Websites\Model\DomainRegistrarAccount;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Service\DomainOriginMatchService;
use Weline\Websites\Service\DomainPoolResolveService;
use Weline\Websites\Service\DomainRegistrarResolverService;
use Weline\Websites\Service\DomainResolveService;
use Weline\Websites\Service\ServerIpService;
use Weline\Websites\Cron\Concern\WebsitesCronTestRunnerTrait;
use Weline\Websites\Service\WebsitesCronTestContext;

/**
 * 逻辑由 {@see WebsitesDomainResolvePipeline} 统一调度（每 10 分钟），本类仅保留可执行体。
 */
#[CronTestHelp(
    description: '仅跑购买自动解析任务、DNS 迁移、全局自动解析（不跑域名池/根域解析）。',
    examples: [
        'php bin/w cron:test --task=domain_auto_resolve --domain=example.com -v',
    ],
    manual_help: [
        '控制台 --domain= 聚焦该根域相关自动解析任务。',
        '后台「后缀」未解析时与管道内定时行为一致。',
    ],
)]
class DomainAutoResolve
{
    use WebsitesCronTestRunnerTrait;

    private const STALE_PROCESSING_MINUTES = 45;
    private const GLOBAL_AUTO_RESOLVE_BATCH = 50;

    public function execute(): string
    {
        $messages = [];

        $taskResult = $this->processPurchaseTasks();
        $messages[] = $taskResult;

        $migrationResult = $this->processDnsMigrationPending();
        if ($migrationResult !== '') {
            $messages[] = $migrationResult;
        }

        $globalResult = $this->processGlobalAutoResolve();
        if ($globalResult !== '') {
            $messages[] = $globalResult;
        }

        return \implode(' | ', $messages);
    }

    /**
     * 处理购买时创建的自动解析任务
     */
    private function processPurchaseTasks(): string
    {
        try {
            $taskModel = ObjectManager::getInstance(DomainAutoResolveTask::class);
            $serverIpService = ObjectManager::getInstance(ServerIpService::class);
            $resolverService = ObjectManager::getInstance(DomainRegistrarResolverService::class);
            $accountModel = ObjectManager::getInstance(DomainRegistrarAccount::class);
            $registrarModel = ObjectManager::getInstance(DomainRegistrar::class);

            $serverIp = $serverIpService->getPublicIpv4();
            $serverIpv6 = $serverIpService->getPublicIpv6();
            WebsitesCronTestContext::detail('DomainAutoResolve.processPurchaseTasks', [
                'server_ipv4' => $serverIp !== '' ? $serverIp : null,
                'server_ipv6' => $serverIpv6 !== '' ? $serverIpv6 : null,
            ]);
            if ($serverIp === '' && $serverIpv6 === '') {
                return '无法获取服务器公网IP，跳过自动解析任务';
            }
            $originMatch = ObjectManager::getInstance(DomainOriginMatchService::class);

            $staleBefore = \date('Y-m-d H:i:s', \time() - self::STALE_PROCESSING_MINUTES * 60);
            $stuck = $taskModel->clearQuery()
                ->where(DomainAutoResolveTask::schema_fields_STATUS, DomainAutoResolveTask::STATUS_PROCESSING)
                ->where(DomainAutoResolveTask::schema_fields_UPDATED_AT, $staleBefore, '<')
                ->select()
                ->fetchArray();
            foreach ($stuck as $stuckRow) {
                $t = clone $taskModel;
                $t->setData($stuckRow);
                $t->setStatus(DomainAutoResolveTask::STATUS_PENDING);
                $t->setLastError((string) __('任务长时间未结束，已重置为待处理'));
                $t->save();
            }

            $tasks = $taskModel->clearQuery()
                ->where(DomainAutoResolveTask::schema_fields_STATUS, DomainAutoResolveTask::STATUS_PENDING)
                ->select()
                ->fetchArray();

            if ($tasks === []) {
                return '无待处理的自动解析任务';
            }

            $total = \count($tasks);
            $success = 0;
            $skipped = 0;
            $failed = 0;
            /** @var array<string, true> 解析成功的根域名，用于任务结束后检查并更新域名池记录 */
            $successfulDomains = [];

            foreach ($tasks as $row) {
                $task = clone $taskModel;
                $task->setData($row);
                $domain = $task->getDomain();
                if (!WebsitesCronTestContext::matchesSubject($domain, $domain)) {
                    WebsitesCronTestContext::skipNote($domain, 'purchase task domain filter');
                    continue;
                }
                $accountId = $task->getAccountId();
                WebsitesCronTestContext::detail('task_start', [
                    'domain' => $domain,
                    'account_id' => $accountId,
                    'status' => $task->getStatus(),
                    'retry' => $task->getRetryCount(),
                ]);

                $task->setStatus(DomainAutoResolveTask::STATUS_PROCESSING);
                $task->save();

                try {
                    $domainRow = ObjectManager::getInstance(Domain::class, [], false);
                    $domainRow->clearQuery()
                        ->where(Domain::schema_fields_DOMAIN, $domain)
                        ->find()
                        ->fetch();
                    $dnsId = $domainRow->getDomainId() > 0 ? (int) $domainRow->getDnsAccountId() : 0;
                    $cdnId = $domainRow->getDomainId() > 0 ? (int) $domainRow->getCdnAccountId() : 0;
                    if ($originMatch->fqdnPointsToServer($domain, $domain, $dnsId, $cdnId)) {
                        $task->setStatus(DomainAutoResolveTask::STATUS_SUCCESS);
                        $task->save();
                        $success++;
                        $successfulDomains[$domain] = true;
                        continue;
                    }

                    $account = clone $accountModel;
                    $account->load($accountId);
                    if (!$account->getAccountId()) {
                        $task->setStatus(DomainAutoResolveTask::STATUS_FAILED);
                        $task->setLastError('域名商账户不存在');
                        $task->save();
                        $failed++;
                        continue;
                    }

                    if ($originMatch->fqdnPointsToServerForRegistrarAccount($domain, $domain, $accountId)) {
                        $task->setStatus(DomainAutoResolveTask::STATUS_SUCCESS);
                        $task->save();
                        $success++;
                        $successfulDomains[$domain] = true;
                        continue;
                    }

                    $registrar = clone $registrarModel;
                    $registrar->load($account->getRegistrarId());
                    $adapter = $resolverService->getAdapter($registrar->getCode());

                    if (!$adapter) {
                        $task->setStatus(DomainAutoResolveTask::STATUS_FAILED);
                        $task->setLastError('未找到域名商适配器');
                        $task->save();
                        $failed++;
                        continue;
                    }

                    $credentials = $account->getCredentials();

                    $records = [];
                    if ($serverIp !== '' && \filter_var($serverIp, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
                        $records[] = ['type' => 'A', 'name' => '@', 'value' => $serverIp, 'ttl' => 600];
                        $records[] = ['type' => 'A', 'name' => 'www', 'value' => $serverIp, 'ttl' => 600];
                    }
                    if ($serverIpv6 !== '' && \filter_var($serverIpv6, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
                        $records[] = ['type' => 'AAAA', 'name' => '@', 'value' => $serverIpv6, 'ttl' => 600];
                        $records[] = ['type' => 'AAAA', 'name' => 'www', 'value' => $serverIpv6, 'ttl' => 600];
                    }
                    if ($records === []) {
                        $task->setStatus(DomainAutoResolveTask::STATUS_FAILED);
                        $task->setLastError((string) __('服务器公网 IP 无效，无法写入解析'));
                        $task->save();
                        $failed++;
                        continue;
                    }

                    $allSuccess = true;
                    $errors = [];

                    foreach ($records as $record) {
                        try {
                            WebsitesCronTestContext::detail('addDnsRecord', ['domain' => $domain, 'record' => $record]);
                            $result = $adapter->addDnsRecord($domain, $record, $credentials);
                            WebsitesCronTestContext::detail('addDnsRecord_result', ['success' => $result['success'] ?? false, 'message' => $result['message'] ?? '']);
                            if (!($result['success'] ?? false)) {
                                $allSuccess = false;
                                $label = ($record['type'] ?? '') . ' ' . ($record['name'] ?? '@');
                                $errors[] = $label . ': ' . ($result['message'] ?? '未知错误');
                            }
                        } catch (\Throwable $e) {
                            $allSuccess = false;
                            $label = ($record['type'] ?? '') . ' ' . ($record['name'] ?? '@');
                            $errors[] = $label . ': ' . $e->getMessage();
                        }
                    }

                    if ($allSuccess) {
                        $task->setStatus(DomainAutoResolveTask::STATUS_SUCCESS);
                        $task->save();
                        $success++;
                        $successfulDomains[$domain] = true;
                    } else {
                        $task->incrementRetryCount();
                        if ($task->canRetry()) {
                            $task->setStatus(DomainAutoResolveTask::STATUS_PENDING);
                        } else {
                            $task->setStatus(DomainAutoResolveTask::STATUS_FAILED);
                        }
                        $task->setLastError(\implode('; ', $errors));
                        $task->save();

                        if (!$task->canRetry()) {
                            $failed++;
                        } else {
                            $skipped++;
                        }
                    }
                } catch (\Throwable $e) {
                    $task->incrementRetryCount();
                    if ($task->canRetry()) {
                        $task->setStatus(DomainAutoResolveTask::STATUS_PENDING);
                    } else {
                        $task->setStatus(DomainAutoResolveTask::STATUS_FAILED);
                    }
                    $task->setLastError($e->getMessage());
                    $task->save();

                    if (!$task->canRetry()) {
                        $failed++;
                    } else {
                        $skipped++;
                    }
                }
            }

            $poolChecked = 0;
            $poolUpdated = 0;
            // 解析成功后立即检查对应域名池内的子域名，有则更新记录（IP 变了才更新，无变化则不写库）
            if ($successfulDomains !== []) {
                $poolModel = ObjectManager::getInstance(DomainPool::class);
                $poolResolveService = ObjectManager::getInstance(DomainPoolResolveService::class);
                foreach (\array_keys($successfulDomains) as $rootDomain) {
                    if (!WebsitesCronTestContext::matchesSubject($rootDomain, $rootDomain)) {
                        continue;
                    }
                    try {
                        $poolRows = $poolModel->clearQuery()
                            ->where(DomainPool::schema_fields_STATUS, DomainPool::STATUS_ACTIVE)
                            ->where(DomainPool::schema_fields_ROOT_DOMAIN, $rootDomain)
                            ->select()
                            ->fetchArray();
                        foreach ($poolRows as $poolRow) {
                            $poolFqdn = (string) ($poolRow[DomainPool::schema_fields_DOMAIN] ?? '');
                            $poolRoot = (string) ($poolRow[DomainPool::schema_fields_ROOT_DOMAIN] ?? '');
                            if (!WebsitesCronTestContext::matchesSubject($poolFqdn, $poolRoot !== '' ? $poolRoot : null)) {
                                WebsitesCronTestContext::skipNote($poolFqdn, 'pool row after purchase resolve');
                                continue;
                            }
                            $pool = ObjectManager::getInstance(DomainPool::class, [], false);
                            $pool->setData($poolRow);
                            $r = $poolResolveService->checkAndUpdateIfChanged($pool);
                            WebsitesCronTestContext::detail('pool_after_resolve', ['domain' => $poolFqdn, 'updated' => $r['updated'] ?? false]);
                            $poolChecked++;
                            if ($r['updated'] ?? false) {
                                $poolUpdated++;
                            }
                        }
                    } catch (\Throwable $e) {
                        w_log_warning(__('域名池解析检查失败: root=%{1}, 错误=%{2}', [
                            $rootDomain,
                            $e->getMessage(),
                        ]), [], 'domain_auto_resolve');
                    }
                }
            }

            $msg = \sprintf('购买任务处理: 共%d个, 成功%d, 重试%d, 失败%d', $total, $success, $skipped, $failed);
            if ($poolChecked > 0) {
                $msg .= \sprintf(' | 域名池检查%d条, 更新%d条', $poolChecked, $poolUpdated);
            }
            return $msg;
        } catch (\Throwable $e) {
            w_log_error('处理购买任务异常: ' . $e->getMessage(), [], 'domain_auto_resolve');
            return '购买任务异常: ' . $e->getMessage();
        }
    }

    /**
     * 处理 DNS 切换后的记录推送：将本地已同步的 DNS 记录推送到新账户
     */
    private function processDnsMigrationPending(): string
    {
        $logCh = 'dns_cdn_switch';
        try {
            $domainModel = ObjectManager::getInstance(Domain::class);
            $resolveService = ObjectManager::getInstance(DomainResolveService::class);
            $accountModel = ObjectManager::getInstance(DomainRegistrarAccount::class);

            $rows = $domainModel->clearQuery()
                ->where(Domain::schema_fields_DNS_MIGRATION_PENDING, 1)
                ->select()
                ->fetchArray();

            if ($rows === []) {
                return '';
            }

            $total = \count($rows);
            w_log_info(__('[DnsMigration] 开始处理 DNS 记录迁移，待处理=%{1}', [(string) $total]), [], $logCh);

            $success = 0;
            $failed = 0;

            foreach ($rows as $row) {
                $domain = clone $domainModel;
                $domain->setData($row);
                $domainName = $domain->getDomain();
                if (!WebsitesCronTestContext::matchesSubject($domainName, $domainName)) {
                    WebsitesCronTestContext::skipNote($domainName, 'dns migration filter');
                    continue;
                }
                $targetAccountId = (int) $domain->getDnsAccountId();
                WebsitesCronTestContext::detail('dns_migration_row', ['domain' => $domainName, 'target_dns_account_id' => $targetAccountId]);

                if ($targetAccountId <= 0) {
                    w_log_warning(__('[DnsMigration] %{1} 目标 DNS 账户未设置，跳过', [$domainName]), [], $logCh);
                    $domain->setDnsMigrationPending(0);
                    $domain->forceCheck(false)->save();
                    $failed++;
                    continue;
                }

                $targetAccount = clone $accountModel;
                $targetAccount->load($targetAccountId);
                if (!$targetAccount->getAccountId()) {
                    w_log_warning(__('[DnsMigration] %{1} 目标账户 ID %{2} 不存在，跳过', [$domainName, (string) $targetAccountId]), [], $logCh);
                    $domain->setDnsMigrationPending(0);
                    $domain->forceCheck(false)->save();
                    $failed++;
                    continue;
                }

                w_log_info(__('[DnsMigration] %{1} 推送 DNS 记录到账户 %{2}(%{3})', [
                    $domainName, (string) $targetAccountId, $targetAccount->getRegistrarCode(),
                ]), [], $logCh);

                $pushResult = $resolveService->pushRecordsToProvider($domain, $targetAccount, null);
                if ($pushResult['success'] ?? false) {
                    $domain->setDnsMigrationPending(0);
                    $domain->forceCheck(false)->save();
                    $success++;
                    w_log_info(__('[DnsMigration] %{1} 推送成功，added=%{2}', [
                        $domainName, (string) ($pushResult['added'] ?? 0),
                    ]), [], $logCh);
                } else {
                    $failed++;
                    w_log_error(__('[DnsMigration] %{1} 推送失败：%{2}', [
                        $domainName, \implode('; ', $pushResult['errors'] ?? []),
                    ]), [], $logCh);
                }
            }

            $msg = \sprintf('DNS记录迁移: 待处理%d, 成功%d, 失败%d', $total, $success, $failed);
            w_log_info(__('[DnsMigration] %{1}', [$msg]), [], $logCh);
            return $msg;
        } catch (\Throwable $e) {
            w_log_error('[DnsMigration] 任务异常: ' . $e->getMessage(), [], $logCh);
            return '';
        }
    }

    /**
     * 处理全局自动解析（原逻辑）
     */
    private function processGlobalAutoResolve(): string
    {
        try {
            $config = ObjectManager::getInstance(DomainConfig::class);

            if (!$config->isAutoResolveEnabled()) {
                return '';
            }

            $domainModel = ObjectManager::getInstance(Domain::class);
            $resolveService = ObjectManager::getInstance(DomainResolveService::class);

            $domains = $domainModel->clearQuery()
                ->where(Domain::schema_fields_STATUS, Domain::STATUS_ACTIVE)
                ->where(Domain::schema_fields_IS_LOCAL_SERVER, 0)
                ->order(Domain::schema_fields_DOMAIN, 'ASC')
                ->limit(self::GLOBAL_AUTO_RESOLVE_BATCH)
                ->select()
                ->fetchArray();

            $total = \count($domains);
            if ($total === 0) {
                return '';
            }

            $success = 0;
            $failed = 0;
            $errors = [];

            foreach ($domains as $row) {
                $domain = clone $domainModel;
                $domain->setData($row);
                $dn = $domain->getDomain();
                if (!WebsitesCronTestContext::matchesSubject($dn, $dn)) {
                    WebsitesCronTestContext::skipNote($dn, 'global auto resolve filter');
                    continue;
                }
                WebsitesCronTestContext::detail('global_auto_resolve', ['domain' => $dn]);

                try {
                    $result = $resolveService->autoResolveToLocal($domain);
                    WebsitesCronTestContext::detail('global_auto_resolve_result', ['domain' => $dn, 'success' => $result['success'] ?? false, 'errors' => $result['errors'] ?? []]);

                    if ($result['success']) {
                        $success++;
                    } else {
                        $failed++;
                        $errors[] = $domain->getDomain() . ': ' . \implode('; ', $result['errors']);
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = $domain->getDomain() . ': ' . $e->getMessage();
                }
            }

            if ($errors !== []) {
                w_log_warning("全局解析失败详情:\n" . \implode("\n", \array_slice($errors, 0, 10)), [], 'domain_auto_resolve');
            }

            $msg = \sprintf('全局解析: 本批%d个, 成功%d, 失败%d', $total, $success, $failed);
            if ($total >= self::GLOBAL_AUTO_RESOLVE_BATCH) {
                $msg .= '；' . (string) __('未就绪域名较多时将分多轮处理');
            }

            return $msg;
        } catch (\Throwable $e) {
            w_log_error('全局自动解析异常: ' . $e->getMessage(), [], 'domain_auto_resolve');
            return '';
        }
    }
}
