<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * A previous serving manifest is well-formed but has been superseded by one
 * proven monotonic certificate-authority transition.
 *
 * This signal is intentionally reserved for native WLS cold recovery.
 * currentForFence() and every live listener path continue to reject stale
 * manifests strictly.
 */
final class ServingManifestAuthorityTransitionException extends \RuntimeException
{
    public const STALE_REBUILDABLE = 'STALE_REBUILDABLE';
    public const TOMBSTONED = 'TOMBSTONED';

    /** @var non-empty-list<array<string,mixed>> */
    public readonly array $transitions;

    /** @var list<string> */
    public readonly array $activeDomains;

    private readonly string $transitionState;

    /**
     * @param non-empty-list<array<string,mixed>> $transitions
     * @param list<string> $activeDomains
     */
    public function __construct(
        array $transitions,
        array $activeDomains,
    ) {
        if (!\array_is_list($transitions)
            || $transitions === []
            || \count($transitions) > ProjectServingManifestStore::MAX_ROUTES
            || !\array_is_list($activeDomains)
            || \count($activeDomains) > ProjectServingManifestStore::MAX_ROUTES
        ) {
            throw new \InvalidArgumentException(
                'Serving manifest authority transition set is invalid.',
            );
        }
        $hasTombstone = false;
        foreach ($transitions as $transition) {
            $reason = \is_array($transition)
                ? (string)($transition['reason'] ?? '')
                : '';
            if (!\in_array($reason, [
                'active_advanced',
                'tombstoned',
                'explicitly_reenabled',
            ], true)) {
                throw new \InvalidArgumentException(
                    'Serving manifest authority transition reason is invalid.',
                );
            }
            $hasTombstone = $hasTombstone || $reason === 'tombstoned';
        }
        $uniqueActiveDomains = [];
        foreach ($activeDomains as $activeDomain) {
            if (!\is_string($activeDomain) || $activeDomain === '') {
                throw new \InvalidArgumentException(
                    'Serving manifest active transition domain is invalid.',
                );
            }
            $uniqueActiveDomains[$activeDomain] = true;
        }
        \ksort($uniqueActiveDomains, SORT_STRING);
        $this->transitions = $transitions;
        $this->activeDomains = \array_keys($uniqueActiveDomains);
        $this->transitionState = $hasTombstone
            ? self::TOMBSTONED
            : self::STALE_REBUILDABLE;
        parent::__construct(
            'Current certificate authority has advanced beyond the serving manifest.',
        );
    }

    public function transitionState(): string
    {
        return $this->transitionState;
    }
}
