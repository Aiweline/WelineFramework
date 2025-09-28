<?php
declare(strict_types=1);

namespace Weline\Ai\Service;

/**
 * 安全扫描服务
 */
class SecurityScanService
{
    public function performScan(string $content): array
    {
        return ['safe' => true];
    }
}
