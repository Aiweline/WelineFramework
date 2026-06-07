<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Controller\Frontend;

use GuoLaiRen\A2A\Service\QuoteSelectionService;
use Weline\Framework\App\Controller\FrontendController;

class Quote extends FrontendController
{
    public function __construct(
        private readonly QuoteSelectionService $quoteSelectionService
    ) {
    }

    public function index(): string
    {
        $this->disableQuotePageCache();

        $quoteCode = (string)($this->request->getParam('quote') ?? '');

        try {
            $selection = $this->quoteSelectionService->selectQuote($quoteCode);
            foreach ($selection as $key => $value) {
                $this->assign($key, $value);
            }
            $this->assign('has_error', false);
        } catch (\Throwable $exception) {
            $this->request->getResponse()->setHttpResponseCode(404);
            $this->assign('page_title', __('A2A 报价托管草稿'));
            $this->assign('has_error', true);
            $this->assign('error_message', $exception->getMessage());
        }

        return $this->getTemplate()->fetch('GuoLaiRen_A2A::templates/Frontend/Quote/index.phtml');
    }

    private function disableQuotePageCache(): void
    {
        $response = $this->request->getResponse();
        $response->setHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');
        $response->setHeader('X-Accel-Expires', '0');
    }
}
