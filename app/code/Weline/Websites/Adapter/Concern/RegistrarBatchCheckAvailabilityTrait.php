<?php
declare(strict_types=1);

namespace Weline\Websites\Adapter\Concern;

/**
 * 批量查可用性：逐条调用 checkAvailability（无批量 API 时共用）。
 */
trait RegistrarBatchCheckAvailabilityTrait
{
    public function batchCheckAvailability(array $domains, array $credentials): array
    {
        $results = [];
        foreach ($domains as $domain) {
            $results[] = $this->checkAvailability(\trim((string) $domain), $credentials);
        }

        return $results;
    }
}
