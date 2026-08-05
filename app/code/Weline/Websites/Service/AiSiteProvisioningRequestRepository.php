<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Websites\Model\AiSiteProvisioningRequest;

class AiSiteProvisioningRequestRepository
{
    public function __construct(private readonly AiSiteProvisioningRequest $requestModel)
    {
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): AiSiteProvisioningRequest
    {
        $request = $this->newRequest();
        $request->setData($data);
        $savedId = (int)$request->save();
        if ($request->getId() <= 0 && $savedId > 0) {
            $request->setData(AiSiteProvisioningRequest::schema_fields_ID, $savedId);
        }

        return $request;
    }

    public function save(AiSiteProvisioningRequest $request): AiSiteProvisioningRequest
    {
        $request->save();

        return $request;
    }

    public function findByRequestId(string $requestId): ?AiSiteProvisioningRequest
    {
        $requestId = \trim($requestId);
        if ($requestId === '') {
            return null;
        }

        $request = $this->newRequest();
        $request->where(AiSiteProvisioningRequest::schema_fields_REQUEST_ID, $requestId)
            ->find()
            ->fetch();

        return $request->getId() > 0 ? $request : null;
    }

    public function findByCommand(string $sourceModule, string $clientRequestId): ?AiSiteProvisioningRequest
    {
        $sourceModule = \trim($sourceModule);
        $clientRequestId = \trim($clientRequestId);
        if ($sourceModule === '' || $clientRequestId === '') {
            return null;
        }

        $request = $this->newRequest();
        $request->where(AiSiteProvisioningRequest::schema_fields_SOURCE_MODULE, $sourceModule)
            ->where(AiSiteProvisioningRequest::schema_fields_CLIENT_REQUEST_ID, $clientRequestId)
            ->find()
            ->fetch();

        return $request->getId() > 0 ? $request : null;
    }

    private function newRequest(): AiSiteProvisioningRequest
    {
        return (clone $this->requestModel)->clearData()->clearQuery();
    }
}
