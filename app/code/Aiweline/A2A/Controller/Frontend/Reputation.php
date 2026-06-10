<?php

declare(strict_types=1);

namespace Aiweline\A2A\Controller\Frontend;

use Aiweline\A2A\Service\AgentReputationService;
use Weline\Framework\App\Controller\FrontendController;

class Reputation extends FrontendController
{
    public function __construct(
        private readonly AgentReputationService $agentReputationService
    ) {
    }

    public function index(): string
    {
        $this->disableReputationPageCache();

        $providerKey = (string)($this->request->getParam('agent') ?? '');

        try {
            $reputation = $this->agentReputationService->calculate($providerKey);
            foreach ($reputation as $key => $value) {
                $this->assign($key, $value);
            }
            $this->assign('has_error', false);
        } catch (\Throwable $exception) {
            $this->request->getResponse()->setHttpResponseCode(404);
            $this->assign('page_title', __('A2A Agent 信誉重算'));
            $this->assign('has_error', true);
            $this->assign('error_message', $exception->getMessage());
        }

        return $this->getTemplate()->fetch('Aiweline_A2A::templates/Frontend/Reputation/index.phtml');
    }

    private function disableReputationPageCache(): void
    {
        $response = $this->request->getResponse();
        $response->setHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');
        $response->setHeader('X-Accel-Expires', '0');
    }
}
