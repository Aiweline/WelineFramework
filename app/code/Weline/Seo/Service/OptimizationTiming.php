<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

/** Single UTC timing policy for analyze, observation, expiry and cooldown. */
final class OptimizationTiming
{
    private const SQL_DATETIME = 'Y-m-d H:i:s';

    private readonly ClockInterface $clock;

    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock
            ?? EnvironmentFrozenClock::fromEnvironment()
            ?? new SystemClock();
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }

    public function format(\DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format(self::SQL_DATETIME);
    }

    /** @return array{start:string,end:string} */
    public function analysisWindow(int $days): array
    {
        $end = $this->now();
        $start = $end->modify('-' . \max(1, $days) . ' days');

        return [
            'start' => $this->format($start),
            'end' => $this->format($end),
        ];
    }

    /** @return array{applied_at:string,evaluate_after:string,expires_at:string} */
    public function observationWindow(int $minimumDays, int $maximumDays): array
    {
        $minimumDays = \max(1, $minimumDays);
        $maximumDays = \max($minimumDays, $maximumDays);
        $appliedAt = $this->now();

        return [
            'applied_at' => $this->format($appliedAt),
            'evaluate_after' => $this->format($appliedAt->modify('+' . $minimumDays . ' days')),
            'expires_at' => $this->format($appliedAt->modify('+' . $maximumDays . ' days')),
        ];
    }

    public function cooldownUntil(int $days): string
    {
        return $this->format($this->now()->modify('+' . \max(1, $days) . ' days'));
    }

    public function isFuture(string $value): bool
    {
        $time = $this->parse($value);
        return $time instanceof \DateTimeImmutable && $time > $this->now();
    }

    public function isExpired(string $value): bool
    {
        $time = $this->parse($value);
        return $time instanceof \DateTimeImmutable && $time <= $this->now();
    }

    private function parse(string $value): ?\DateTimeImmutable
    {
        $value = \trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }
}
