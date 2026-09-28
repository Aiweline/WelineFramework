<?php

declare(strict_types=1);

namespace Weline\Cdn\Extends\Module\Weline_Framework\Fpc\Bypass;

use Weline\Framework\Http\Fpc\FpcBypassRuleProviderInterface;

/**
 * CDN Scope 开发模式：facts.env.cdn_fpc_dev_mode=1 时 bypass 公共 FPC。
 */
final class CdnFpcDevModeBypassProvider implements FpcBypassRuleProviderInterface
{
    public function rules(): array
    {
        return [
            [
                'id' => 'cdn.fpc_dev_mode',
                'match' => [
                    'env_flags' => ['cdn_fpc_dev_mode'],
                ],
                'effect' => 'bypass_serve_and_publish',
            ],
        ];
    }
}
