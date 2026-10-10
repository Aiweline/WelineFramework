<?php
declare(strict_types=1);

namespace Weline\Server\Service\Capacity;

/**
 * Master capacity decision produced by CapacityAdvisor / pressure controller.
 * Never mutates Desired by itself — CapacityScaleApplier is the sole writer.
 */
final class CapacityIntent
{
    public const ACTION_SCALE_TO = 'scale_to';
    public const ACTION_NOOP = 'noop';

    /**
     * @param list<string> $advice
     * @param list<string> $reasonCodes
     * @param array<string, mixed> $sample
     */
    public function __construct(
        public readonly string $action,
        public readonly ?int $target,
        public readonly string $reason,
        public readonly string $instance,
        public readonly array $advice = [],
        public readonly array $reasonCodes = [],
        public readonly array $sample = [],
        public readonly int $priority = 0,
    ) {
    }

    /**
     * @param list<string> $advice
     * @param list<string> $reasonCodes
     * @param array<string, mixed> $sample
     */
    public static function noop(
        string $instance,
        string $reason,
        array $advice = [],
        array $reasonCodes = [],
        array $sample = [],
        int $priority = 0,
    ): self {
        return new self(
            self::ACTION_NOOP,
            null,
            $reason,
            $instance,
            $advice,
            $reasonCodes,
            $sample,
            $priority,
        );
    }

    /**
     * @param list<string> $advice
     * @param list<string> $reasonCodes
     * @param array<string, mixed> $sample
     */
    public static function scaleTo(
        string $instance,
        int $target,
        string $reason,
        array $advice = [],
        array $reasonCodes = [],
        array $sample = [],
        int $priority = 0,
    ): self {
        return new self(
            self::ACTION_SCALE_TO,
            \max(1, $target),
            $reason,
            $instance,
            $advice,
            $reasonCodes,
            $sample,
            $priority,
        );
    }

    public function isScale(): bool
    {
        return $this->action === self::ACTION_SCALE_TO && $this->target !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'target' => $this->target,
            'reason' => $this->reason,
            'instance' => $this->instance,
            'advice' => $this->advice,
            'reason_codes' => $this->reasonCodes,
            'sample' => $this->sample,
            'priority' => $this->priority,
        ];
    }
}
