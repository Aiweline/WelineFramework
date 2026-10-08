<?php

declare(strict_types=1);

namespace Weline\Mail\Service;

/**
 * 企业邮箱 DNS 期望清单与线上探测（按记录内容校验，不是「有任意 TXT 即通过」）。
 */
class DnsRecordAdvisor
{
    /**
     * @return list<array{code:string,type:string,host:string,value:string,required:bool,optional?:bool}>
     */
    public function expectedRecords(string $domain, string $hostname, string $dkimSelector = 'default'): array
    {
        $domain = strtolower(trim($domain));
        $hostname = strtolower(trim($hostname));
        $selector = strtolower(trim($dkimSelector)) ?: 'default';

        return [
            [
                'code' => 'a',
                'type' => 'A',
                'host' => $hostname,
                'value' => 'origin IPv4/IPv6 · DNS-only',
                'required' => true,
            ],
            [
                'code' => 'mx',
                'type' => 'MX',
                'host' => $domain,
                'value' => '10 ' . $hostname,
                'required' => true,
            ],
            [
                'code' => 'spf',
                'type' => 'TXT',
                'host' => $domain,
                'value' => 'v=spf1 mx -all',
                'required' => true,
            ],
            [
                'code' => 'dkim',
                'type' => 'TXT',
                'host' => $selector . '._domainkey.' . $domain,
                'value' => 'v=DKIM1; k=rsa; p=…',
                'required' => true,
            ],
            [
                'code' => 'dmarc',
                'type' => 'TXT',
                'host' => '_dmarc.' . $domain,
                'value' => 'v=DMARC1; p=quarantine; rua=mailto:postmaster@' . $domain,
                'required' => true,
            ],
            [
                'code' => 'smtp_cname',
                'type' => 'CNAME',
                'host' => 'smtp.' . $domain,
                'value' => $hostname . ' · DNS-only',
                'required' => false,
                'optional' => true,
            ],
        ];
    }

    /**
     * @return array{
     *   domain:string,
     *   hostname:string,
     *   ok:bool,
     *   records:list<array{code:string,type:string,host:string,value:string,required:bool,optional?:bool,ok:bool,detail:string}>
     * }
     */
    public function check(string $domain, string $hostname, string $dkimSelector = 'default', string $originIp = ''): array
    {
        $records = $this->expectedRecords($domain, $hostname, $dkimSelector);
        $originIp = trim($originIp);
        foreach ($records as &$record) {
            $probe = $this->probeRecord($record, $hostname, $originIp);
            $record['ok'] = $probe['ok'];
            $record['detail'] = $probe['detail'];
        }
        unset($record);

        $requiredFailed = array_filter(
            $records,
            static fn(array $record): bool => !empty($record['required']) && empty($record['ok'])
        );

        return [
            'domain' => strtolower(trim($domain)),
            'hostname' => strtolower(trim($hostname)),
            'ok' => $requiredFailed === [],
            'records' => $records,
        ];
    }

    /**
     * @param array{code:string,type:string,host:string,value:string,required:bool,optional?:bool} $record
     * @return array{ok:bool,detail:string}
     */
    private function probeRecord(array $record, string $mailHostname, string $originIp): array
    {
        if (!function_exists('dns_get_record')) {
            return ['ok' => false, 'detail' => 'dns_get_record unavailable'];
        }

        $code = (string)$record['code'];
        $host = (string)$record['host'];

        return match ($code) {
            'a' => $this->probeA($host, $originIp),
            'mx' => $this->probeMx($host, $mailHostname),
            'spf' => $this->probeTxtContains($host, 'v=spf1'),
            'dkim' => $this->probeTxtContains($host, 'p='),
            'dmarc' => $this->probeTxtContains($host, 'v=DMARC1'),
            'smtp_cname' => $this->probeSmtpAlias($host, $mailHostname),
            default => ['ok' => false, 'detail' => 'unknown'],
        };
    }

    /**
     * @return array{ok:bool,detail:string}
     */
    private function probeA(string $host, string $originIp): array
    {
        $a = @dns_get_record($host, DNS_A) ?: [];
        $aaaa = @dns_get_record($host, DNS_AAAA) ?: [];
        $ips = [];
        foreach ($a as $row) {
            if (!empty($row['ip'])) {
                $ips[] = (string)$row['ip'];
            }
        }
        foreach ($aaaa as $row) {
            if (!empty($row['ipv6'])) {
                $ips[] = (string)$row['ipv6'];
            }
        }
        if ($ips === []) {
            return ['ok' => false, 'detail' => 'NXDOMAIN/empty'];
        }
        if ($originIp !== '' && !in_array($originIp, $ips, true)) {
            return ['ok' => false, 'detail' => implode(',', $ips) . ' ≠ ' . $originIp];
        }

        return ['ok' => true, 'detail' => implode(',', $ips)];
    }

