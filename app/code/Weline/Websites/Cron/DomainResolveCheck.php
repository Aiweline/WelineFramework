<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名解析检测定时任务
 *
 * 定期检测所有域名的解析状态，更新解析 IP 和本服务器指向状态
 */

namespace Weline\Websites\Cron;

use Weline\Cron\Attribute\CronTestHelp;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Service\DomainCronLockService;
use Weline\Websites\Service\DomainResolveService;
use Weline\Websites\Service\DomainRootRegistrationSelfCorrectService;
use Weline\Websites\Service\SubdomainGeneratorService;
use Weline\Websites\Cron\Concern\WebsitesCronTestRunnerTrait;
use Weline\Websites\Service\WebsitesCronTestContext;

/**
 * 由 {@see WebsitesDomainResolvePipeline} 第三步调用。
 */
#[CronTestHelp(
    description: '根域解析检测 + 默认子域入池：先扫描未建站就绪的根域，若其下所有子域都可建站则从子域回填根域各字段；再对仍未就绪的根域做 A/AAAA 检测与入池，并纠正根域状态。',
    examples: ['php bin/w cron:test --task=domain_resolve_check --domain=example.com -v'],
    manual_help: [
        '① 根域字段回填：取 site_ready=0 的根域，若该根下所有子域均为 site_ready=1，则用子域数据更新根域（status、resolve_status、resolved_ip、https_status、site_ready 等），不再重复解析。',
        '② 注册状态纠正：存在至少一个可建站子域但根域仍非 active 的，将根域标为 active。',
        '③ 解析与入池：对仍未就绪的根域确保默认子域入池并做 DNS 解析检测。',
        '--domain= 仅处理该根域；不指定则按批次处理。',
    ],
)]
class DomainResolveCheck
{
    use WebsitesCronTestRunnerTrait;

    private const ROOT_RESOLVE_BATCH = 200;

    public function execute(): string
    {
        try {
            $domainModel = ObjectManager::getInstance(Domain::class);
            $resolveService = ObjectManager::getInstance(DomainResolveService::class);
            $selfCorrect = ObjectManager::getInstance(DomainRootRegistrationSelfCorrectService::class);
            // 先：未就绪根域中，若所有子域都可建站则从子域回填根域各字段
            $syncedFromPool = $selfCorrect->syncRootFieldsFromPoolBatch(200);
            // 再：存在可建站子域但根域仍非 active 的，纠正为 active
            $batchPromoted = $selfCorrect->correctBatch(200);

            // 未建站就绪根域，每轮限量避免单次超时；多轮 cron 扫完。
            $domains = $domainModel->clearQuery()
                ->where(Domain::schema_fields_SITE_READY, 0)
                ->order(Domain::schema_fields_DOMAIN, 'ASC')
                ->limit(self::ROOT_RESOLVE_BATCH)
                ->select()
                ->fetchArray();

            $total = \count($domains);
            WebsitesCronTestContext::detail('DomainResolveCheck.batch', ['site_ready_0_count' => $total]);
            $resolved = 0;
            $local = 0;
            $errors = 0;
            $poolAdded = 0;
            $loopPromoted = 0;

            $subdomainGenerator = ObjectManager::getInstance(SubdomainGeneratorService::class);
            $cronLock = ObjectManager::getInstance(DomainCronLockService::class);

            foreach ($domains as $row) {
                $domain = ObjectManager::getInstance(Domain::class, [], false);
                $domain->clearQuery()
                    ->where(Domain::schema_fields_ID, (int) ($row[Domain::schema_fields_ID] ?? 0))
                    ->find()
                    ->fetch();
                if (!$domain->getDomainId()) {
                    continue;
                }
                $dn = $domain->getDomain();
                if (!WebsitesCronTestContext::matchesSubject($dn, $dn)) {
                    WebsitesCronTestContext::skipNote($dn, 'root resolve check');
                    continue;
                }
                if ($cronLock->shouldSkipNonCertificateWorkForRootFqdn($dn)) {
                    WebsitesCronTestContext::skipNote($dn, 'cron_resolved lock');
                    continue;
                }
                WebsitesCronTestContext::detail('root_resolve_row', ['domain' => $dn, 'site_ready' => $domain->isSiteReady()]);

                // 无论解析成功或失败，都立即确保子域名接入域名池
                try {
                    $poolResult = $subdomainGenerator->generateDefaultSubdomains($domain);
                    WebsitesCronTestContext::detail('generateDefaultSubdomains', ['domain' => $dn, 'poolResult' => $poolResult]);
                    $poolAdded += $poolResult['added'] ?? 0;
                } catch (\Throwable $e) {
                    w_log_warning(__('子域名入池失败: %{1}, %{2}', [$domain->getDomain(), $e->getMessage()]), [], 'domain_resolve_check');
                }

                try {
                    $result = $resolveService->checkResolve($domain);
                    WebsitesCronTestContext::detail('checkResolve_root', ['domain' => $dn, 'result' => $result]);

                    if ($result['resolved']) {
                        $resolved++;
                    } else {
                        $errors++;
                    }

                    if ($result['is_local']) {
                        $local++;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    w_log_warning("域名 {$row[Domain::schema_fields_DOMAIN]} 检测失败: {$e->getMessage()}", [], 'domain_resolve_check');
                }
            }

            $message = \sprintf(
                '检测完成: 本批 %d 个域名, %d 个已解析, %d 个指向本服务器, %d 个错误',
                $total,
                $resolved,
                $local,
                $errors
            );
            if ($total >= self::ROOT_RESOLVE_BATCH) {
                $message .= '；' . (string) __('未就绪根域较多时将分多轮检测');
            }
            if ($poolAdded > 0) {
                $message .= \sprintf(', 子域名入池 %d 个', $poolAdded);
            }
            if ($syncedFromPool > 0) {
                $message .= \sprintf(', %s %d', (string) __('根域从子域回填'), $syncedFromPool);
            }
            if ($batchPromoted > 0 || $loopPromoted > 0) {
                $message .= \sprintf(
                    ', %s %d+%d',
                    (string) __('根域注册状态纠正'),
                    $batchPromoted,
                    $loopPromoted
                );
            }

            w_log_info($message, [], 'domain_resolve_check');

            return $message;
        } catch (\Throwable $e) {
            $errorMsg = '解析检测任务异常: ' . $e->getMessage();
            w_log_error($errorMsg, [], 'domain_resolve_check');
            return $errorMsg;
        }
    }
}
