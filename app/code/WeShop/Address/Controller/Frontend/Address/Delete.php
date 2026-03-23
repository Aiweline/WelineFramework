<?php

declare(strict_types=1);

namespace WeShop\Address\Controller\Frontend\Address;

use WeShop\Address\Service\AddressService;
use WeShop\Customer\Api\CustomerContextInterface;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Url;

class Delete extends FrontendController
{
    private const LOGIN_ROUTE = 'customer/account/login';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly AddressService $addressService,
        private readonly Url $url
    ) {
    }

    public function index(): string
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Please log in to continue.'),
                'data' => [
                    'redirect_url' => $this->url->getUrl(self::LOGIN_ROUTE),
                ],
            ]);
        }

        $addressId = $this->readAddressId();
        if ($addressId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Address ID is required.'),
            ]);
        }

        $deleted = $this->addressService->deleteAddress($addressId, $customerId);
        if (!$deleted) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Address could not be removed.'),
            ]);
        }

        return $this->fetchJson([
            'success' => true,
            'message' => __('Address removed successfully.'),
            'data' => [
                'address_count' => count($this->addressService->getCustomerAddresses($customerId)),
            ],
        ]);
    }

    public function post(): string
    {
        return $this->index();
    }

    protected function readAddressId(): int
    {
        return (int) (
            $this->request->body('address_id')
            ?? $this->request->body('id')
            ?? $this->request->getPost('address_id')
            ?? $this->request->getPost('id')
            ?? $this->request->getParam('address_id')
            ?? $this->request->getParam('id')
            ?? 0
        );
    }
}
