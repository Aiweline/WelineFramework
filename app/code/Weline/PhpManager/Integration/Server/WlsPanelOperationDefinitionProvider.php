<?php

declare(strict_types=1);

namespace Weline\PhpManager\Integration\Server;

use Weline\Server\Api\Panel\WlsPanelOperationDefinitionProviderInterface;

final class WlsPanelOperationDefinitionProvider implements WlsPanelOperationDefinitionProviderInterface
{
    public function definition(): array
    {
        return ['key' => 'php-profile', 'module' => 'Weline_PhpManager'];
    }
}
