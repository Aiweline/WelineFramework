<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

/**
 * 结账/地址表单校验失败：携带表单字段名，供 API 返回 field_errors。
 */
final class AddressValidationException extends \InvalidArgumentException
{
    /** @var array<string, string> */
    private const FORM_FIELD_MAP = [
        'contact_name' => 'name',
        'contact_phone' => 'phone',
        'street' => 'address1',
        'postal_code' => 'postal_code',
        'province' => 'province',
        'city' => 'city',
        'district' => 'district',
        'email' => 'email',
        'country_code' => 'country_code',
    ];

    /**
     * @param array<string, string> $fieldErrors schema/internal field => message
     */
    public function __construct(
        private readonly array $fieldErrors,
        ?string $summaryMessage = null,
    ) {
        parent::__construct($summaryMessage ?? self::summarize($fieldErrors));
    }

    public static function single(string $field, string $message): self
    {
        return new self([$field => $message]);
    }

    public function getField(): string
    {
        $keys = array_keys($this->fieldErrors);

        return $keys[0] ?? '';
    }

    public function getFormField(): string
    {
        $field = $this->getField();

        return self::FORM_FIELD_MAP[$field] ?? $field;
    }

    /**
     * @return array<string, string>
     */
    public function toFieldErrors(): array
    {
        $mapped = [];
        foreach ($this->fieldErrors as $field => $message) {
            $mapped[self::FORM_FIELD_MAP[$field] ?? $field] = $message;
        }

        return $mapped;
    }

    /**
     * @param array<string, string> $fieldErrors
     */
    private static function summarize(array $fieldErrors): string
    {
        $messages = array_values(array_filter(array_map(
            static fn (string $message): string => trim($message),
            $fieldErrors,
        )));
        if ($messages === []) {
            return (string)__('请检查收货地址信息。');
        }

        return $messages[0];
    }
}
