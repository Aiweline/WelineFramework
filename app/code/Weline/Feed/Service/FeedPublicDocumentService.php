<?php

declare(strict_types=1);

namespace Weline\Feed\Service;

use Weline\Feed\Api\Data\FeedPublicDecision;
use Weline\Feed\Api\FeedPublicSnapshotSourceInterface;

/**
 * Public GET reads a stored artifact. It does not call the offer reader or a channel renderer.
 */
final class FeedPublicDocumentService
{
    public function __construct(
        private readonly FeedPublicSnapshotSourceInterface $snapshots,
        private readonly FeedPublicDocumentGate $gate = new FeedPublicDocumentGate(),
    ) {
    }

    public function resolve(string $token): FeedPublicDecision
    {
        $token = trim($token);
        if (FeedPublicPath::isForbiddenToken($token)) {
            return FeedPublicDecision::notFound();
        }

        return $this->gate->decide($this->snapshots->findByToken($token));
    }
}
