<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Async;

final class AsyncEventDiagnostics
{
    public function providerNotConfigured(string $eventName): void
    {
        $key = $this->key('not_configured', $eventName);
        $cache = w_cache('event_async_diagnostic');
        if ($cache->getCustom($key) !== null) {
            return;
        }
        $cache->setCustom($key, 1, 3600);
        w_log_warning(
            'event_async_skipped_not_configured',
            [
                'instance' => $this->instance(),
                'error_code' => 'transport_not_configured',
            ],
            'event_async.log',
        );
    }

    public function providerUnavailable(string $eventName, string $errorCode, string $error): void
    {
        unset($error);
        $errorCode = $this->errorCode($errorCode);
        $cache = w_cache('event_async_diagnostic');
        $cache->setCustom($this->stateKey($eventName), 'unavailable', 3600);
        $key = $this->key('unavailable_' . $errorCode, $eventName);
        if ($cache->getCustom($key) !== null) {
            return;
        }
        $cache->setCustom($key, 1, 3600);
        w_log_error(
            'event_async_provider_unavailable',
            [
                'instance' => $this->instance(),
                'error_code' => $errorCode,
            ],
            'event_async.log',
        );
    }

    public function providerAvailable(string $eventName): void
    {
        $cache = w_cache('event_async_diagnostic');
        $stateKey = $this->stateKey($eventName);
        if ($cache->getCustom($stateKey) !== 'unavailable') {
            return;
        }
        $cache->setCustom($stateKey, 'available', 3600);
        w_log_info(
            'event_async_provider_recovered',
            ['instance' => $this->instance()],
            'event_async.log',
        );
    }

    private function key(string $state, string $eventName): string
    {
        return hash('sha256', $this->instance() . '|' . getmypid() . '|' . $state . '|' . $eventName);
    }

    private function stateKey(string $eventName): string
    {
        return $this->key('provider_state', $eventName);
    }

    private function instance(): string
    {
        return substr((string)\Weline\Framework\Env\WelineEnv::get('wls.instance_name', 'fpm'), 0, 191);
    }

    private function errorCode(string $errorCode): string
    {
        $errorCode = strtolower(trim($errorCode));
        if (!preg_match('/^[a-z0-9_.:-]{1,64}$/', $errorCode)) {
            return 'transport_unavailable';
        }
        return $errorCode;
    }
}
