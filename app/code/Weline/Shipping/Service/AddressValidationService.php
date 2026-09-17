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
     * @throws AddressValidationException
     */
    public function validate(array $data, array $requiredBaseFields = []): void
    {
        $address = $this->formatter->normalize($data);
        $schema = $this->schemaProvider->getSchema($address['country_code']);
        $labels = $schema['labels'];
        $required = array_values(array_unique(array_merge($requiredBaseFields, $schema['required_fields'])));

        /** @var array<string, string> $errors */
        $errors = [];

        foreach ($required as $field) {
            if (trim((string)($address[$field] ?? $data[$field] ?? '')) === '') {
                $errors[$field] = (string)__('%{1}不能为空', [$labels[$field] ?? $field]);
            }
        }

        $phone = trim((string)($address['contact_phone'] ?? ''));
        if ($phone !== '' && !$this->isValidInternationalPhone($phone)) {
            $errors['contact_phone'] = (string)__('电话号码格式不正确');
        }

        $postalCode = trim((string)($address['postal_code'] ?? ''));
        if ($postalCode !== '' && !preg_match((string)$schema['postal_code_pattern'], $postalCode)) {
            $errors['postal_code'] = (string)__('邮政编码格式不正确');
        }

        if ($errors !== []) {
            throw new AddressValidationException($errors);
        }
    }

    /**
     * 全球电话：允许 + 与常见分隔符，按 E.164 数字位数 7–15 校验（不绑单一国格式）。
     * 允许字符：数字、可选前导 +、空格、-、()、.、/
     */
    public function isValidInternationalPhone(string $phone): bool
    {
        $phone = trim($phone);
        if ($phone === '' || strlen($phone) > 32) {
            return false;
        }
        if (!preg_match('/^\+?[0-9\-\s().\/]+$/', $phone)) {
            return false;
        }
        if (str_contains($phone, '+') && !str_starts_with($phone, '+')) {
            return false;
        }
        if (substr_count($phone, '+') > 1) {
            return false;
        }
        if (!preg_match('/^\+?[0-9]/', $phone)) {
            return false;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $len = strlen($digits);

        return $len >= 7 && $len <= 15;
    }
}
