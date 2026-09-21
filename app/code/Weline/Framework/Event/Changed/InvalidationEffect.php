<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Changed;

/**
 * 单条失效 Effect；phase 决定 sync（事务内）或 after_commit。
 *
 * @param array<string, mixed> $payload
 */
final readonly class InvalidationEffect
{
    public const PHASE_SYNC = 'sync';
    public const PHASE_AFTER_COMMIT = 'after_commit';

    public const CODE_BUMP_NAMESPACES = 'bump_namespaces';
    public const CODE_PURGE_FPC_URLS = 'purge_fpc_urls';
    public const CODE_PURGE_FPC_ALL = 'purge_fpc_all';
    public const CODE_CDN_PURGE = 'cdn_purge';
    public const CODE_CACHE_OPS_DELETE = 'cache_ops_delete';
    public const CODE_THEME_RUNTIME_CLEAR = 'theme_runtime_clear';

    public function __construct(
        public string $code,
        public string $phase,
        public array $payload = [],
    ) {
        if (!in_array($this->phase, [self::PHASE_SYNC, self::PHASE_AFTER_COMMIT], true)) {
            throw new \InvalidArgumentException('InvalidationEffect phase 必须是 sync 或 after_commit');
        }
        if (trim($this->code) === '') {
            throw new \InvalidArgumentException('InvalidationEffect code 不能为空');
        }
    }
}
