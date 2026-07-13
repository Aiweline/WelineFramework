<?php

declare(strict_types=1);

namespace Weline\Deploy\Integration\Server;

use Weline\Server\Api\Panel\WlsPanelOperationDefinitionProviderInterface;

final class WlsPanelOperationDefinitionProvider implements WlsPanelOperationDefinitionProviderInterface
{
    public function definition(): array
    {
        return ['key' => 'deploy', 'module' => 'Weline_Deploy'];
    }
}
