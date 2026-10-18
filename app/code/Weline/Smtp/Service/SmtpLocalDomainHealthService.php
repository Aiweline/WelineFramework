<?php
declare(strict_types=1);

namespace Weline\Smtp\Service;

use Weline\Smtp\Model\SmtpService;

/**
 * Smtp 本机域名健康聚合：列表徽章与 create/enable/import 门禁共用。
 * 只经 w_query('mail', getSmtpTlsStatus|getSmtpTlsStatusBatch)；禁止直调池模型或 TLS 同步服务类。
 *
 * @phpstan-type SmtpLocalHealthSummary array{
 *   smtp_service_id: int,
 *   mode: string,
 *   hostname: string,
 *   mode_label_key: string,
 *   mode_tone: string,
 *   health_applicable: bool,
 *   status: string,
 *   is_local: bool,
 *   pool_https: string,
 *   message: string,
 *   tone: string,
 *   label: string,
 *   block_create: bool,
 *   source: string
 * }
 */
final class SmtpLocalDomainHealthService
{
    /** @var array<string, array<string, mixed>> */
    private array $tlsMemo = [];

    /** @var array<string, true> hostname keys filled via getSmtpTlsStatusBatch */
    private array $batchHit = [];

    /**
     * @param iterable<mixed> $services
     * @return array<int, SmtpLocalHealthSummary>
     */
    public function summarizeForServices(iterable $services): array
    {
        $items = [];
        foreach ($services as $svc) {
            if ($svc instanceof SmtpService) {
                $items[] = $svc;
            }
        }

        $hostnames = [];
        foreach ($items as $svc) {
            if ((string)$svc->getData(SmtpService::schema_fields_MODE) !== SmtpService::MODE_SELF) {
                continue;
            }
            $h = strtolower(trim((string)$svc->getData(SmtpService::schema_fields_SMTP_HOSTNAME)));
            if ($h !== '') {
                $hostnames[] = $h;
            }
        }
        $this->prefetchBatch($hostnames);

        $out = [];
        foreach ($items as $svc) {
            $id = (int)$svc->getId();
            if ($id <= 0) {
                continue;
            }
            $out[$id] = $this->summarizeFromService($svc);
        }

        return $out;
    }

    /**
     * @return SmtpLocalHealthSummary
     */
    public function summarizeFromService(SmtpService $svc): array
    {
        return $this->buildSummary(
            (int)$svc->getId(),
            (string)$svc->getData(SmtpService::schema_fields_MODE),
            (string)$svc->getData(SmtpService::schema_fields_SMTP_HOSTNAME),
        );
    }

    /**
     * 新建/启用预检（未落库时 smtp_service_id=0）。
     *
     * @return SmtpLocalHealthSummary
     */
    public function summarizePreview(string $mode, string $hostname): array
    {
        return $this->buildSummary(0, $mode, $hostname);
    }