    /**
     * @return array{ok:bool,detail:string}
     */
    private function probeMx(string $host, string $mailHostname): array
    {
        $rows = @dns_get_record($host, DNS_MX) ?: [];
        if ($rows === []) {
            return ['ok' => false, 'detail' => 'empty'];
        }
        $want = rtrim(strtolower($mailHostname), '.');
        $seen = [];
        foreach ($rows as $row) {
            $target = rtrim(strtolower((string)($row['target'] ?? '')), '.');
            $pri = (int)($row['pri'] ?? 0);
            if ($target === '') {
                continue;
            }
            $seen[] = $pri . ' ' . $target;
            if ($target === $want) {
                return ['ok' => true, 'detail' => $pri . ' ' . $target];
            }
        }

        return ['ok' => false, 'detail' => implode('; ', $seen)];
    }

    /**
     * @return array{ok:bool,detail:string}
     */
    private function probeTxtContains(string $host, string $needle): array
    {
        $texts = $this->lookupTxt($host);
        if ($texts === []) {
            return ['ok' => false, 'detail' => 'empty'];
        }
        $needle = strtolower($needle);
        foreach ($texts as $txt) {
            $lower = strtolower($txt);
            if ($lower !== '' && str_contains($lower, $needle)) {
                return ['ok' => true, 'detail' => substr($txt, 0, 96)];
            }
        }

        return ['ok' => false, 'detail' => 'no match'];
    }

    /**
     * @return array{ok:bool,detail:string}
     */
    private function probeSmtpAlias(string $host, string $mailHostname): array
    {
        $want = rtrim(strtolower($mailHostname), '.');
        foreach ($this->lookupCname($host) as $target) {
            if ($target === $want) {
                return ['ok' => true, 'detail' => 'CNAME ' . $target];
            }
        }
        $a = $this->probeA($host, '');
        if ($a['ok']) {
            return ['ok' => true, 'detail' => 'A ' . $a['detail']];
        }

        return ['ok' => false, 'detail' => 'empty'];
    }

    /**
     * 公网 TXT：优先 dig @1.1.1.1（规避 systemd-resolved 多 TXT 丢记录），再回退 dns_get_record。
     *
     * @return list<string>
     */
    private function lookupTxt(string $host): array
    {
        $out = [];
        foreach ($this->digShort('TXT', $host) as $line) {
            $txt = trim($line, " \t\"'");
            $txt = str_replace('" "', '', $txt);
            if ($txt !== '') {
                $out[] = $txt;
            }
        }
        if ($out !== []) {
            return array_values(array_unique($out));
        }
        $rows = @dns_get_record($host, DNS_TXT) ?: [];
        foreach ($rows as $row) {
            $txt = trim((string)($row['txt'] ?? ''));
            if ($txt !== '') {
                $out[] = $txt;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private function lookupCname(string $host): array
    {
        $out = [];
        foreach ($this->digShort('CNAME', $host) as $line) {
            $target = rtrim(strtolower(trim($line, " \t.\"'")), '.');
            if ($target !== '') {
                $out[] = $target;
            }
        }
        if ($out !== []) {
            return array_values(array_unique($out));
        }
        $rows = @dns_get_record($host, DNS_CNAME) ?: [];
        foreach ($rows as $row) {
            $target = rtrim(strtolower((string)($row['target'] ?? '')), '.');
            if ($target !== '') {
                $out[] = $target;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private function digShort(string $type, string $host): array
    {
        if (!function_exists('exec')) {
            return [];
        }
        $type = strtoupper(preg_replace('/[^A-Z0-9]/', '', $type) ?? '');
        $host = strtolower(rtrim(trim($host), '.'));
        if ($type === '' || $host === '' || !preg_match('/^[a-z0-9._-]+$/', $host)) {
            return [];
        }
        $cmd = 'dig +short +time=2 +tries=1 ' . escapeshellarg($type) . ' '
            . escapeshellarg($host) . ' @1.1.1.1 2>/dev/null';
        $lines = [];
        $code = 1;
        @exec($cmd, $lines, $code);
        if ($code !== 0 || !is_array($lines)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $lines), static fn(string $l): bool => $l !== ''));
    }
}
