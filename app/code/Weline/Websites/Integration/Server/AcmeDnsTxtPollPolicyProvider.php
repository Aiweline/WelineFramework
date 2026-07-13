<?php

declare(strict_types=1);

namespace Weline\Websites\Integration\Server;

use Weline\Framework\App\Env;
use Weline\Server\Api\Tls\AcmeDnsTxtPollPolicyProviderInterface;

final class AcmeDnsTxtPollPolicyProvider implements AcmeDnsTxtPollPolicyProviderInterface
{
    public function getPolicy(): array
    {
        $config = Env::module_env('Weline_Websites', 'acme_dns') ?? [];
        if (!\is_array($config)) {
            $config = [];
        }

        $policy = [
            'max_seconds' => (int)($config['txt_poll_max_seconds'] ?? 900),
            'interval_seconds' => (int)($config['txt_poll_interval_seconds'] ?? 10),
            'visible_use_public_doh' => !\array_key_exists('txt_visible_use_public_doh', $config)
                ? true
                : !empty($config['txt_visible_use_public_doh']),
        ];
        if (\array_key_exists('txt_poll_max_seconds_gname', $config)) {
            $policy['max_seconds_gname'] = (int)$config['txt_poll_max_seconds_gname'];
        }
        if (\array_key_exists('txt_poll_max_seconds_cloudflare', $config)) {
            $policy['max_seconds_cloudflare'] = (int)$config['txt_poll_max_seconds_cloudflare'];
        }

        return $policy;
    }
}
