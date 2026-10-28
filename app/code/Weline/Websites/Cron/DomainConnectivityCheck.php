<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名连通性检测定时任务
 *
 * 定期对根域与子域（域名池）做轻探测（DNS → 单次 TCP/443 → TLS 读证书），
 * 只有证书临期（<= 3 天，含已过期）或轻探测失败时才升级为完整 HTTP(S) 诊断并写 connectivity_detail。
 */

namespace Weline\Websites\Cron;

use Weline\Cron\CronTaskPreflightInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Cron\Attribute\CronTestHelp;
use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Service\DomainConnectivityService;
use Weline\Websites\Service\DomainCronLockService;
use Weline\Websites\Cron\Concern\WebsitesCronTestRunnerTrait;
use Weline\Websites\Service\WebsitesCronTestContext;

#[CronTestHelp(
    description: '对根域与子域做轻探测（DNS → 单次 TCP/443 → TLS 读证书，不拉页面、不跟随重定向）；仅证书剩余 <= 3 天（含已过期）或轻探测失败时才做完整 HTTP(S) 诊断并写详情。--domain= 可限定根域或子域。',
    examples: [
        'php bin/w cron:test --task=domain_connectivity_check --domain=example.com -v',
        'php bin/w cron:test --task=domain_connectivity_check --domain=www.example.com -v',
    ],
    manual_help: [
        '轻探测：DNS 解析 → 单次 TCP/443 连接 → TLS 握手读证书 notAfter；短超时（4 秒），失败返回结构化结果而不是抛异常。',
        '重活门槛：证书剩余 <= 3 天（含已过期）或轻探测未通过时，才走完整 HTTP 探测（先 HTTP；失败再 HTTPS 且关闭 TLS 证书校验，仅看是否响应）并写 connectivity_detail。',
        '其余情况只更新 connectivity_status / connectivity_checked_at（+ updated_at），不做每条一次全字段 UPDATE；detail 与「证书管理」摘要保持上一次重活的结果。',
        '新鲜窗口：connectivity_checked_at 在 websites.connectivity_ttl_hours（默认 6 小时）内直接跳过该域名，不探测。',
        'preflight：hasPendingWork() 用轻量 SQL 判断是否存在 TTL 过期/未检测的域名，无活则不 spawn 子进程；前置检查内不做网络 IO。',
        '每 15 分钟跑一批根域与一批子域，用于后台列表「连通性」列与 hover 详情，不参与建站就绪判断。',
        '根域/池子所属根域 cron_resolved=1 时跳过探测，避免锁定后重复写 connectivity_*。',
    ],
)]
class DomainConnectivityCheck implements CronTaskInterface, CronTaskPreflightInterface
{
    use WebsitesCronTestRunnerTrait;

    private const LOG_KEY = 'domain_connectivity_check';
    private const ROOT_BATCH = 80;
    private const POOL_BATCH = 120;
    /** 证书剩余天数门槛：<= 该值（含已过期）才升级为重活 */
    private const CERT_HEAVY_THRESHOLD_DAYS = 3;
    /** connectivity_checked_at 的新鲜窗口默认小时数 */
    private const DEFAULT_TTL_HOURS = 6;

    public function name(): string
    {
        return __('域名可访问性检测');
    }

    public function execute_name(): string
    {
        return 'domain_connectivity_check';
    }

    public function tip(): string
    {
        return __('轻探测（DNS + TCP/443 + TLS 读证书）连通性；证书剩余 <= 3 天或失败才做完整 HTTP 诊断写详情');
    }

    public function cron_time(): string
    {
        return '*/15 * * * *';
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 35;
    }

    /**
     * 本轮是否有待检查的域名：新鲜窗口外（或从未检测）的根域/活跃池行存在即有活
     *
     * 只做轻量 SQL（LIMIT 1），不做任何网络 IO；抛异常按有活处理（fail-open）。
     */
    public function hasPendingWork(): bool
    {
        try {
            $cutoff = \date('Y-m-d H:i:s', \time() - $this->ttlHours() * 3600);

            return $this->existsStaleRow(Domain::class, [], $cutoff)
                || $this->existsStaleRow(DomainPool::class, [DomainPool::schema_fields_STATUS => DomainPool::STATUS_ACTIVE], $cutoff);
        } catch (\Throwable) {
            return true;
        }
    }

