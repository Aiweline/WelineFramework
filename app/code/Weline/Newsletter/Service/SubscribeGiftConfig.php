<?php

declare(strict_types=1);

namespace Weline\Newsletter\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * SystemConfig keys under newsletter/subscribe_gift/* (module Weline_Newsletter).
 */
final class SubscribeGiftConfig
{
    public const MODULE = 'Weline_Newsletter';
    public const AREA = SystemConfig::area_BACKEND;

    public const KEY_ENABLED = 'newsletter/subscribe_gift/enabled';
    public const KEY_DISCOUNT_TYPE = 'newsletter/subscribe_gift/discount_type';
    public const KEY_DISCOUNT_VALUE = 'newsletter/subscribe_gift/discount_value';
    public const KEY_VALID_DAYS = 'newsletter/subscribe_gift/valid_days';
    public const KEY_MARKETING_RULE_ID = 'newsletter/subscribe_gift/marketing_rule_id';
    public const KEY_POPUP_COOKIE_DAYS = 'newsletter/subscribe_gift/popup_cookie_days';

    public const DEFAULT_ENABLED = true;
    public const DEFAULT_DISCOUNT_TYPE = 'percentage';
    public const DEFAULT_DISCOUNT_VALUE = 10.0;
    public const DEFAULT_VALID_DAYS = 14;
    public const DEFAULT_POPUP_COOKIE_DAYS = 14;

    /**
     * @return array{
     *   enabled:bool,
     *   discount_type:string,
     *   discount_value:float,
     *   valid_days:int,
     *   marketing_rule_id:int,
     *   popup_cookie_days:int
     * }
     */
    public function read(): array
    {
        $cfg = $this->systemConfig();

        return [
            'enabled' => $this->asBool(
                $cfg->getConfig(self::KEY_ENABLED, self::MODULE, self::AREA, self::DEFAULT_ENABLED)
            ),
            'discount_type' => $this->normalizeDiscountType((string)$cfg->getConfig(
                self::KEY_DISCOUNT_TYPE,
                self::MODULE,
                self::AREA,
                self::DEFAULT_DISCOUNT_TYPE
            )),
            'discount_value' => \round(\max(0, (float)$cfg->getConfig(
                self::KEY_DISCOUNT_VALUE,
                self::MODULE,
                self::AREA,
                self::DEFAULT_DISCOUNT_VALUE
            )), 2),
            'valid_days' => \max(1, (int)$cfg->getConfig(
                self::KEY_VALID_DAYS,
                self::MODULE,
                self::AREA,
                self::DEFAULT_VALID_DAYS
            )),
            'marketing_rule_id' => \max(0, (int)$cfg->getConfig(
                self::KEY_MARKETING_RULE_ID,
                self::MODULE,
                self::AREA,
                0
            )),
            'popup_cookie_days' => \max(1, (int)$cfg->getConfig(
                self::KEY_POPUP_COOKIE_DAYS,
                self::MODULE,
                self::AREA,
                self::DEFAULT_POPUP_COOKIE_DAYS
            )),
        ];
    }

    /**
     * @param array<string, mixed> $values
     */
    public function write(array $values): void
    {
        $cfg = $this->systemConfig();
        if (\array_key_exists('enabled', $values)) {
            $cfg->setConfig(self::KEY_ENABLED, $this->asBool($values['enabled']) ? '1' : '0', self::MODULE, self::AREA);
        }
        if (\array_key_exists('discount_type', $values)) {
            $cfg->setConfig(
                self::KEY_DISCOUNT_TYPE,
                $this->normalizeDiscountType((string)$values['discount_type']),
                self::MODULE,
                self::AREA
            );
        }
        if (\array_key_exists('discount_value', $values)) {
            $cfg->setConfig(
                self::KEY_DISCOUNT_VALUE,
                (string)\round(\max(0, (float)$values['discount_value']), 2),
                self::MODULE,
                self::AREA
            );
        }
        if (\array_key_exists('valid_days', $values)) {
            $cfg->setConfig(
                self::KEY_VALID_DAYS,
                (string)\max(1, (int)$values['valid_days']),
                self::MODULE,
                self::AREA
            );
        }
        if (\array_key_exists('marketing_rule_id', $values)) {
            $cfg->setConfig(
                self::KEY_MARKETING_RULE_ID,
                (string)\max(0, (int)$values['marketing_rule_id']),
                self::MODULE,
                self::AREA
            );
        }
        if (\array_key_exists('popup_cookie_days', $values)) {
            $cfg->setConfig(
                self::KEY_POPUP_COOKIE_DAYS,
                (string)\max(1, (int)$values['popup_cookie_days']),
                self::MODULE,
                self::AREA
            );
        }
    }

    public function normalizeDiscountType(string $type): string
    {
        $type = \strtolower(\trim($type));

        return match ($type) {
            'percentage', 'percent', 'pct' => 'percentage',
            'fixed_amount', 'fixed', 'amount' => 'fixed_amount',
            default => self::DEFAULT_DISCOUNT_TYPE,
        };
    }

    private function asBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int)$value === 1;
        }
        $s = \strtolower(\trim((string)$value));

        return \in_array($s, ['1', 'true', 'yes', 'on'], true);
    }

    private function systemConfig(): SystemConfig
    {
        return ObjectManager::getInstance(SystemConfig::class);
    }
}
