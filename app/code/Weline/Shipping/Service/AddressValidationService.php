<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

class AddressValidationService
{
    public function __construct(
        private AddressSchemaProvider $schemaProvider,
        private AddressFormatter $formatter
    ) {
    }

    /**
     * @throws \Exception
     */
    public function validate(array $data, array $requiredBaseFields = []): void
    {
        $address = $this->formatter->normalize($data);
        $schema = $this->schemaProvider->getSchema($address['country_code']);
        $labels = $schema['labels'];
        $required = array_values(array_unique(array_merge($requiredBaseFields, $schema['required_fields'])));

        foreach ($required as $field) {
            if (trim((string)($address[$field] ?? $data[$field] ?? '')) === '') {
                throw new \Exception(__('%{1}不能为空', [$labels[$field] ?? $field]));
            }
        }

        $phone = trim((string)($address['contact_phone'] ?? ''));
        if ($phone !== '' && !preg_match((string)$schema['phone_pattern'], $phone)) {
            throw new \Exception(__('电话号码格式不正确'));
        }

        $postalCode = trim((string)($address['postal_code'] ?? ''));
        if ($postalCode !== '' && !preg_match((string)$schema['postal_code_pattern'], $postalCode)) {
            throw new \Exception(__('邮政编码格式不正确'));
        }
    }
}
