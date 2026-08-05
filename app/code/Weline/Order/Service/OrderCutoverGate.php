<?php

declare(strict_types=1);

namespace Weline\Order\Service;

/**
 * Checkout→Order cutover gate（DEC-023 / MOD-P2D-004 / TASK-MIG-P2-ORDER）.
 * `executeCutover` 须带 production_on_token；落地后 `cutoverApplied` 永久禁旧 writer。
 */
final class OrderCutoverGate
{
    public const CAPABILITY = 'checkout_to_order';

    public const MODE_OFF = 'off';
    public const MODE_SHADOW = 'shadow';
    public const MODE_ALLOWLIST = 'allowlist';
    public const MODE_ON = 'on';

    public const MODES = [
        self::MODE_OFF,
        self::MODE_SHADOW,
        self::MODE_ALLOWLIST,
        self::MODE_ON,
    ];

    public const ERROR_UNKNOWN_MODE = 'order_cutover_unknown_mode';
    public const ERROR_ON_TOKEN = 'order_cutover_on_requires_token';
    public const ERROR_CUTOVER_DEFERRED = 'order_cutover_deferred_to_mig';
    public const ERROR_CUTOVER_NOT_AUTHORIZED = 'order_cutover_mig_token_required';
    public const ERROR_ROLLBACK_LEGACY = 'order_cutover_rollback_legacy_forbidden';
    public const ERROR_ROLLBACK_HIDES_NEW = 'order_cutover_rollback_would_hide_new_orders';

    /** @var array{mode:string,allowlist:list<string>,prod_token:string} */
    private array $state = [
        'mode' => self::MODE_OFF,
        'allowlist' => [],
        'prod_token' => '',
    ];

    private bool $cutoverApplied = false;
    private int $watermark = 0;

    public function mode(): string
    {
        return $this->state['mode'];
    }

    public function isCutoverApplied(): bool
    {
        return $this->cutoverApplied;
    }

    public function watermark(): int
    {
        return $this->watermark;
    }

    /**
     * @param list<string> $allowlistSubjects
     */
    public function setMode(
        string $mode,
        array $allowlistSubjects = [],
        string $productionOnToken = '',
    ): void {
        if (!in_array($mode, self::MODES, true)) {
            throw new OrderFacadeConflictException(
                self::ERROR_UNKNOWN_MODE,
                \__('未知 cutover mode：%{1}', [$mode]),
                ['mode' => $mode],
            );
        }
        if ($mode === self::MODE_ON && trim($productionOnToken) === '') {
            throw new OrderFacadeConflictException(
                self::ERROR_ON_TOKEN,
                \__('生产 on 必须显式授权令牌'),
            );
        }
        $clean = [];
        foreach ($allowlistSubjects as $subject) {
            $subject = trim((string)$subject);
            if ($subject !== '') {
                $clean[] = $subject;
            }
        }
        $this->state = [
            'mode' => $mode,
            'allowlist' => array_values(array_unique($clean)),
            'prod_token' => $productionOnToken,
        ];
    }

    public function isShadow(): bool
    {
        return $this->mode() === self::MODE_SHADOW;
    }

    public function isEffectivelyOn(string $subject = ''): bool
    {
        return match ($this->mode()) {
            self::MODE_ON => true,
            self::MODE_ALLOWLIST => $subject !== ''
                && in_array($subject, $this->state['allowlist'], true),
            default => false,
        };
    }

    /** Legacy Checkout writer may mutate when mode is off|shadow|(allowlist miss uses legacy). */
    public function legacyWritable(string $subject = ''): bool
    {
        // DEC-023：cutover 一旦落地，永不恢复旧 writer。
        if ($this->cutoverApplied) {
            return false;
        }

        return match ($this->mode()) {
            self::MODE_OFF, self::MODE_SHADOW => true,
            self::MODE_ALLOWLIST => $subject !== '' && !$this->isEffectivelyOn($subject),
            self::MODE_ON => false,
            default => true,
        };
    }

    /** New OrderFacade::create：off 可写（构建期）；shadow 禁止；allowlist/on 按名单. */
    public function newWritable(string $subject = ''): bool
    {
        return match ($this->mode()) {
            self::MODE_OFF => true,
            self::MODE_SHADOW => false,
            self::MODE_ALLOWLIST, self::MODE_ON => $this->isEffectivelyOn($subject),
            default => false,
        };
    }

    /**
     * Cutover apply（TASK-MIG-P2-ORDER）。
     * 必须带 `production_on_token`；无 token 仍拒绝（防误触）。
     *
     * @param array<string, mixed> $checkpoint
     * @return array{ok:bool,mode:string,watermark:int,cutover_applied:bool}
     */
    public function executeCutover(array $checkpoint = []): array
    {
        $token = trim((string) ($checkpoint['production_on_token'] ?? ''));
        if ($token === '') {
            throw new OrderFacadeConflictException(
                self::ERROR_CUTOVER_NOT_AUTHORIZED,
                \__('实际 cutover 须由 TASK-MIG-P2-ORDER 携带 production_on_token'),
                ['checkpoint_keys' => array_keys($checkpoint)],
            );
        }
        $this->setMode(self::MODE_ON, [], $token);
        $this->cutoverApplied = true;
        $this->watermark = (int) ($checkpoint['watermark'] ?? 0);

        return [
            'ok' => true,
            'mode' => self::MODE_ON,
            'watermark' => $this->watermark,
            'cutover_applied' => true,
        ];
    }

    /**
     * Safe UI/reader rollback：可回 shadow；已有新单且已 cutover 时拒绝回 off。
     * 绝不恢复旧 writer（DEC-023）。
     *
     * @return array{ok:bool,mode:string}
     */
    public function rollbackUiMode(string $targetMode, bool $hasNewOrders): array
    {
        if ($targetMode === self::MODE_ON) {
            throw new OrderFacadeConflictException(
                self::ERROR_UNKNOWN_MODE,
                \__('rollback 不得直接设为 on'),
            );
        }
        if ($hasNewOrders && $targetMode === self::MODE_OFF && $this->cutoverApplied) {
            throw new OrderFacadeConflictException(
                self::ERROR_ROLLBACK_HIDES_NEW,
                \__('已有新 Order 时拒绝会混淆事实源的 unsafe rollback；可切 shadow，且绝不恢复旧 writer'),
            );
        }
        $this->setMode($targetMode);

        return ['ok' => true, 'mode' => $this->mode()];
    }

    /** DEC-023：禁止通过回滚恢复旧 writer。 */
    public function forbidLegacyWriterRollback(): never
    {
        throw new OrderFacadeConflictException(
            self::ERROR_ROLLBACK_LEGACY,
            \__('禁止回滚恢复旧 Checkout writer'),
        );
    }
}
