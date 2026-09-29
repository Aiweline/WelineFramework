<?php
declare(strict_types=1);
namespace Weline\Cdn\Service;
use Weline\Framework\Controller\Extra\FpcPolicySnapshotProviderInterface;

/** 只加载已发布事实；缓存与请求冻结归 Framework 统一负责。 */
final class CompiledFpcPolicySnapshotProvider implements FpcPolicySnapshotProviderInterface
{
    public function snapshot(): array
    {
        $file = BP . '/generated/framework/fpc_policy_snapshot.php';
        clearstatcache(true, $file);
        if (!is_file($file)) { return []; }
        if (function_exists('opcache_invalidate')) { opcache_invalidate($file, true); }
        $value = require $file;
        if (!is_array($value) || ($value['schema_version'] ?? '') !== 'fpc-policy-snapshot.v1') {
            throw new \RuntimeException('cdn_fpc_snapshot_invalid');
        }
        return $value;
    }
}
