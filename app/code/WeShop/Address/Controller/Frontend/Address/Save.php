<?php

declare(strict_types=1);

namespace WeShop\Address\Controller\Frontend\Address;

use WeShop\Address\Service\AddressService;
use WeShop\Customer\Api\CustomerContextInterface;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Url;

class Save extends FrontendController
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
        try {
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

            $address = $this->addressService->saveAddress([
                'address_id' => (int) ($this->request->getParam('address_id') ?? 0),
                'customer_id' => $customerId,
                'firstname' => (string) ($this->request->getParam('firstname') ?? ''),
                'lastname' => (string) ($this->request->getParam('lastname') ?? ''),
                'telephone' => (string) ($this->request->getParam('telephone') ?? $this->request->getParam('phone') ?? ''),
                'country_id' => (string) ($this->request->getParam('country_id') ?? $this->request->getParam('country') ?? ''),
                'region' => (string) ($this->request->getParam('region') ?? ''),
                'city' => (string) ($this->request->getParam('city') ?? ''),
                'district' => (string) ($this->request->getParam('district') ?? ''),
                'street' => (string) ($this->request->getParam('street') ?? ''),
                'postcode' => (string) ($this->request->getParam('postcode') ?? ''),
                'is_default' => (bool) ($this->request->getParam('is_default') ?? false),
            ]);

            return $this->fetchJson([
                'success' => true,
                'message' => __('Address saved successfully.'),
                'data' => $address,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function post(): string
    {
        return $this->index();
    }
}
