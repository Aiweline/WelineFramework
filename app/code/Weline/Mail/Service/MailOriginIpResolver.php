<?php

declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;

/**
 * 解析邮件源站公网 IP：域名已存值优先，否则本机 env / ServerIpService。
 * 本机即邮局时清单应直接展示具体 IP，而不是「源站公网 IP」占位。
 */
final class MailOriginIpResolver
{
    public function resolve(string $storedOriginIp = ''): string
    {
        $stored = trim($storedOriginIp);
        if ($stored !== '' && $this->isIpv4($stored)) {
            return $stored;
        }

        foreach ([
            Env::get('wls.public_ip'),
            Env::get('server.public_ip'),
        ] as $envIp) {
            $ip = trim((string)$envIp);
            if ($ip !== '' && $this->isIpv4($ip)) {
                return $ip;
            }
        }

        if (class_exists(\Weline\Websites\Service\ServerIpService::class)) {
            try {
                /** @var \Weline\Websites\Service\ServerIpService $svc */
                $svc = ObjectManager::getInstance(\Weline\Websites\Service\ServerIpService::class);
                // 邮局 DNS 必须用本机当前公网 IP；DomainConfig 缓存可能是切机前旧值 → 强制刷新
                $ip = trim($svc->getPublicIpv4(true));
                if ($ip !== '' && $this->isIpv4($ip)) {
                    return $ip;
                }
            } catch (\Throwable) {
                // optional dependency path
            }
        }

        return '';
    }

    private function isIpv4(string $ip): bool
    {
        return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    }
}
