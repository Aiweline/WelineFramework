<?php
declare(strict_types=1);
namespace Weline\Cdn\Service;
use Weline\Framework\Compilation\CompiledPhpRegistry;
use Weline\Framework\Controller\Extra\FpcPolicySnapshotProviderInterface;
use Weline\Framework\Runtime\Runtime;

/** 只加载已发布事实；缓存与请求冻结归 Framework 统一负责。 */
final class CompiledFpcPolicySnapshotProvider implements FpcPolicySnapshotProviderInterface
{
    public function snapshot(): array
    {
        $file = BP . 'generated' . DS . 'framework' . DS . 'fpc_policy_snapshot.php';
        // CLI publish may rewrite the sidecar; persistent Workers never re-include.
        if (!Runtime::isPersistent()) {
            \clearstatcache(true, $file);
            if (\function_exists('opcache_invalidate') && \is_file($file)) {
                @\opcache_invalidate($file, true);
            }
            CompiledPhpRegistry::forgetForTests($file);
        }
        $value = CompiledPhpRegistry::load($file);
        if ($value === null) {
            return [];
        }
        if (!\is_array($value) || ($value['schema_version'] ?? '') !== 'fpc-policy-snapshot.v1') {
            throw new \RuntimeException('cdn_fpc_snapshot_invalid');
        }
        return $value;
    }
}
