<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

class AddressValidationService
{
    public function __construct(
        private AddressSchemaProvider $schemaProvider,
        private AddressFormatter $formatter,
        private PhoneNumberRuleService $phoneRules = new PhoneNumberRuleService(),
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
        if ($phone !== '') {
            $analysis = $this->phoneRules->analyze($phone, (string)$address['country_code']);
            if (!$analysis['valid']) {
                $errors['contact_phone'] = $this->phoneErrorMessage($analysis);
            }
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
     * 国际化电话校验（规则权威：PhoneNumberRuleService / data/phone-country-rules.json）。
     * 允许字符：数字、可选前导 +、空格、-、()、.、/。
     * 带 + / 00 按区号定位国家规则；否则按 $countryCode 当国内写法校验；无规则回退 E.164 总位数 7–15。
     */
    public function isValidInternationalPhone(string $phone, ?string $countryCode = null): bool
    {
        return $this->phoneRules->isValid($phone, $countryCode);
    }

    /**
     * @param array{reason:string,rule:array<string,mixed>|null} $analysis
     */
    private function phoneErrorMessage(array $analysis): string
    {
        $example = trim((string)($analysis['rule']['example'] ?? ''));
        if ($example !== '') {
            return (string)__('电话号码格式不正确，示例：%{1}', [$example]);
        }

        return (string)__('电话号码格式不正确');
    }
}
