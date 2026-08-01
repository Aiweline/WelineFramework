<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandAbstract;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;

abstract class AbstractGatewayCommand extends CommandAbstract
{
    protected const OUTPUT_SCHEMA = 'wls-gateway-command/1';

    protected function gateway(): GatewayHostManager
    {
        return new GatewayHostManager();
    }

    /**
     * @param array<string,mixed> $payload
     */
    protected function output(
        array $payload,
        bool $json,
        bool $ok = true,
        array $error = [],
    ): void
    {
        $sanitized = GatewayOutputSanitizer::sanitize($payload);
        $payload = \is_array($sanitized) ? $sanitized : [];
        if ($json) {
            $document = [
                'schema' => self::OUTPUT_SCHEMA,
                'ok' => $ok,
                'payload' => $payload,
            ];
            if (!$ok) {
                $normalizedError = GatewayOutputSanitizer::sanitize(
                    $this->normalizeError($error),
                );
                $document['error'] = \is_array($normalizedError)
                    ? $normalizedError
                    : $this->normalizeError([]);
            }
            echo \json_encode(
                $document,
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

    /**
     * Emit exactly one machine-readable document in JSON mode.
     *
     * @param array<string,mixed> $details
     */
    protected function failure(
        string $message,
        bool $json,
        string $code = 'command_failed',
        array $details = [],
    ): int {
        if ($json) {
            $this->output([], true, false, [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ]);
        } else {
            $this->printer->error($message);
        }
        return 1;
    }

    protected function isJson(array $args): bool
    {
        return isset($args['json']);
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

    /**
     * @param array<string,mixed> $error
     * @return array{code:string,message:string,details:array<string,mixed>}
     */
    private function normalizeError(array $error): array
    {
        $details = $error['details'] ?? [];
        return [
            'code' => \trim((string)($error['code'] ?? 'command_failed')) ?: 'command_failed',
            'message' => \trim((string)($error['message'] ?? __('命令执行失败。')))
                ?: __('命令执行失败。'),
            'details' => \is_array($details) ? $details : [],
        ];
    }
}
