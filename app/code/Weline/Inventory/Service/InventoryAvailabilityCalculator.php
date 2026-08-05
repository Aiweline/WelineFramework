<?php

declare(strict_types=1);

namespace Weline\Inventory\Service;

/**
 * Pure minor-quantity availability rules for the four stock strategies.
 *
 * - strict: available = max(0, on_hand - reserved)；不可为负
 * - oversell: available = max(0, on_hand + oversell_allowance - reserved)
 * - preorder: available = max(0, on_hand + preorder_allowance - reserved)
 * - unlimited: available = PHP_INT_MAX（仍跟踪 reserved，但不以 on_hand 限制）
 */
final class InventoryAvailabilityCalculator
{
    public const STRATEGY_STRICT = 'strict';
    public const STRATEGY_OVERSELL = 'oversell';
    public const STRATEGY_PREORDER = 'preorder';
    public const STRATEGY_UNLIMITED = 'unlimited';

    /** @return list<string> */
    public static function strategies(): array
    {
        return [
            self::STRATEGY_STRICT,
            self::STRATEGY_OVERSELL,
            self::STRATEGY_PREORDER,
            self::STRATEGY_UNLIMITED,
        ];
    }

    public function assertValidStrategy(string $strategy): string
    {
        $strategy = strtolower(trim($strategy));
        if (!in_array($strategy, self::strategies(), true)) {
            throw new \InvalidArgumentException(__('未知库存策略：%{1}', [$strategy]));
        }
        return $strategy;
    }

    public function assertPositiveMinor(int $quantityMinor): void
    {
        if ($quantityMinor <= 0) {
            throw new \InvalidArgumentException(__('库存数量必须为正整数 minor：%{1}', [$quantityMinor]));
        }
    }

    public function availableMinor(
        string $strategy,
        int $onHandMinor,
        int $reservedMinor,
        int $oversellAllowance = 0,
        int $preorderAllowance = 0,
    ): int {
        $strategy = $this->assertValidStrategy($strategy);
        if ($onHandMinor < 0 || $reservedMinor < 0) {
            throw new \InvalidArgumentException(__('on_hand/reserved 不能为负'));
        }
        if ($oversellAllowance < 0 || $preorderAllowance < 0) {
            throw new \InvalidArgumentException(__('allowance 不能为负'));
        }

        return match ($strategy) {
            self::STRATEGY_STRICT => max(0, $onHandMinor - $reservedMinor),
            self::STRATEGY_OVERSELL => max(
                0,
                $this->checkedAdd($onHandMinor, $oversellAllowance) - $reservedMinor,
            ),
            self::STRATEGY_PREORDER => max(
                0,
                $this->checkedAdd($onHandMinor, $preorderAllowance) - $reservedMinor,
            ),
            self::STRATEGY_UNLIMITED => PHP_INT_MAX,
        };
    }

    public function canReserve(
        string $strategy,
        int $onHandMinor,
        int $reservedMinor,
        int $quantityMinor,
        int $oversellAllowance = 0,
        int $preorderAllowance = 0,
    ): bool {
        $this->assertPositiveMinor($quantityMinor);
        $available = $this->availableMinor(
            $strategy,
            $onHandMinor,
            $reservedMinor,
            $oversellAllowance,
            $preorderAllowance,
        );
        return $quantityMinor <= $available;
    }

    private function checkedAdd(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new \InvalidArgumentException(__('库存 minor 加法溢出'));
        }
        return $left + $right;
    }
}
