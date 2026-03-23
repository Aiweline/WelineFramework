<?php

declare(strict_types=1);

namespace WeShop\Auth\Api\Rest\V1\Auth;

use WeShop\Auth\Service\AuthGrantService;
use Weline\Framework\App\Controller\FrontendRestController;

class Challenge extends FrontendRestController
{
    public function __construct(
        private readonly AuthGrantService $authGrantService
    ) {
        parent::__construct();
    }

    public function postVerify(): string
    {
        try {
            $challengeToken = (string) ($this->request->getBodyParam('challenge_token') ?? $this->request->getPost('challenge_token') ?? '');
            $code = (string) ($this->request->getBodyParam('code') ?? $this->request->getPost('code') ?? '');

            if ($challengeToken === '' || $code === '') {
                throw new \InvalidArgumentException((string) __('Challenge token and code are required.'));
            }

            return (string) $this->success(
                __('Challenge verification succeeded.'),
                $this->authGrantService->verifyChallenge($challengeToken, $code)
            );
        } catch (\Throwable $throwable) {
            return (string) $this->exception($throwable, __('Challenge verification failed.'));
        }
    }
}
