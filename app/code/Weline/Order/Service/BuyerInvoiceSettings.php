<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\ConfigReader;

/**
 * 买家发票 PDF 开关与销售方资料。值只来自统一配置中心。
 */
final class BuyerInvoiceSettings
{
    public const MODULE = 'Weline_Order';
    public const AREA = ConfigReader::area_BACKEND;
    public const KEY_ENABLED = 'order/invoice/buyer_pdf_enabled';
    public const KEY_SELLER_NAME = 'order/invoice/seller_name';
    public const KEY_SELLER_TAX_ID = 'order/invoice/seller_tax_id';
    public const KEY_SELLER_ADDRESS = 'order/invoice/seller_address';
    public const KEY_SELLER_PHONE = 'order/invoice/seller_phone';
    public const KEY_SELLER_EMAIL = 'order/invoice/seller_email';

    public function __construct(private ?ConfigReader $config = null)
    {
    }

    public function isEnabled(): bool
    {
        return self::toBool($this->read(self::KEY_ENABLED, '0'));
    }

    public function isOffered(): bool
    {
        return self::offered(
            $this->isEnabled(),
            $this->read(self::KEY_SELLER_NAME, ''),
        );
    }

    /**
     * @return array{name:string,tax_id:string,address:string,phone:string,email:string}
     */
    public function seller(): array
    {
        return [
            'name' => trim((string)$this->read(self::KEY_SELLER_NAME, '')),
            'tax_id' => trim((string)$this->read(self::KEY_SELLER_TAX_ID, '')),
            'address' => trim((string)$this->read(self::KEY_SELLER_ADDRESS, '')),
            'phone' => trim((string)$this->read(self::KEY_SELLER_PHONE, '')),
            'email' => trim((string)$this->read(self::KEY_SELLER_EMAIL, '')),
        ];
    }

    public static function offered(mixed $enabled, mixed $sellerName): bool
    {
        return self::toBool($enabled) && trim((string)$sellerName) !== '';
    }

    private function read(string $key, mixed $default): mixed
    {
        $reader = $this->config ??= ObjectManager::getInstance(ConfigReader::class);
        $identity = RequestContext::scopeIdentity();
        if ($identity instanceof ScopeIdentity) {
            $typed = $reader->resolveTypedConfig(
                $key,
                self::MODULE,
                self::AREA,
                $identity,
                null,
                $default,
            );

            return $typed->found() ? $typed->value : $default;
        }

        return $reader->get($key, self::MODULE, self::AREA, $default);
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value !== 0;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
