<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Controller\Frontend;

use GuoLaiRen\A2A\Service\EscrowConfirmationService;
use Weline\Framework\App\Controller\FrontendController;

class Confirm extends FrontendController
{
    public function __construct(
        private readonly EscrowConfirmationService $escrowConfirmationService
    ) {
    }

    public function index(): string
    {
        $this->disableConfirmationPageCache();

        $draftPublicId = (string) ($this->request->getParam('draft') ?? '');

        try {
            $confirmation = $this->escrowConfirmationService->confirm($draftPublicId);
            foreach ($confirmation as $key => $value) {
                $this->assign($key, $value);
            }
            $this->assign('has_error', false);
        } catch (\Throwable $exception) {
            $this->request->getResponse()->setHttpResponseCode(404);
            $this->assign('page_title', __('A2A 正式托管订单'));
            $this->assign('has_error', true);
            $this->assign('error_message', $exception->getMessage());
        }

        return $this->getTemplate()->fetch('GuoLaiRen_A2A::templates/Frontend/Confirm/index.phtml');
    }

    private function disableConfirmationPageCache(): void
    {
        $response = $this->request->getResponse();
        $response->setHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');
        $response->setHeader('X-Accel-Expires', '0');
    }
}