    public function execute(): string
    {
        /** @var DomainConnectivityService $service */
        $service = ObjectManager::getInstance(DomainConnectivityService::class);
        $domainFilter = WebsitesCronTestContext::getDomainFilter();
        $ttlHours = $this->ttlHours();

        $rootOk = 0;
        $rootErr = 0;
        $poolOk = 0;
        $poolErr = 0;

        $domainModel = ObjectManager::getInstance(Domain::class);
        $cronLock = ObjectManager::getInstance(DomainCronLockService::class);
        $rootRows = $domainModel->clearQuery()
            ->order(Domain::schema_fields_DOMAIN, 'ASC')
            ->limit(self::ROOT_BATCH)
            ->select()
            ->fetchArray();

        foreach ($rootRows as $row) {
            $domainName = (string) ($row[Domain::schema_fields_DOMAIN] ?? '');
            if ($domainName === '') {
                continue;
            }
            if ($domainFilter !== null && !WebsitesCronTestContext::matchesSubject($domainName, $domainName)) {
                continue;
            }
            if ($cronLock->shouldSkipNonCertificateWorkForRootFqdn($domainName)) {
                continue;
            }
            $ok = $this->applyConnectivity(Domain::class, $row, $domainName, $service, $ttlHours);
            if ($ok === null) {
                continue;
            }
            if ($ok) {
                $rootOk++;
            } else {
                $rootErr++;
            }
        }

        $poolModel = ObjectManager::getInstance(DomainPool::class);
        $poolRows = $poolModel->clearQuery()
            ->where(DomainPool::schema_fields_STATUS, DomainPool::STATUS_ACTIVE)
            ->order(DomainPool::schema_fields_DOMAIN, 'ASC')
            ->limit(self::POOL_BATCH)
            ->select()
            ->fetchArray();

        foreach ($poolRows as $row) {
            $domainName = (string) ($row[DomainPool::schema_fields_DOMAIN] ?? '');
            if ($domainName === '') {
                continue;
            }
            if ($domainFilter !== null && !WebsitesCronTestContext::matchesSubject($domainName, $domainName)) {
                continue;
            }
            if ($cronLock->shouldSkipNonCertificateWorkForPoolRow($row)) {
                continue;
            }
            $ok = $this->applyConnectivity(DomainPool::class, $row, $domainName, $service, $ttlHours);
            if ($ok === null) {
                continue;
            }
            if ($ok) {
                $poolOk++;
            } else {
                $poolErr++;
            }
        }

        $msg = \sprintf(
            __('连通性检测：根域 %d 成功 / %d 失败，子域 %d 成功 / %d 失败'),
            $rootOk,
            $rootErr,
            $poolOk,
            $poolErr
        );
        w_log_info('[DomainConnectivityCheck] ' . $msg, [], self::LOG_KEY);
        return $msg;
    }

    /**
     * 单条记录：新鲜窗口跳过 → 轻探测 → 必要时重活
     *
     * @param class-string<Domain|DomainPool> $modelClass
     * @param array<string, mixed> $row
     * @return bool|null true=连通 false=不连通 null=新鲜窗口跳过
     */
    protected function applyConnectivity(
        string $modelClass,
        array $row,
        string $domainName,
        DomainConnectivityService $service,
        int $ttlHours
    ): ?bool {
        $checkedAt = (string) ($row[$modelClass::schema_fields_CONNECTIVITY_CHECKED_AT] ?? '');
        if ($this->isFresh($checkedAt, $ttlHours)) {
            WebsitesCronTestContext::detail('skip:ttl_fresh', [
                'domain' => $domainName,
                'checked_at' => $checkedAt,
                'ttl_hours' => $ttlHours,
            ]);

            return null;
        }

        $light = $this->probeLight($service, $domainName);
        WebsitesCronTestContext::detail('probe_light', [
            'domain' => $domainName,
            'status' => $light['status'] ?? '',
            'cert_days_left' => $light['cert_days_left'] ?? null,
            'detail' => $light['detail'] ?? '',
        ]);
        if (!$this->shouldDoHeavyWork($light)) {
            return $this->writeLightRow($modelClass, $row, $light);
        }

        WebsitesCronTestContext::detail('probe_heavy', [
            'domain' => $domainName,
            'reason' => ($light['status'] ?? '') === 'ok' ? 'cert_expiring' : 'light_probe_failed',
        ]);

        return $this->writeHeavyRow($modelClass, $row, $this->probeHeavy($service, $domainName));
    }

    /**
     * @return array{status: 'ok'|'error', checked_at: string, detail: string, cert_expires_at: ?string, cert_days_left: ?int}
     */
    protected function probeLight(DomainConnectivityService $service, string $domainName): array
    {
        return $service->probeLight($domainName);
    }

