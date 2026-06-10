<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

use Weline\Framework\DataObject\DataObject;

abstract class AbstractPaymentData extends DataObject
{
    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new static($data);
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->getData($key);

        return $value === null ? $default : (string) $value;
    }

    public function getNullableString(string $key): ?string
    {
        $value = $this->getData($key);

        return $value === null || $value === '' ? null : (string) $value;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->getData($key);

        return $value === null || $value === '' ? $default : (int) $value;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->getData($key);

        return $value === null ? $default : (bool) $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function getArray(string $key): array
    {
        $value = $this->getData($key);

        return \is_array($value) ? $value : [];
    }
}
