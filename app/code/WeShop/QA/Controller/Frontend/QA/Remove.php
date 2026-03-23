<?php

declare(strict_types=1);

namespace WeShop\QA\Controller\Frontend\QA;

use WeShop\Frontend\Controller\BaseController;
use WeShop\QA\Service\QAService;
use WeShop\Customer\Api\CustomerContextInterface;
use Weline\Framework\Http\Url;

class Remove extends BaseController
{
    private const LOGIN_ROUTE = 'customer/account/login';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly QAService $qaService,
        private readonly Url $url
    ) {
    }

    public function index(): string
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Please log in to manage your questions.'),
                'data' => [
                    'redirect_url' => $this->url->getUrl(self::LOGIN_ROUTE),
                ],
            ]);
        }

        $questionId = (int) (
            $this->request->body('question_id')
            ?? $this->request->body('item_id')
            ?? $this->request->getPost('question_id')
            ?? $this->request->getPost('item_id')
            ?? $this->request->getParam('question_id')
            ?? $this->request->getParam('item_id')
            ?? 0
        );
        if ($questionId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Question ID is required.'),
            ]);
        }

        $removed = $this->qaService->removeQuestion($questionId, $customerId);
        if (!$removed) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Cannot remove this question.'),
            ]);
        }

        return $this->fetchJson([
            'success' => true,
            'message' => __('Question removed.'),
        ]);
    }

    public function post(): string
    {
        return $this->index();
    }
}
