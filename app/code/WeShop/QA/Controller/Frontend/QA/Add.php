<?php

declare(strict_types=1);

namespace WeShop\QA\Controller\Frontend\QA;

use WeShop\Frontend\Controller\BaseController;
use WeShop\QA\Service\QAService;
use WeShop\Customer\Api\CustomerContextInterface;

class Add extends BaseController
{
    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly QAService $qaService
    ) {
    }

    public function index(): string
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        $productId = (int) ($this->request->body('product_id') ?? $this->request->getPost('product_id') ?? 0);
        $question = trim((string) ($this->request->body('question') ?? $this->request->getPost('question') ?? ''));
        $mentionedCustomerIds = $this->request->body('mentioned_customer_ids')
            ?? $this->request->getPost('mentioned_customer_ids')
            ?? [];
        $displayName = trim((string) ($this->request->body('display_name') ?? $this->request->getPost('display_name') ?? ''));

        if ($productId <= 0 || $question === '') {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Product ID and question text are required.'),
            ]);
        }

        $this->qaService->createQuestion([
            'product_id' => $productId,
            'customer_id' => $customerId,
            'question' => $question,
            'mentioned_customer_ids' => $mentionedCustomerIds,
            'display_name' => $displayName,
        ]);

        return $this->fetchJson([
            'success' => true,
            'message' => __('您的问题已发布。'),
        ]);
    }

    public function post(): string
    {
        return $this->index();
    }
}
