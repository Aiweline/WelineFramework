<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Preload;

interface WorkerPreloadProviderInterface
{
    public function code(): string;

    public function phase(): string;

    public function priority(): int;

    /**
     * Provider codes that must run before this one in the same phase.
     *
     * Ordering rules:
     *   - Dependencies run first regardless of priority().
     *   - Otherwise the lower priority() runs first.
     *   - A dependency on an unknown/disabled code is ignored (that provider may
     *     be skipped in this runtime mode).
     *   - A dependency cycle falls back to priority order for the providers
     *     involved instead of dead-locking worker boot.
     *
     * Declare a dependency when this provider's warm-up reads data another
     * provider publishes into process memory — e.g. a theme provider depends on
     * the website directory provider, because theme scopes are resolved per
     * website and need the site catalogue in memory first.
     *
     * @return list<string>
     */
    public function dependsOn(): array;

    public function isEnabled(WorkerPreloadContext $context): bool;

    public function preload(WorkerPreloadContext $context): WorkerPreloadResult;

    /**
     * @return list<string>
     */
    public function invalidationKeys(): array;
}
