<?php
declare(strict_types=1);

return [
    \Weline\Server\Service\Provider\SessionServerProvider::class,
    \Weline\Server\Service\Provider\MemoryServerProvider::class,
    \Weline\Server\Service\Provider\WorkerProvider::class,
    \Weline\Server\Service\Provider\DispatcherProvider::class,
    \Weline\Server\Service\Provider\HttpRedirectProvider::class,
    \Weline\Server\Service\Provider\MaintenanceWorkerProvider::class,
];
