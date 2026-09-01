<?php

declare(strict_types=1);

/**
 * Parse the portable `ps -o etime=` format: [[days-]hours:]minutes:seconds.
 */
function welineGuidanceElapsedSeconds(string $value): ?int
{
    $value = trim($value);
    if (preg_match('/^(?:(\d+)-)?(?:(\d{1,2}):)?(\d{2}):(\d{2})$/D', $value, $matches) !== 1) {
        return null;
    }

    $days = ($matches[1] ?? '') === '' ? 0 : (int) $matches[1];
    $hours = ($matches[2] ?? '') === '' ? 0 : (int) $matches[2];
    $minutes = (int) $matches[3];
    $seconds = (int) $matches[4];
    if ($minutes >= 60 || $seconds >= 60 || ($days > 0 && (($matches[2] ?? '') === '' || $hours >= 24))) {
        return null;
    }

    return ($days * 86_400) + ($hours * 3_600) + ($minutes * 60) + $seconds;
}

function welineGuidanceStartedEpochFromElapsed(string $elapsed, int $observedAt): int
{
    $seconds = welineGuidanceElapsedSeconds($elapsed);
    if ($seconds === null || $observedAt <= 0 || $seconds > $observedAt) {
        return 0;
    }

    return $observedAt - $seconds;
}
