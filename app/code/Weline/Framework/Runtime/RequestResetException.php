<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Reports one or more request-boundary cleanup failures after every cleanup
 * step that could still run has been attempted.
 */
final class RequestResetException extends \RuntimeException
{
    /** @var list<array{stage: string, exception: \Throwable}> */
    private array $failures;

    /**
     * @param list<array{stage: string, exception: \Throwable}> $failures
     */
    public function __construct(
        private readonly string $boundary,
        array $failures,
    ) {
        if ($failures === []) {
            throw new \InvalidArgumentException('Request reset failure list must not be empty.');
        }

        $this->failures = \array_values($failures);
        $summary = \array_map(
            static fn (array $failure): string => $failure['stage']
                . '=' . $failure['exception']::class
                . '(' . $failure['exception']->getMessage() . ')',
            $this->failures,
        );

        parent::__construct(
            'Request reset boundary ' . $boundary . ' failed in '
            . \count($this->failures) . ' stage(s): ' . \implode('; ', $summary),
            0,
            $this->failures[0]['exception'],
        );
    }

    /**
     * Append a failure while preserving the detailed stages of a nested reset
     * exception.
     *
     * @param list<array{stage: string, exception: \Throwable}> $failures
     */
    public static function append(array &$failures, string $stage, \Throwable $exception): void
    {
        if ($exception instanceof self) {
            foreach ($exception->failures() as $nested) {
                $failures[] = [
                    'stage' => $stage . '/' . $nested['stage'],
                    'exception' => $nested['exception'],
                ];
            }
            return;
        }

        $failures[] = [
            'stage' => $stage,
            'exception' => $exception,
        ];
    }

    public function boundary(): string
    {
        return $this->boundary;
    }

    /** @return list<array{stage: string, exception: \Throwable}> */
    public function failures(): array
    {
        return $this->failures;
    }

    /** @return list<string> */
    public function stages(): array
    {
        return \array_column($this->failures, 'stage');
    }
}
