<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge;

/**
 * The endpoint-bound manifest is authentic, but project-owned certificate
 * authority has moved monotonically beyond it. Only native cold startup may
 * consume this signal, by discarding the old proof and rebuilding from the
 * current selector/tombstone.
 */
final class NativeServingManifestRebuildRequiredException extends \RuntimeException
{
    /** @var non-empty-list<array<string,mixed>> */
    public readonly array $transitions;

    /** @var list<string> */
    public readonly array $activeDomains;

    /**
     * @param non-empty-list<array<string,mixed>> $transitions
     * @param list<string> $activeDomains
     */
    public function __construct(
        array $transitions,
        array $activeDomains,
        \Throwable $previous,
    ) {
        $this->transitions = $transitions;
        $this->activeDomains = $activeDomains;
        parent::__construct(
            'The previous native WLS serving manifest is stale but safely rebuildable.',
            0,
            $previous,
        );
    }
}
