<?php

declare(strict_types=1);

namespace Weline\Feed\Service;

use Weline\Feed\Api\Data\FeedChannelAccount;
use Weline\Feed\Api\Data\FeedCollectQuery;
use Weline\Feed\Api\Data\FeedConnectionMode;
use Weline\Feed\Api\Data\FeedConnectionState;
use Weline\Feed\Api\Data\FeedDefinition;
use Weline\Feed\Api\Data\FeedDocument;
use Weline\Feed\Api\Data\FeedGenerationReport;
use Weline\Feed\Api\Data\FeedItem;
use Weline\Feed\Api\FeedArtifactWriterInterface;
use Weline\Feed\Api\FeedCategoryMapReaderInterface;
use Weline\Feed\Api\FeedSourceInterface;
use Weline\Feed\Api\FeedStoreCategoryReaderInterface;
use Weline\Feed\Exception\FeedChannelUnregistered;
use Weline\Feed\Exception\FeedCredentialsMissing;

/**
 * Builds one artifact from a registered channel. Missing secrets and blank bodies are failures.
 * Category maps are read for the definition's website. They are not seeded.
 */
final class FeedGenerationService
{
    public function __construct(
        private readonly FeedConnectionRegistry $connections,
        private readonly FeedChannelRegistry $channels,
        private readonly FeedSourceInterface $source,
        private readonly FeedArtifactWriterInterface $artifacts,
        private readonly ?FeedCategoryMapReaderInterface $maps = null,
        private readonly ?FeedStoreCategoryReaderInterface $storeCategories = null,
    ) {
    }

    public function generate(FeedDefinition $definition, FeedChannelAccount $account): void
    {
        $this->generateReport($definition, $account);
    }

    public function generateReport(FeedDefinition $definition, FeedChannelAccount $account): FeedGenerationReport
    {
        $connectionStatus = $this->operatorStatus($definition, $account);
        if ($account->connectionMode !== $definition->connectionMode || !FeedConnectionMode::isKnown($definition->connectionMode)) {
            $failure = new FeedCredentialsMissing($definition->channelCode, $definition->connectionMode, ['connection_mode']);
            $this->artifacts->writeFailed(
                $definition,
                $account,
                'credentials_missing',
                FeedConnectionState::APP_UNCONFIGURED,
            );
            throw $failure;
        }

        $connection = $this->connections->forMode($definition->connectionMode);
        $channel = $this->channels->find($definition->channelCode);
        try {
            if ($channel !== null && !in_array($connection->mode(), $channel->acceptedModes(), true)) {
                throw new FeedCredentialsMissing($definition->channelCode, $connection->mode(), ['connection_mode']);
            }
            $connection->assertSecrets($account, $definition);
            $connection->open($account, $definition);
        } catch (FeedCredentialsMissing $e) {
            $this->artifacts->writeFailed(
                $definition,
                $account,
                'credentials_missing',
                FeedConnectionState::APP_UNCONFIGURED,
            );
            throw $e;
        }

        if ($channel === null) {
            $this->artifacts->writeFailed(
                $definition,
                $account,
                'channel_unregistered',
                $this->stateAfterSecrets($definition),
            );
            throw new FeedChannelUnregistered($definition->channelCode);
        }

        if (FeedMarketGate::excluded($definition->channelCode, $definition->country())) {
            $this->artifacts->writeFailed(
                $definition,
                $account,
                'market_excluded',
                $this->stateAfterSecrets($definition),
            );
            throw new \RuntimeException('market_excluded');
        }

        $items = [];
        foreach ($this->source->collect(FeedCollectQuery::fromDefinition($definition)) as $item) {
            if ($item instanceof FeedItem) {
                $items[] = $item;
            }
        }
        if ($items === []) {
            $planDiagnostics = [[
                'code' => 'no_eligible_offers',
                'channel_code' => $definition->channelCode,
                'product_id' => 0,
                'offer_id' => '',
                'store_category_id' => 0,
                'vocabulary' => FeedCategoryVocabulary::forChannel($definition->channelCode, $channel->filenameSuffix()),
            ]];
            $planItems = [];
            $skipped = 0;
        } else {
            $plan = (new FeedCategoryPlanner($this->maps, $this->storeCategories))
                ->apply($definition, $items, $channel->filenameSuffix());
            $planDiagnostics = $plan->diagnostics;
            $planItems = $plan->items;
            $skipped = $plan->skippedCount;
        }

        $document = $channel->render(
            $definition->withShippingAmount((string)($account->secretFields['shipping'] ?? '')),
            $planItems,
        );
        if (trim($document->body) === '') {
            $errorCode = trim($document->diagnosticCode) !== '' ? trim($document->diagnosticCode) : 'empty_document';
            $this->artifacts->writeFailed(
                $definition,
                $account,
                $errorCode,
                $this->stateAfterSecrets($definition),
            );
            throw new \RuntimeException($errorCode);
        }
        $document = $this->withPlan($document, $planDiagnostics, $skipped);

        $this->artifacts->writeReady(
            $definition,
            $account,
            $document,
            $document->itemCount,
            $this->stateAfterSecrets($definition),
        );

        return new FeedGenerationReport(
            completed: true,
            itemCount: $document->itemCount,
            skippedCount: $document->skippedCount,
            connectionStatus: $connectionStatus,
            diagnostics: $document->diagnostics,
            errorCode: $document->diagnosticCode,
            body: $document->body,
        );
    }

    /**
     * @param list<array<string, mixed>> $planDiagnostics
     */
    private function withPlan(FeedDocument $document, array $planDiagnostics, int $skipped): FeedDocument
    {
        $primary = trim($document->diagnosticCode);
        if ($primary === '' && $planDiagnostics !== []) {
            $primary = trim((string)($planDiagnostics[0]['code'] ?? ''));
        }

        return new FeedDocument(
            contentType: $document->contentType,
            filenameSuffix: $document->filenameSuffix,
            body: $document->body,
            diagnosticCode: $primary,
            itemCount: $document->itemCount,
            skippedCount: $skipped,
            diagnostics: $planDiagnostics,
        );
    }

    private function operatorStatus(FeedDefinition $definition, FeedChannelAccount $account): string
    {
        $status = FeedOperatorConnectionStatus::resolve($definition->channelCode, $account->secretFields);
        if ($status === FeedOperatorConnectionStatus::AUTHORIZED
            && FeedMarketGate::hidesAuthorized($definition->channelCode, $definition->country())
        ) {
            return FeedOperatorConnectionStatus::AWAITING;
        }

        return $status;
    }

    private function stateAfterSecrets(FeedDefinition $definition): string
    {
        if ($definition->connectionMode === FeedConnectionMode::FEED_URL) {
            return FeedConnectionState::ADDRESS_ONLY;
        }

        return FeedConnectionState::AWAITING_AUTHORIZATION;
    }
}
