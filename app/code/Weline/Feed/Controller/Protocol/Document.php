<?php

declare(strict_types=1);

namespace Weline\Feed\Controller\Protocol;

use Weline\Feed\Service\FeedPublicDocumentService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Response;

/**
 * Public GET of a stored artifact. The route is /feed/protocol/document?token=...
 */
final class Document extends FrontendController
{
    public function __construct(
        private readonly FeedPublicDocumentService $documents,
    ) {
    }

    public function index(): Response
    {
        $token = '';
        if (isset($this->request)) {
            $token = trim((string)$this->request->getParam('token'));
        }
        $decision = $this->documents->resolve($token);
        if ($decision->statusCode !== 200) {
            return Response::text('', 404, 'text/plain; charset=utf-8');
        }

        return Response::text($decision->body, 200, $decision->contentType);
    }
}
