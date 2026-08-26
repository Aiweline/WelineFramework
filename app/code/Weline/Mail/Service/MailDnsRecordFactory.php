<?php

declare(strict_types=1);

namespace Weline\Mail\Service;

/**
 * Builds only real, user-confirmed mail DNS targets. It never invents a DKIM key.
 */
final class MailDnsRecordFactory
{
    /**
     * @return array{desired_records: array<int, array<string, mixed>>, dns_only_hosts: array<int, string>}
     */
    public function build(
        string $domain,
        string $hostname,
        string $originIp,
        string $dkimSelector,
        string $dkimPublicKey,
    ): array {
        $domain = strtolower(rtrim(trim($domain), '.'));
        $hostname = strtolower(rtrim(trim($hostname), '.'));
        $selector = strtolower(trim($dkimSelector));

        if (!filter_var('postmaster@' . $domain, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException((string)__('邮箱域名格式无效。'));
        }
        if (
            !filter_var('postmaster@' . $hostname, FILTER_VALIDATE_EMAIL)
            || !str_ends_with($hostname, '.' . $domain)
        ) {
            throw new \DomainException((string)__('邮件服务主机名必须属于当前邮箱域名。'));
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $selector)) {
            throw new \DomainException((string)__('DKIM 选择器格式无效。'));
        }

        $dkim = $this->normalizeDkim($dkimPublicKey);
        $records = [
            [
                'type' => 'MX',
                'name' => $domain,
                'content' => $hostname,
                'priority' => 10,
                'ttl' => 1,
            ],
            [
                'type' => 'TXT',
                'name' => $domain,
                'content' => 'v=spf1 mx -all',
                'ttl' => 1,
            ],
            [
                'type' => 'TXT',
                'name' => $selector . '._domainkey.' . $domain,
                'content' => $dkim,
                'ttl' => 1,
            ],
            [
                'type' => 'TXT',
                'name' => '_dmarc.' . $domain,
                'content' => 'v=DMARC1; p=quarantine; rua=mailto:postmaster@' . $domain . '; adkim=s; aspf=s',
                'ttl' => 1,
            ],
        ];

        $originIp = trim($originIp);
        if ($originIp !== '') {
            $valid = filter_var(
                $originIp,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );
            if ($valid === false) {
                throw new \DomainException((string)__('源站 IP 必须是可公开路由的 IPv4 或 IPv6。'));
            }
            $records[] = [
                'type' => str_contains($originIp, ':') ? 'AAAA' : 'A',
                'name' => $hostname,
                'content' => $originIp,
                'ttl' => 1,
                'proxied' => false,
            ];
        }

        return [
            'desired_records' => $records,
            'dns_only_hosts' => [$hostname],
        ];
    }

    private function normalizeDkim(string $value): string
    {
        $value = trim(preg_replace('/"\s*"/', '', trim($value)) ?? '', " \t\n\r\0\x0B\"");
        if (
            $value === ''
            || stripos($value, 'PRIVATE KEY') !== false
            || stripos($value, 'example') !== false
            || stripos($value, 'replace') !== false
        ) {
            throw new \DomainException(
                (string)__('必须填写 Stalwart 实际生成的 DKIM 公钥，禁止填写私钥或示例值。')
            );
        }

        if (stripos($value, 'v=DKIM1') === 0) {
            if (!preg_match('/(?:^|;)\s*p\s*=\s*([A-Za-z0-9+\/=]+)/i', $value, $match)) {
                throw new \DomainException((string)__('DKIM TXT 缺少有效的 p= 公钥。'));
            }
            $key = $match[1];
            $record = $value;
        } else {
            $key = preg_replace('/[\s"]+/', '', $value) ?? '';
            $record = 'v=DKIM1; k=rsa; p=' . $key;
        }

        $decoded = base64_decode($key, true);
        if ($decoded === false || strlen($decoded) < 32) {
            throw new \DomainException((string)__('DKIM 公钥不是有效的 Base64 公钥。'));
        }

        return $record;
    }
}
