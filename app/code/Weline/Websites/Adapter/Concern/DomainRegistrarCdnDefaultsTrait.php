<?php

declare(strict_types=1);

namespace Weline\Websites\Adapter\Concern;

/**
 * CDN/边缘：默认不修改记录、不提供 API 级校验（非 Cloudflare 类供应商）。
 *
 * @see \Weline\Websites\Api\DomainRegistrarInterface::applyCdnSettingsToDnsRecords()
 * @see \Weline\Websites\Api\DomainRegistrarInterface::verifyCdnConfiguration()
 */
trait DomainRegistrarCdnDefaultsTrait
{
    /**
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    public function applyCdnSettingsToDnsRecords(string $domain, array $records): array
    {
        unset($domain);

        return $records;
    }

    /**
     * @return array{supported: bool, ok: bool, message: string}
     */
    public function verifyCdnConfiguration(string $domain, array $credentials): array
    {
        unset($domain, $credentials);

        return [
            'supported' => false,
            'ok' => true,
            'message' => '',
        ];
    }
}
