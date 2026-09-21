<?php

declare(strict_types=1);

namespace Weline\Server\Extends\Module\Weline_Framework\Fpc\Bypass;

use Weline\Framework\Http\Fpc\FpcBypassRuleProviderInterface;

/**
 * WLS 传输层显式 bypass 头（适配器声明，非业务）。
 */
final class WlsTransportFpcBypassProvider implements FpcBypassRuleProviderInterface
{
    public function rules(): array
    {
        return [
            [
                'id' => 'wls.transport.fpc_bypass_headers',
                'match' => [
                    'request_headers' => [
                        'x-wls-fpc-bypass',
                        'x-wls-internal-fpc-bypass',
                        'x-wls-dynamic-warmup',
                        'x-wls-internal-dynamic-warmup',
                        'x-wls-dynamic-benchmark',
                        'x-wls-fpc-prime',
                        'wls-fpc-bypass',
                        'wls-internal-dynamic-warmup',
                        'http-x-wls-fpc-bypass',
                        'http-x-wls-dynamic-warmup',
                        'http-x-wls-dynamic-benchmark',
                    ],
                ],
                'effect' => 'bypass_serve_and_publish',
            ],
        ];
    }
}
