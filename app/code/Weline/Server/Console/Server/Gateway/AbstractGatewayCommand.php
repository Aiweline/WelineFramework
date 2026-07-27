<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandAbstract;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;

abstract class AbstractGatewayCommand extends CommandAbstract
{
    protected function gateway(): GatewayHostManager
    {
        return new GatewayHostManager();
    }

    /**
     * @param array<string,mixed> $payload
     */
    protected function output(array $payload, bool $json): void
    {
        if ($json) {
            echo \json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . PHP_EOL;
            return;
        }
        foreach ($payload as $key => $value) {
            if (\is_array($value)) {
                $value = \json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } elseif (\is_bool($value)) {
                $value = $value ? __('是') : __('否');
            } elseif ($value === null) {
                $value = '-';
            }
            $this->printer->note((string)$key . ': ' . (string)$value);
        }
    }

    protected function positional(array $args, int $position = 0, string $default = ''): string
    {
        $values = [];
        foreach ($args as $key => $value) {
            if (\is_int($key) && !\str_starts_with((string)$value, '-')) {
                $values[] = (string)$value;
            }
        }
        \array_shift($values);
        return \trim($values[$position] ?? $default);
    }
}
