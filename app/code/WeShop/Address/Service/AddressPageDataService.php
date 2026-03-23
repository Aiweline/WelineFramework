<?php

declare(strict_types=1);

namespace WeShop\Address\Service;

use Weline\I18n\Model\I18n;

class AddressPageDataService
{
    public function __construct(
        private readonly AddressService $addressService,
        private readonly I18n $i18n
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $customerId): array
    {
        $addresses = $this->addressService->getCustomerAddresses($customerId);
        $defaultAddress = null;
        foreach ($addresses as $address) {
            if (!is_array($address)) {
                continue;
            }

            if (!empty($address['is_default'])) {
                $defaultAddress = $address;
                break;
            }
        }

        return [
            'addresses' => $addresses,
            'address_count' => count($addresses),
            'default_address' => $defaultAddress,
            'countries' => $this->buildCountries(),
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function buildCountries(): array
    {
        $countries = [];
        foreach ($this->i18n->getCountries('en') as $code => $name) {
            $countries[] = [
                'code' => (string) $code,
                'name' => (string) $name,
            ];
        }

        return $countries;
    }
}
