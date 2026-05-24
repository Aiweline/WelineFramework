<?php
declare(strict_types=1);

namespace Weline\Server\Service\Contract;

use Weline\Server\Service\Runtime\WlsRuntimeProfile;

interface RuntimeAwareServiceProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getRuntimeRequirements(WlsRuntimeProfile $profile, ServiceContext $context): array;

    /**
     * @return array<string, mixed>
     */
    public function getRuntimeRecommendations(WlsRuntimeProfile $profile, ServiceContext $context): array;

    /**
     * @return array<string, mixed>
     */
    public function getResourceProfile(WlsRuntimeProfile $profile, ServiceContext $context): array;

    /**
     * @return array<string, mixed>
     */
    public function getRestartPolicy(WlsRuntimeProfile $profile, ServiceContext $context): array;
}
