<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

/** Acceptance-only UTC clock guarded by an explicit, fail-closed environment opt-in. */
final class EnvironmentFrozenClock implements ClockInterface
{
    private const ACCEPTANCE_ENVIRONMENT_KEY = 'WELINE_SEO_ACCEPTANCE_MODE';
    private const FROZEN_NOW_ENVIRONMENT_KEY = 'WELINE_SEO_TEST_FROZEN_NOW';

    private readonly \DateTimeImmutable $frozenNow;

    /**
     * The no-argument constructor is intentional: module providers must be
     * instantiable without asking the ObjectManager to autowire DateTimeImmutable.
     */
    public function __construct()
    {
        $frozenNow = self::resolveEnvironmentNow();
        if (!$frozenNow instanceof \DateTimeImmutable) {
            throw new \LogicException(
                'EnvironmentFrozenClock requires WELINE_SEO_ACCEPTANCE_MODE=1 and a valid WELINE_SEO_TEST_FROZEN_NOW.'
            );
        }

        $this->frozenNow = $frozenNow->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function fromEnvironment(): ?self
    {
        try {
            return new self();
        } catch (\LogicException) {
            return null;
        }
    }

    private static function resolveEnvironmentNow(): ?\DateTimeImmutable
    {
        if (\getenv(self::ACCEPTANCE_ENVIRONMENT_KEY) !== '1') {
            return null;
        }

        $raw = \trim((string)(\getenv(self::FROZEN_NOW_ENVIRONMENT_KEY) ?: ''));
        if ($raw === '') {
            return null;
        }

        try {
            $frozenNow = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }

        $parseErrors = \DateTimeImmutable::getLastErrors();
        if (\is_array($parseErrors)
            && ((int)($parseErrors['warning_count'] ?? 0) > 0 || (int)($parseErrors['error_count'] ?? 0) > 0)
        ) {
            return null;
        }

        return $frozenNow;
    }

    public function now(): \DateTimeImmutable
    {
        return $this->frozenNow;
    }
}
