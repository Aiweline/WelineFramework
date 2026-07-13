<?php

declare(strict_types=1);

namespace Weline\FileManager\Integration\Server;

use Weline\Server\Api\Panel\WlsPanelOperationDefinitionProviderInterface;

final class WlsPanelOperationDefinitionProvider implements WlsPanelOperationDefinitionProviderInterface
{
    public function definition(): array
    {
        return ['key' => 'file-manager', 'module' => 'Weline_FileManager'];
    }
}
