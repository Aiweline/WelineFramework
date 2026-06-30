<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

class DnsRecordAdvisor
{
    public function expectedRecords(string $domain, string $hostname): array
    {
        return [
            [
                'type' => 'MX',
                'host' => $domain,
                'value' => '10 ' . $hostname,
                'required' => true,
            ],
            [
                'type' => 'TXT',
                'host' => $domain,
                'value' => 'v=spf1 mx -all',
                'required' => true,
            ],
            [
                'type' => 'TXT',
                'host' => '_dmarc.' . $domain,
                'value' => 'v=DMARC1; p=quarantine; rua=mailto:postmaster@' . $domain,
                'required' => true,
            ],
            [
                'type' => 'TXT',
                'host' => 'default._domainkey.' . $domain,
                'value' => __('由邮件引擎生成 DKIM 公钥后填写'),
                'required' => true,
            ],
        ];
    }

    public function check(string $domain, string $hostname): array
    {
        $records = $this->expectedRecords($domain, $hostname);
        foreach ($records as &$record) {
            $record['ok'] = $this->recordLooksPresent($record);
        }
        unset($record);

        return [
            'domain' => $domain,
            'hostname' => $hostname,
            'ok' => count(array_filter($records, static fn(array $record): bool => $record['required'] && !$record['ok'])) === 0,
            'records' => $records,
        ];
    }

    private function recordLooksPresent(array $record): bool
    {
        if (!function_exists('dns_get_record')) {
            return false;
        }

        $type = strtoupper((string)$record['type']);
        $host = (string)$record['host'];
        $dnsType = match ($type) {
            'MX' => DNS_MX,
            'TXT' => DNS_TXT,
            default => DNS_ALL,
        };

        $records = @dns_get_record($host, $dnsType);
        return is_array($records) && !empty($records);
    }
}
