<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Framework\Runtime\Policy\RuntimePolicyBundle;

/**
 * Worker 进程内的路由策略注册表。
 *
 * 说明：
 * - 策略由 Master 通过 IPC 下发；
 * - 本类只保存进程级只读快照，不保存请求级状态；
 * - 未下发策略时使用内建默认值，保证零配置可用。
 */
class RoutingPolicyRegistry
{
    public const POLICY_TRANSITION_IDLE = 'idle';
    public const POLICY_TRANSITION_PREPARING = 'preparing';
    public const POLICY_TRANSITION_PREPARED = 'prepared';
    public const POLICY_TRANSITION_ACTIVATED = 'activated';

    /**
     * 当前进程的路由策略快照。
     *
     * @var array<string, mixed>|null
     */
    private static ?array $policy = null;

    private static ?RuntimePolicyBundle $activeBundle = null;
    private static ?RuntimePolicyBundle $stagedBundle = null;
    private static ?RuntimePolicyBundle $previousBundle = null;
    /** @var array<string, mixed>|null */
    private static ?array $activeBundleArray = null;
    /** @var array<string, mixed>|null */
    private static ?array $stagedBundleArray = null;
    /** @var array<string, mixed>|null */
    private static ?array $previousBundleArray = null;

    /**
     * Process-local publication barrier. While this state is not idle the
     * transport adapters must stop starting new application work. Existing
     * requests may continue so PREPARE can acknowledge only after they drain.
     */
    private static string $policyTransitionState = self::POLICY_TRANSITION_IDLE;
    private static string $policyTransitionDigest = '';
    private static string $policyTransitionPreviousDigest = '';
    private static bool $policyPreparedAckPending = false;

    /**
     * 写入/更新策略快照。
     *
     * @param array<string, mixed> $policy
     */
    public static function update(array $policy): void
    {
        self::$policy = $policy;
    }

    /**
     * 获取完整策略快照。
     *
     * @return array<string, mixed>|null
     */
    public static function getPolicy(): ?array
    {
        return self::$policy;
    }

    /**
     * Validate and stage a complete immutable policy bundle without exposing it
     * to request handling. Activation is a separate atomic pointer swap.
     *
     * @param RuntimePolicyBundle|array<string, mixed> $bundle
     */
    public static function prepare(RuntimePolicyBundle|array $bundle): bool
    {
        if (\is_array($bundle)) {
            $bundle = RuntimePolicyBundle::fromArray($bundle);
        }
        self::$stagedBundle = $bundle;
        self::$stagedBundleArray = $bundle->toArray();
        return true;
    }

    /**
     * Validate/stage a policy and close the application admission gate.
     *
     * This is intentionally separate from prepare(): startup bootstrapping
     * installs an already-active immutable bundle and must not enter a live
     * publication barrier.
     *
     * @param RuntimePolicyBundle|array<string, mixed> $bundle
     */
    public static function beginPolicyTransition(RuntimePolicyBundle|array $bundle): string
    {
        if (\is_array($bundle)) {
            $bundle = RuntimePolicyBundle::fromArray($bundle);
        }
        if (self::$policyTransitionState !== self::POLICY_TRANSITION_IDLE) {
            if (\hash_equals(self::$policyTransitionDigest, $bundle->digest)) {
                if (self::$policyTransitionState === self::POLICY_TRANSITION_PREPARED) {
                    self::$policyPreparedAckPending = true;
                }
                return self::$policyTransitionDigest;
            }
            throw new \RuntimeException('Another process-local runtime policy transition is already in progress.');
        }

        self::$policyTransitionPreviousDigest = self::getActiveDigest();
        self::$policyTransitionDigest = $bundle->digest;
        self::$policyTransitionState = self::POLICY_TRANSITION_PREPARING;
        self::$policyPreparedAckPending = true;
        self::prepare($bundle);

        return $bundle->digest;
    }

