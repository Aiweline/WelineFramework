<?php

declare(strict_types=1);

namespace Weline\Server\Api\Panel;

/** Optional WLS panel operation metadata contribution. */
interface WlsPanelOperationDefinitionProviderInterface
{
    /** @return array{key:string,module:string} */
    public function definition(): array;
}
