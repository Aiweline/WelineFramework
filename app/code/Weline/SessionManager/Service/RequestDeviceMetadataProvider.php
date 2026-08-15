<?php

declare(strict_types=1);

namespace Weline\SessionManager\Service;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\SessionManager\Api\DeviceMetadataProviderInterface;
use Weline\SessionManager\Data\DeviceMetadata;

final class RequestDeviceMetadataProvider implements DeviceMetadataProviderInterface
{
    public function __construct(
        private readonly DeviceMetadataParser $parser,
    ) {
    }

    public function current(): DeviceMetadata
    {
        // Runtime providers are process-lived under WLS. Resolve the request at
        // call time so concurrent Fibers cannot reuse constructor-time UA/IP.
        $request = ObjectManager::getInstance(Request::class);
        return $this->parser->parse(
            $request->getServerBag()->getUserAgent(),
            (string)$request->clientIP(),
        );
    }
}
