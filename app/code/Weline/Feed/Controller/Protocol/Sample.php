<?php

declare(strict_types=1);

namespace Weline\Feed\Controller\Protocol;

use Weline\Feed\Api\Data\FeedConnectionMode;
use Weline\Feed\Api\Data\FeedDefinition;
use Weline\Feed\Api\Data\FeedPublicDecision;
use Weline\Feed\Service\Channel\AbstractFeedChannel;
use Weline\Feed\Service\FeedChannelRegistry;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Response;

/**
 * Operator sample GET. The route is /feed/protocol/sample?channel=...
 */
final class Sample extends FrontendController
{
    public function __construct(
        private readonly FeedChannelRegistry $channels,
    ) {
    }

    public function index(): Response
    {
        $code = '';
        if (isset($this->request)) {
            $code = trim((string)$this->request->getParam('channel'));
        }
        $channel = $this->channels->find($code);
        if (!$channel instanceof AbstractFeedChannel) {
            return $this->notFound();
        }
        $document = $channel->renderSample(new FeedDefinition(
            definitionId: 0,
            websiteId: 0,
            sourceCode: 'catalog',
            channelCode: $code,
            locale: 'en_US',
            currency: 'USD',
            title: $channel->displayName(),
            enabled: true,
            connectionMode: FeedConnectionMode::FEED_URL,
            accountId: 0,
            publicToken: 'sample',
        ));
        if (trim($document->body) === '') {
            return $this->notFound();
        }

        return Response::text($document->body, 200, $document->contentType);
    }

    private function notFound(): Response
    {
        $decision = FeedPublicDecision::notFound();

        return Response::text($decision->body, $decision->statusCode, $decision->contentType);
    }
}