    /**
     * @param list<string> $hostnames
     */
    private function prefetchBatch(array $hostnames): void
    {
        $need = [];
        foreach ($hostnames as $entry) {
            $h = strtolower(trim((string)$entry));
            if ($h === '' || isset($this->tlsMemo[$h])) {
                continue;
            }
            $need[] = $h;
        }
        if ($need === []) {
            return;
        }

        try {
            $batch = w_query('mail', 'getSmtpTlsStatusBatch', ['hostnames' => $need]);
            if (is_array($batch) && isset($batch['by_hostname']) && is_array($batch['by_hostname'])) {
                foreach ($batch['by_hostname'] as $key => $status) {
                    $k = strtolower(trim((string)$key));
                    if ($k === '' || !is_array($status)) {
                        continue;
                    }
                    $this->tlsMemo[$k] = $status;
                    $this->batchHit[$k] = true;
                }
                foreach ($need as $h) {
                    if (!isset($this->tlsMemo[$h])) {
                        $this->fetchTlsStatus($h);
                    }
                }

                return;
            }
        } catch (\Throwable) {
            // 单主机回退
        }

        foreach ($need as $h) {
            $this->fetchTlsStatus($h);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchTlsStatus(string $hostname): array
    {
        $hostname = strtolower(trim($hostname));
        if ($hostname === '') {
            return [
                'status' => 'error',
                'is_local' => false,
                'message' => (string)__('主机名为空'),
                'pool_https' => '',
                'success' => false,
            ];
        }
        if (isset($this->tlsMemo[$hostname])) {
            return $this->tlsMemo[$hostname];
        }

        try {
            $status = w_query('mail', 'getSmtpTlsStatus', ['hostname' => $hostname]);
            if (!is_array($status)) {
                $status = [
                    'status' => 'error',
                    'is_local' => false,
                    'message' => (string)__('TLS 状态查询失败'),
                    'pool_https' => '',
                    'success' => false,
                ];
            }
        } catch (\Throwable $e) {
            $status = [
                'status' => 'error',
                'is_local' => false,
                'message' => $e->getMessage(),
                'pool_https' => '',
                'success' => false,
            ];
        }

        $this->tlsMemo[$hostname] = $status;

        return $status;
    }

    /**
     * @return SmtpLocalHealthSummary
     */
    private function buildSummary(int $serviceId, string $mode, string $hostname): array
    {
        $mode = $mode === SmtpService::MODE_EXTERNAL
            ? SmtpService::MODE_EXTERNAL
            : SmtpService::MODE_SELF;
        $hostname = strtolower(trim($hostname));

        $base = [
            'smtp_service_id' => $serviceId,
            'mode' => $mode,
            'hostname' => $hostname,
            'mode_label_key' => $mode === SmtpService::MODE_SELF ? '本机登记' : '外部填入',
            'mode_tone' => $mode === SmtpService::MODE_SELF ? 'info' : 'primary',
            'health_applicable' => $mode === SmtpService::MODE_SELF,
            'status' => 'n/a',
            'is_local' => false,
            'pool_https' => '',
            'message' => '',
            'tone' => 'muted',
            'label' => '',
            'block_create' => false,
            'source' => 'n/a',
        ];

        if ($mode === SmtpService::MODE_EXTERNAL) {
            return $base;
        }

        if ($hostname === '') {
            $base['status'] = 'error';
            $base['tone'] = 'danger';
            $base['label'] = (string)__('证书同步失败');
            $base['message'] = (string)__('主机名为空');
            $base['block_create'] = true;

            return $base;
        }

        $tls = $this->fetchTlsStatus($hostname);
        $status = trim((string)($tls['status'] ?? ''));
        if ($status === '') {
            $status = 'error';
        }
        $isLocal = (bool)($tls['is_local'] ?? false);
        $message = trim((string)($tls['message'] ?? ''));
        $poolHttps = (string)($tls['pool_https'] ?? '');

        $tone = match ($status) {
            'ready' => 'success',
            'needs_dns', 'needs_cert' => 'warning',
            default => 'danger',
        };
        $label = match ($status) {
            'ready' => (string)__('证书已就绪'),
            'needs_dns' => (string)__('DNS 未指向本机'),
            'needs_cert' => (string)__('证书需要配置'),
            default => (string)__('证书同步失败'),
        };

        // G1 fail-closed：非本机或 needs_dns 禁止新建/启用
        $blockCreate = !$isLocal || $status === 'needs_dns';

        $base['status'] = $status;
        $base['is_local'] = $isLocal;
        $base['pool_https'] = $poolHttps;
        $base['message'] = $message !== '' ? $message : $label;
        $base['tone'] = $tone;
        $base['label'] = $label;
        $base['block_create'] = $blockCreate;
        $base['source'] = isset($this->batchHit[$hostname])
            ? 'batch'
            : 'mail.getSmtpTlsStatus';

        return $base;
    }
}
