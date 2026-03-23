<?php

declare(strict_types=1);

namespace WeShop\Address\Controller\Frontend\Address;

use WeShop\Address\Service\AddressService;
use WeShop\Customer\Api\CustomerContextInterface;
use Weline\Framework\App\Controller\FrontendController;

class AddressList extends FrontendController
{
    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly AddressService $addressService
    ) {
    }

    public function index(): string
    {
        try {
            $customerId = (int) ($this->customerContext->getUserId() ?? 0);
            if ($customerId <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('Please log in to continue.'),
                ]);
            }

            return $this->fetchJson([
                'success' => true,
                'data' => $this->addressService->getCustomerAddresses($customerId),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
