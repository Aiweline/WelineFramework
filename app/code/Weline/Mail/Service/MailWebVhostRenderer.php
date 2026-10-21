<?php

declare(strict_types=1);

namespace Weline\Mail\Service;

final class MailWebVhostRenderer
{
    public function render(string $hostname, string $certPath, string $keyPath, int $listenPort): string
    {
        $hostname = strtolower(trim($hostname));
        $certPath = trim($certPath);
        $keyPath = trim($keyPath);

        if (!$this->isValidHostname($hostname)) {
            throw new \InvalidArgumentException('hostname invalid');
        }
        if ($certPath === '') {
            throw new \InvalidArgumentException('cert path empty');
        }
        if ($listenPort < 1 || $listenPort > 65535) {
            throw new \InvalidArgumentException('listen port invalid');
        }

        return implode("\n", [
            'server {',
            "    listen {$listenPort} ssl;",
            '    http2 on;',
            "    server_name {$hostname};",
            "    ssl_certificate {$certPath};",
            "    ssl_certificate_key {$keyPath};",
            '    ssl_protocols TLSv1.2 TLSv1.3;',
            '',
            '    location / {',
            '        proxy_pass https://127.0.0.1:8443;',
            "        proxy_ssl_name {$hostname};",
            '        proxy_set_header Host $host;',
            '        proxy_set_header X-Forwarded-Proto https;',
            '        proxy_set_header Upgrade $http_upgrade;',
            '        proxy_set_header Connection "";',
            '        proxy_read_timeout 300s;',
            '        proxy_send_timeout 300s;',
            '    }',
            '}',
        ]) . "\n";
    }

    public function fileName(string $hostname): string
    {
        $hostname = strtolower(trim($hostname));
        if (!$this->isValidHostname($hostname)) {
            throw new \InvalidArgumentException('hostname invalid');
        }

        return 'mail-' . substr(hash('sha256', $hostname), 0, 16) . '.conf';
    }

    public function listenPortFromNginxConf(string $conf): int
    {
        if (!preg_match_all('/listen\s+([0-9]+)\s+ssl\s*;/', $conf, $matches)) {
            return 443;
        }

        $port = (int) end($matches[1]);
        return ($port >= 1 && $port <= 65535) ? $port : 443;
    }

    private function isValidHostname(string $hostname): bool
    {
        return (bool) preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $hostname)
            && str_contains($hostname, '.');
    }
}
