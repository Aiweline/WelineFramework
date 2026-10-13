<?php

declare(strict_types=1);

namespace Weline\Cron\Service;

/**
 * Lightweight host capacity facts for Cron soft admission.
 *
 * Kept inside Weline_Cron (no Server module coupling). Tests inject overrides.
 */
final class HostCapacityProbe
{
    public function __construct(
        private readonly ?int $cpuCoresOverride = null,
        private readonly ?float $loadAvg1Override = null,
    ) {
    }

    public function cpuCores(): int
    {
        if ($this->cpuCoresOverride !== null && $this->cpuCoresOverride >= 1) {
            return $this->cpuCoresOverride;
        }

        $n = 0;
        if (\function_exists('ini_get')) {
            $ini = (string)\ini_get('weline.cpu_cores');
            if ($ini !== '' && \ctype_digit($ini)) {
                $n = (int)$ini;
            }
        }
        if ($n < 1 && \is_readable('/proc/cpuinfo')) {
            $raw = @\file_get_contents('/proc/cpuinfo');
            if (\is_string($raw) && $raw !== '') {
                $n = \preg_match_all('/^processor\s*:/m', $raw);
            }
        }
        if ($n < 1 && \function_exists('shell_exec') && \PHP_OS_FAMILY === 'Darwin') {
            $out = @\shell_exec('sysctl -n hw.ncpu 2>/dev/null');
            if (\is_string($out) && \ctype_digit(\trim($out))) {
                $n = (int)\trim($out);
            }
        }
        if ($n < 1) {
            $n = 1;
        }

        return \max(1, $n);
    }

    /**
     * 1-minute load average, or null when unavailable.
     */
    public function loadAvg1(): ?float
    {
        if ($this->loadAvg1Override !== null) {
            return $this->loadAvg1Override;
        }
        if (!\function_exists('sys_getloadavg')) {
            return null;
        }
        $loads = @\sys_getloadavg();
        if (!\is_array($loads) || !isset($loads[0])) {
            return null;
        }

        return (float)$loads[0];
    }
}
