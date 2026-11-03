<?php

declare(strict_types=1);

namespace Weline\Mail\Service\Relay;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Api\Relay\RelaySecretInterface;
use Weline\Mail\Service\MailOutboundRelaySecretService;

/**
 * Adapter exposing relay secret sealing through the public Mail API.
 */
final class RelaySecret implements RelaySecretInterface
{
    public function seal(string $plain): string
    {
        return ObjectManager::getInstance(MailOutboundRelaySecretService::class)->seal($plain);
    }

    public function open(string $sealedB64): string
    {
        return ObjectManager::getInstance(MailOutboundRelaySecretService::class)->open($sealedB64);
    }
}
