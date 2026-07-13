<?php

declare(strict_types=1);

namespace Weline\DbManager\Integration\Server;

use Weline\Server\Api\Panel\WlsPanelOperationDefinitionProviderInterface;

final class WlsPanelOperationDefinitionProvider implements WlsPanelOperationDefinitionProviderInterface
{
    public function definition(): array
    {
        return ['key' => 'database-profile', 'module' => 'Weline_DbManager'];
    }
}
