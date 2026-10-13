<?php

declare(strict_types=1);

namespace Weline\Deploy\Extends\Module\Weline_Framework\Fpc\Bypass;

use Weline\Deploy\Service\DeployWebhookRouteService;
use Weline\Framework\Http\Fpc\FpcBypassRuleProviderInterface;

/**
 * Deploy 公网 Webhook（/~wh~{random}）：WLS 下须 FPC 签章才 bypass；FPM 另走硬排除。
 */
final class DeployWebhookFpcBypassProvider implements FpcBypassRuleProviderInterface
{
    public function rules(): array
    {
        $marker = DeployWebhookRouteService::MARKER;

        return [
            [
                'id' => 'deploy.webhook_path_prefix',
                'match' => [
                    'require_pierce_stamp' => true,
                    'uri_path_prefixes' => ['/' . $marker],
                ],
                'effect' => 'bypass_serve_and_publish',
            ],
        ];
    }
}