    /**
     * 重活：完整 HTTP(S) 探测（含「证书管理」摘要）
     *
     * @return array{status: 'ok'|'error', detail: string, checked_at: string}
     */
    protected function probeHeavy(DomainConnectivityService $service, string $domainName): array
    {
        return $service->probe($domainName);
    }

    /**
     * 轻量写回：只动 connectivity_status / connectivity_checked_at（+ updated_at），保留原详情
     *
     * @param class-string<Domain|DomainPool> $modelClass
     * @param array<string, mixed> $row
     * @param array<string, mixed> $light
     */
    protected function writeLightRow(string $modelClass, array $row, array $light): bool
    {
        $ok = ($light['status'] ?? '') === 'ok';
        $checkedAt = (string) ($light['checked_at'] ?? \date('Y-m-d H:i:s'));
        $model = ObjectManager::getInstance($modelClass, [], false);
        $model->clearQuery()
            ->where($modelClass::schema_fields_ID, (int) ($row[$modelClass::schema_fields_ID] ?? 0))
            ->update([
                $modelClass::schema_fields_CONNECTIVITY_STATUS => $ok ? $modelClass::CONNECTIVITY_OK : $modelClass::CONNECTIVITY_ERROR,
                $modelClass::schema_fields_CONNECTIVITY_CHECKED_AT => $checkedAt,
                $modelClass::schema_fields_UPDATED_AT => $checkedAt,
            ])
            ->fetch();

        return $ok;
    }

    /**
     * 重活写回：完整 connectivity_detail（hover 展示 + 证书管理摘要）
     *
     * @param class-string<Domain|DomainPool> $modelClass
     * @param array<string, mixed> $row
     * @param array<string, mixed> $result
     */
    protected function writeHeavyRow(string $modelClass, array $row, array $result): bool
    {
        $ok = ($result['status'] ?? '') === 'ok';
        $model = ObjectManager::getInstance($modelClass, [], false);
        $model->setData($row);
        $model->setConnectivityStatus($ok ? $modelClass::CONNECTIVITY_OK : $modelClass::CONNECTIVITY_ERROR);
        $model->setConnectivityCheckedAt((string) ($result['checked_at'] ?? \date('Y-m-d H:i:s')));
        $model->setConnectivityDetail((string) ($result['detail'] ?? ''));
        $model->save();

        return $ok;
    }

    /**
     * connectivity_checked_at 是否还在新鲜窗口内（窗口内直接跳过，不探测）
     */
    protected function isFresh(string $checkedAt, int $ttlHours): bool
    {
        $checkedAt = \trim($checkedAt);
        if ($checkedAt === '') {
            return false;
        }
        $ts = \strtotime($checkedAt);
        if ($ts === false) {
            return false;
        }

        return (\time() - $ts) < $ttlHours * 3600;
    }

    /**
     * 是否升级为重活：证书剩余 <= 3 天（含已过期）或轻探测未通过
     *
     * @param array<string, mixed> $light
     */
    protected function shouldDoHeavyWork(array $light): bool
    {
        if (($light['status'] ?? '') !== 'ok') {
            return true;
        }
        $daysLeft = $light['cert_days_left'] ?? null;
        if ($daysLeft === null) {
            return true;
        }

        return (int) $daysLeft <= self::CERT_HEAVY_THRESHOLD_DAYS;
    }

    /**
     * 新鲜窗口小时数：websites.connectivity_ttl_hours，默认 6
     */
    private function ttlHours(): int
    {
        try {
            $hours = (int) Env::getInstance()->getConfig('websites.connectivity_ttl_hours', self::DEFAULT_TTL_HOURS);
        } catch (\Throwable) {
            $hours = self::DEFAULT_TTL_HOURS;
        }

        return $hours > 0 ? $hours : self::DEFAULT_TTL_HOURS;
    }

    /**
     * 是否存在「无检测时间 / 超过新鲜窗口」的行（LIMIT 1 轻量判断）
     *
     * @param class-string<Domain|DomainPool> $modelClass
     * @param array<string, mixed> $scopeWheres
     */
    private function existsStaleRow(string $modelClass, array $scopeWheres, string $cutoff): bool
    {
        $checkedField = $modelClass::schema_fields_CONNECTIVITY_CHECKED_AT;
        foreach ([['is null', null], ['<', $cutoff]] as [$condition, $value]) {
            $query = ObjectManager::getInstance($modelClass, [], false)->clearQuery();
            foreach ($scopeWheres as $field => $fieldValue) {
                $query = $query->where($field, $fieldValue);
            }
            $rows = $query->where($checkedField, $value, $condition)
                ->limit(1)
                ->select()
                ->fetchArray();
            if ($rows !== []) {
                return true;
            }
        }

        return false;
    }
}
