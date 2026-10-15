<?php
declare(strict_types=1);

namespace Weline\Smtp\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Smtp\Helper\Data;

/**
 * Smtp 独立启用：池选或自填域名 → DNS 解析门禁 → 统一入域名池出证。
 */
final class SmtpServiceEnableService
{
    public function __construct(
        private readonly ?Data $data = null,
    ) {
    }

    private function data(): Data
    {
        return $this->data ?? ObjectManager::getInstance(Data::class);
    }

    /**
     * @return array{
     *   success: bool,
     *   message: string,
     *   enabled: bool,
     *   domain_name: string,
     *   smtp_hostname: string,
     *   pool?: array<string, mixed>
     * }
     */
    public function enable(
        string $domainInput,
        string $scope = 'default.default.default',
        string $expectedIp = '',
        bool $relayService = false,
    ): array {
        $scope = $this->data()->resolveScope($scope);
        $parsed = $this->normalizeDomainInput($domainInput);
        if ($parsed['root'] === '') {
            return $this->fail(__('请选择或填写 Smtp 域名'), false, '', '');
        }
        $root = $parsed['root'];
        $smtpHost = $parsed['smtp_hostname'];
        $dns = $this->assertDnsResolved($smtpHost, $expectedIp);
        if (empty($dns['success'])) {
            return $this->fail(
                (string)($dns['message'] ?? __('DNS 未解析通过，不能入池')),
                false,
                $root,
                $smtpHost
            );
        }

        $originIp = trim($expectedIp);
        if ($originIp === '') {
            $originIp = (string)($dns['resolved_ip'] ?? '');
        }

        $pool = null;
        try {
            $pool = w_query('websites', 'ensurePublicDnsHostnamesInPool', [
                'root_domain' => $root,
                'hostnames' => [$smtpHost],
                'origin_ip' => $originIp,
                'request_certificate' => true,
                'source' => 'smtp_enable',
            ]);
        } catch (\Throwable $e) {
            return $this->fail(
                (string)__('入域名池失败：%{1}', [$e->getMessage()]),
                false,
                $root,
                $smtpHost
            );
        }
        if (!is_array($pool) || empty($pool['success'])) {
            return $this->fail(
                (string)($pool['message'] ?? __('入域名池失败')),
                false,
                $root,
                $smtpHost
            );
        }

        $this->data()->setServiceState([
            'enabled' => '1',
            'domain_name' => $root,
            'smtp_hostname' => $smtpHost,
            'relay_service' => $relayService ? '1' : '0',
            'pool_domain_id' => (string)(int)($pool['domain_id'] ?? 0),
        ], $scope);

        return [
            'success' => true,
            'message' => (string)__('Smtp 已启用：DNS 已通并已入域名池申请证书'),
            'enabled' => true,
            'domain_name' => $root,
            'smtp_hostname' => $smtpHost,
            'pool' => $pool,
        ];
    }

    /**
     * @return array{success: bool, message: string, enabled: bool, domain_name: string, smtp_hostname: string}
     */
    public function disable(string $scope = 'default.default.default'): array
    {
        $scope = $this->data()->resolveScope($scope);
        $state = $this->data()->getServiceState($scope);
        $this->data()->setServiceState([
            'enabled' => '0',
            'domain_name' => (string)($state['domain_name'] ?? ''),
            'smtp_hostname' => (string)($state['smtp_hostname'] ?? ''),
            'relay_service' => '0',
            'pool_domain_id' => (string)($state['pool_domain_id'] ?? '0'),
        ], $scope);

        return [
            'success' => true,
            'message' => (string)__('Smtp 服务已关闭（域名池记录保留；账户停止新建）'),
            'enabled' => false,
            'domain_name' => (string)($state['domain_name'] ?? ''),
            'smtp_hostname' => (string)($state['smtp_hostname'] ?? ''),
        ];
    }

    /**
     * @return array{root: string, smtp_hostname: string}
     */
    public function normalizeDomainInput(string $input): array
    {
        $input = strtolower(trim($input));
        $input = preg_replace('#^https?://#', '', $input) ?? $input;
        $input = rtrim($input, '.');
        if ($input === '') {
            return ['root' => '', 'smtp_hostname' => ''];
        }
        if (str_starts_with($input, 'smtp.')) {
            $root = substr($input, 5);

            return ['root' => $root, 'smtp_hostname' => $input];
        }

        return ['root' => $input, 'smtp_hostname' => 'smtp.' . $input];
    }

    /**
     * @return array{success: bool, message: string, resolved_ip?: string}
     */
    public function assertDnsResolved(string $hostname, string $expectedIp = ''): array
    {
        $hostname = strtolower(trim($hostname));
        if ($hostname === '') {
            return ['success' => false, 'message' => (string)__('主机名为空')];
        }
        $ips = [];
        $a = @dns_get_record($hostname, DNS_A) ?: [];
        foreach ($a as $row) {
            if (!empty($row['ip'])) {
                $ips[] = (string)$row['ip'];
            }
        }
        $aaaa = @dns_get_record($hostname, DNS_AAAA) ?: [];
        foreach ($aaaa as $row) {
            if (!empty($row['ipv6'])) {
                $ips[] = (string)$row['ipv6'];
            }
        }
        if ($ips === []) {
            $cname = @dns_get_record($hostname, DNS_CNAME) ?: [];
            foreach ($cname as $row) {
                $target = strtolower(rtrim((string)($row['target'] ?? ''), '.'));
                if ($target === '') {
                    continue;
                }
                $nested = $this->assertDnsResolved($target, $expectedIp);
                if (!empty($nested['success'])) {
                    return $nested;
                }
            }

            return [
                'success' => false,
                'message' => (string)__('主机 %{1} 尚未解析到 A/AAAA，不能入域名池', [$hostname]),
            ];
        }
        $expectedIp = trim($expectedIp);
        if ($expectedIp !== '' && !in_array($expectedIp, $ips, true)) {
            return [
                'success' => false,
                'message' => (string)__(
                    '主机 %{1} 解析到 %{2}，与预期 %{3} 不符',
                    [$hostname, implode(',', $ips), $expectedIp]
                ),
                'resolved_ip' => $ips[0],
            ];
        }

        return [
            'success' => true,
            'message' => (string)__('DNS 已解析'),
            'resolved_ip' => $ips[0],
        ];
    }

    /**
     * @return array{success: bool, message: string, enabled: bool, domain_name: string, smtp_hostname: string}
     */
    private function fail(string $message, bool $enabled, string $root, string $smtpHost): array
    {
        return [
            'success' => false,
            'message' => $message,
            'enabled' => $enabled,
            'domain_name' => $root,
            'smtp_hostname' => $smtpHost,
        ];
    }
}
