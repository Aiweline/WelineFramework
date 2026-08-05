<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

/**
 * 进程内 rollout gate（默认全部 off；可测、可注入）。
 */
final class CommerceRolloutGate implements CommerceRolloutGateInterface
{
    /** @var array<string, array{mode:string,allowlist:list<string>,prod_token:string}> */
    private array $states = [];

    public function mode(string $capability): string
    {
        return $this->states[$capability]['mode'] ?? self::MODE_OFF;
    }

    public function setMode(
        string $capability,
        string $mode,
        array $allowlistSubjects = [],
        string $productionOnToken = '',
    ): void {
        if (!\in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('commerce_rollout_unknown_mode:' . $mode);
        }
        if ($mode === self::MODE_ON && $productionOnToken === '') {
            throw new \InvalidArgumentException('commerce_rollout_on_requires_explicit_token');
        }
        $clean = [];
        foreach ($allowlistSubjects as $subject) {
            $subject = \trim((string)$subject);
            if ($subject !== '') {
                $clean[] = $subject;
            }
        }
        $this->states[$capability] = [
            'mode' => $mode,
            'allowlist' => \array_values(\array_unique($clean)),
            'prod_token' => $productionOnToken,
        ];
    }

    public function isShadow(string $capability): bool
    {
        return $this->mode($capability) === self::MODE_SHADOW;
    }

    public function isEffectivelyOn(string $capability, string $subject = ''): bool
    {
        $mode = $this->mode($capability);
        return match ($mode) {
            self::MODE_ON => true,
            self::MODE_ALLOWLIST => $subject !== ''
                && \in_array($subject, $this->states[$capability]['allowlist'] ?? [], true),
            default => false,
        };
    }

    public function assertMutable(string $capability, string $subject = ''): void
    {
        $mode = $this->mode($capability);
        if ($mode === self::MODE_OFF || $mode === self::MODE_SHADOW) {
            throw new \RuntimeException('commerce_rollout_immutable:' . $mode);
        }
        if ($mode === self::MODE_ALLOWLIST && !$this->isEffectivelyOn($capability, $subject)) {
            throw new \RuntimeException('commerce_rollout_subject_not_allowlisted');
        }
        if ($mode === self::MODE_ON && ($this->states[$capability]['prod_token'] ?? '') === '') {
            throw new \RuntimeException('commerce_rollout_on_unauthorized');
        }
    }
}
