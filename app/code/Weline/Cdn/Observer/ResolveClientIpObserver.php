<?php

declare(strict_types=1);

namespace Weline\Cdn\Observer;

use Weline\Cdn\Api\AdapterInterface;
use Weline\Cdn\Service\AdapterResolver;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * Listens to Weline_Server::security::resolve_client_ip.
 *
 * First adapter that returns a valid IP from vendor headers wins.
 * Does not ban; does not run when Server skipped the event (!trusted_proxy).
 */
class ResolveClientIpObserver implements ObserverInterface
{
    public function __construct(
        private readonly AdapterResolver $adapterResolver,
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!$data instanceof DataObject) {
            return;
        }
        if (!(bool)$data->getData('trusted_proxy')) {
            return;
        }

        $headers = $data->getData('headers');
        if (!\is_array($headers)) {
            return;
        }

        foreach ($this->adapterResolver->getAllAdapters() as $code => $adapter) {
            if (!$adapter instanceof AdapterInterface) {
                continue;
            }
            try {
                $ip = $adapter->resolveClientIpFromHeaders($headers);
            } catch (\Throwable) {
                continue;
            }
            if (!\is_string($ip) || $ip === '' || !\filter_var($ip, \FILTER_VALIDATE_IP)) {
                continue;
            }
            $data->setData('client_ip', $ip);
            $data->setData('resolved_by', (string)$code);

            return;
        }
    }
}