    /**
     * Move PREPARE to PREPARED only after all application work is proven idle.
     * The returned digest is single-consumer: callers send exactly one ACK.
     */
    public static function takePreparedDigestIfDrained(
        int $activeRequests,
        int $activeFibers = 0,
        int $pendingResponses = 0,
    ): ?string
    {
        if (self::$policyTransitionState === self::POLICY_TRANSITION_PREPARING
            && $activeRequests <= 0
            && $activeFibers <= 0
            && $pendingResponses <= 0
        ) {
            self::$policyTransitionState = self::POLICY_TRANSITION_PREPARED;
        }
        if (self::$policyTransitionState !== self::POLICY_TRANSITION_PREPARED
            || !self::$policyPreparedAckPending
        ) {
            return null;
        }

        self::$policyPreparedAckPending = false;
        return self::$policyTransitionDigest;
    }

    /**
     * Atomically swap the staged bundle while keeping admission closed.
     */
    public static function activatePreparedPolicy(string $digest): bool
    {
        $digest = \strtolower(\trim($digest));
        if (self::$policyTransitionState === self::POLICY_TRANSITION_ACTIVATED) {
            return $digest !== '' && \hash_equals(self::getActiveDigest(), $digest);
        }
        if (self::$policyTransitionState !== self::POLICY_TRANSITION_PREPARED
            || $digest === ''
            || !\hash_equals(self::$policyTransitionDigest, $digest)
            || !self::activate($digest)
        ) {
            return false;
        }

        self::$policyTransitionState = self::POLICY_TRANSITION_ACTIVATED;
        return true;
    }

    /**
     * Release the admission gate only after Master has observed every
     * participant's activation ACK.
     */
    public static function commitPolicyTransition(string $digest): bool
    {
        $digest = \strtolower(\trim($digest));
        if (self::$policyTransitionState === self::POLICY_TRANSITION_IDLE) {
            return $digest !== '' && \hash_equals(self::getActiveDigest(), $digest);
        }
        if (self::$policyTransitionState !== self::POLICY_TRANSITION_ACTIVATED
            || $digest === ''
            || !\hash_equals(self::$policyTransitionDigest, $digest)
            || !\hash_equals(self::getActiveDigest(), $digest)
        ) {
            return false;
        }

        self::resetPolicyTransitionState();
        return true;
    }

    /**
     * Abort a failed barrier and reopen the previous policy. Before ACTIVATE
     * this only discards the staged candidate; after ACTIVATE it swaps back to
     * the previous immutable bundle before reopening admission.
     */
    public static function abortPolicyTransition(?string $digest = null): bool
    {
        $targetDigest = \strtolower(\trim((string)$digest));
        if ($targetDigest === '') {
            $targetDigest = self::$policyTransitionPreviousDigest;
        }
        if (self::$policyTransitionState === self::POLICY_TRANSITION_IDLE) {
            return $targetDigest === '' || \hash_equals(self::getActiveDigest(), $targetDigest);
        }

        if ($targetDigest !== '' && !\hash_equals(self::getActiveDigest(), $targetDigest)) {
            if (!self::rollback($targetDigest) || !\hash_equals(self::getActiveDigest(), $targetDigest)) {
                return false;
            }
        }
        self::$stagedBundle = null;
        self::$stagedBundleArray = null;
        self::resetPolicyTransitionState();
        return true;
    }

    public static function isApplicationGateOpen(): bool
    {
        return self::$policyTransitionState === self::POLICY_TRANSITION_IDLE;
    }

    public static function getPolicyTransitionState(): string
    {
        return self::$policyTransitionState;
    }

    public static function getPolicyTransitionDigest(): string
    {
        return self::$policyTransitionDigest;
    }

    public static function activate(string $digest): bool
    {
        $digest = \strtolower(\trim($digest));
        $staged = self::$stagedBundle;
        if ($staged === null || !\hash_equals($staged->digest, $digest)) {
            return false;
        }
        if (self::$activeBundle !== null && !\hash_equals(self::$activeBundle->digest, $digest)) {
            self::$previousBundle = self::$activeBundle;
            self::$previousBundleArray = self::$activeBundleArray;
        }
        self::$activeBundle = $staged;
        self::$activeBundleArray = self::$stagedBundleArray;
        self::$stagedBundle = null;
        self::$stagedBundleArray = null;
        return true;
    }

