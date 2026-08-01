<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Server\Service\Edge\Gateway\GatewaySensitivePayloadSanitizer;

/**
 * Defense-in-depth redaction for gateway command output.
 *
 * A project can communicate with an older, protocol-compatible host slot
 * which predates controller-side response sanitization. Command output must
 * never make host or project credentials observable in that situation.
 */
final class GatewayOutputSanitizer
{
    public static function sanitize(mixed $value): mixed
    {
        return GatewaySensitivePayloadSanitizer::sanitize($value);
    }
}
