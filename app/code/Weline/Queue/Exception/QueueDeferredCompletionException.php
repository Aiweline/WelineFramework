<?php

declare(strict_types=1);

namespace Weline\Queue\Exception;

/**
 * Expected control signal for a Queue consumer that must release its current
 * Worker generation and return the same Queue row to Scheduler ownership.
 *
 * Consumers must not persist running -> pending themselves. Run catches this
 * signal and delegates one fenced, atomic transition to QueueDispatchService.
 */
final class QueueDeferredCompletionException extends \RuntimeException
{
    public function __construct(
        private readonly string $queueContent,
        private readonly string $processMessage,
        private readonly string $queueResult,
        private readonly string $notBefore,
    ) {
        if (!self::isValidUtcNotBefore($notBefore)) {
            throw new \InvalidArgumentException('queue_deferred_not_before_invalid');
        }
        parent::__construct($queueResult !== '' ? $queueResult : $processMessage);
    }

    public function queueContent(): string
    {
        return $this->queueContent;
    }

    public function processMessage(): string
    {
        return $this->processMessage;
    }

    public function queueResult(): string
    {
        return $this->queueResult;
    }

    public function notBefore(): string
    {
        return $this->notBefore;
    }

    private static function isValidUtcNotBefore(string $notBefore): bool
    {
        if (\preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $notBefore) !== 1) {
            return false;
        }
        $value = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $notBefore,
            new \DateTimeZone('UTC')
        );
        $errors = \DateTimeImmutable::getLastErrors();

        return $value instanceof \DateTimeImmutable
            && (!\is_array($errors)
                || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))
            && $value->format('Y-m-d H:i:s') === $notBefore;
    }
}