    public static function rollback(?string $digest = null): bool
    {
        $target = self::$previousBundle;
        if ($digest !== null && $digest !== '') {
            foreach ([self::$previousBundle, self::$stagedBundle, self::$activeBundle] as $candidate) {
                if ($candidate !== null && \hash_equals($candidate->digest, \strtolower(\trim($digest)))) {
                    $target = $candidate;
                    break;
                }
            }
        }
        if ($target === null) {
            return false;
        }
        $current = self::$activeBundle;
        $currentArray = self::$activeBundleArray;
        self::$activeBundle = $target;
        self::$activeBundleArray = $target->toArray();
        self::$previousBundle = $current !== null && !\hash_equals($current->digest, $target->digest)
            ? $current
            : null;
        self::$previousBundleArray = self::$previousBundle !== null ? $currentArray : null;
        self::$stagedBundle = null;
        self::$stagedBundleArray = null;
        return true;
    }

    public static function getActiveBundle(): ?RuntimePolicyBundle
    {
        return self::$activeBundle;
    }

    /**
     * Worker hot-path entry for pre-indexed descriptor data.
     *
     * @return array<string, mixed>|null
     */
    public static function getActiveBundleArray(): ?array
    {
        return self::$activeBundleArray;
    }

    public static function getActiveDigest(): string
    {
        return self::$activeBundle?->digest ?? '';
    }

    public static function getStagedDigest(): string
    {
        return self::$stagedBundle?->digest ?? '';
    }

    /**
     * Session: file 驱动是否接管到 wls。
     */
    public static function shouldHijackSessionFile(): bool
    {
        $value = self::$policy['routing']['session']['hijack_file_driver'] ?? true;
        return (bool)$value;
    }

    /**
     * Cache: file 驱动是否接管到 wls_memory。
     */
    public static function shouldHijackCacheFile(): bool
    {
        $value = self::$policy['routing']['cache']['hijack_file_driver'] ?? true;
        return (bool)$value;
    }

    /**
     * 获取 Session 服务端点。
     *
     * @return array{host: string, port: int}
     */
    public static function getSessionEndpoint(): array
    {
        $host = (string)(self::$policy['endpoints']['session']['host'] ?? '127.0.0.1');
        // 默认端口 19970 + 项目偏移量，确保多项目不冲突
        $defaultPort = 19970 + \Weline\Server\Service\MasterProcess::getProjectPortOffset();
        $port = (int)(self::$policy['endpoints']['session']['port'] ?? $defaultPort);
        return ['host' => $host, 'port' => $port > 0 ? $port : $defaultPort];
    }

    /**
     * 获取 Memory 服务端点。
     *
     * @return array{host: string, port: int}
     */
    public static function getMemoryEndpoint(): array
    {
        $host = (string)(self::$policy['endpoints']['memory']['host'] ?? '127.0.0.1');
        // 默认端口 19971 + 项目偏移量，确保多项目不冲突
        $defaultPort = 19971 + \Weline\Server\Service\MasterProcess::getProjectPortOffset();
        $port = (int)(self::$policy['endpoints']['memory']['port'] ?? $defaultPort);
        return ['host' => $host, 'port' => $port > 0 ? $port : $defaultPort];
    }

    /**
     * 测试/调试辅助：清空快照。
     */
    public static function clear(): void
    {
        self::$policy = null;
        self::$activeBundle = null;
        self::$stagedBundle = null;
        self::$previousBundle = null;
        self::$activeBundleArray = null;
        self::$stagedBundleArray = null;
        self::$previousBundleArray = null;
        self::resetPolicyTransitionState();
    }

    private static function resetPolicyTransitionState(): void
    {
        self::$policyTransitionState = self::POLICY_TRANSITION_IDLE;
        self::$policyTransitionDigest = '';
        self::$policyTransitionPreviousDigest = '';
        self::$policyPreparedAckPending = false;
    }
}
