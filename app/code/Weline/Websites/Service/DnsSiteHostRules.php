<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

/**
 * 哪些 DNS 主机名代表「验证/邮件」等非建站用途，不应入域名池、不应参与自动解析写 A。
 */
final class DnsSiteHostRules
{
    public static function isUnderscoreTechnicalDnsHost(string $host): bool
    {
        $h = \strtolower(\trim($host));
        if ($h === '' || $h === '@') {
            return false;
        }
        foreach (\explode('.', \rtrim($h, '.')) as $label) {
            if ($label !== '' && \str_starts_with($label, '_')) {
                return true;
            }
        }

        return false;
    }

    public static function isFqdnTechnicalNonSitePool(string $fqdn, string $rootDomain): bool
    {
        $fqdn = \strtolower(\trim($fqdn));
        $root = \strtolower(\trim($rootDomain));
        if ($fqdn === '' || $root === '' || $fqdn === $root) {
            return false;
        }
        $suffix = '.' . $root;
        if (!\str_ends_with($fqdn, $suffix)) {
            return false;
        }
        $rel = \substr($fqdn, 0, -\strlen($suffix));

        return self::isUnderscoreTechnicalDnsHost($rel);
    }
}
