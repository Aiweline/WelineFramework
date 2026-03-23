<?php

declare(strict_types=1);

namespace WeShop\QA\Controller\Frontend\QA;

use WeShop\Frontend\Controller\BaseController;
use WeShop\QA\Service\QAService;
use WeShop\Customer\Api\CustomerContextInterface;
use Weline\Framework\Http\Url;

class Add extends BaseController
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
                'message' => __('Please log in to ask a question.'),
                'data' => [
                    'redirect_url' => $this->url->getUrl(self::LOGIN_ROUTE),
                ],
            ]);
        }

        $productId = (int) ($this->request->body('product_id') ?? $this->request->getPost('product_id') ?? 0);
        $question = trim((string) ($this->request->body('question') ?? $this->request->getPost('question') ?? ''));

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
        ]);

        return $this->fetchJson([
            'success' => true,
            'message' => __('Your question has been submitted and is pending review.'),
        ]);
    }

    public function post(): string
    {
        return $this->index();
    }
}
