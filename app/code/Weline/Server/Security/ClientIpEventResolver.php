<?php

declare(strict_types=1);

namespace Weline\Server\Security;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * Dispatches optional vendor client-IP refinement after default XFF peel.
 *
 * Server owns the event and default identity; CDN (or other) modules may set
 * `client_ip` + `resolved_by` when transport is a trusted proxy. Failures and
 * untrusted peers always keep the default IP.
 */
final class ClientIpEventResolver
{
    public const EVENT = 'Weline_Server::security::resolve_client_ip';

    /**
     * @param array<string, string> $headers Lowercase header map
     * @param list<string> $trustedProxyCidrs
     * @return array{ip: string, resolved_by: string|null}
     */
    public static function refine(
        string $defaultClientIp,
        bool $trustedProxy,
        string $transportIp,
        array $headers,
        array $trustedProxyCidrs,
    ): array {
        if (!$trustedProxy) {
            return ['ip' => $defaultClientIp, 'resolved_by' => null];
        }

        try {
            $data = new DataObject([
                'transport_ip' => $transportIp,
                'headers' => $headers,
                'trusted_proxy' => true,
                'trusted_proxy_cidrs' => $trustedProxyCidrs,
                'client_ip' => $defaultClientIp,
                'resolved_by' => null,
            ]);
            /** @var EventsManager $events */
            $events = ObjectManager::getInstance(EventsManager::class);
            $events->dispatch(self::EVENT, $data);

            $resolvedBy = $data->getData('resolved_by');
            $candidate = \trim((string)$data->getData('client_ip'));
            if (!\is_string($resolvedBy) || $resolvedBy === '') {
                return ['ip' => $defaultClientIp, 'resolved_by' => null];
            }
            if ($candidate === '' || !\filter_var($candidate, \FILTER_VALIDATE_IP)) {
                return ['ip' => $defaultClientIp, 'resolved_by' => null];
            }

            return ['ip' => $candidate, 'resolved_by' => $resolvedBy];
        } catch (\Throwable) {
            return ['ip' => $defaultClientIp, 'resolved_by' => null];
        }
    }
}
