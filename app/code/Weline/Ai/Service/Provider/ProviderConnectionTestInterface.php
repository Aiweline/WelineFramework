<?php
declare(strict_types=1);

namespace Weline\Ai\Service\Provider;

use Weline\Ai\Model\AiModel;

interface ProviderConnectionTestInterface
{
    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function testConnection(AiModel $model, array $params = []): array;
}
