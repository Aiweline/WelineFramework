<?php

declare(strict_types=1);

namespace Weline\FileManager\Observer;

use Weline\FileManager\Service\MediaReference\MediaReferenceScopeResolver;
use Weline\FileManager\Service\MediaReference\MediaReferenceService;
use Weline\Framework\Api\Event\AsyncObserverInterface;
use Weline\Framework\Event\Async\Exception\NonRetryableAsyncEventException;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ResourceChange\ResourceChange;

/**
 * On entity delete/update with resource.code, unbind media refs (never delete files).
 */
final class MediaReferenceResourceChanged implements AsyncObserverInterface
{
    public function __construct(
        private readonly MediaReferenceService $mediaReferences,
        private readonly MediaReferenceScopeResolver $scopes,
    ) {
    }

    public function supportsAsyncEvent(string $eventName, int $schemaVersion): bool
    {
        return $eventName === ResourceChange::EVENT_NAME
            && $schemaVersion === ResourceChange::SCHEMA_VERSION;
    }

    public function execute(Event &$event): void
    {
        $change = $event->getData('data');
        if (!$change instanceof ResourceChange) {
            throw new NonRetryableAsyncEventException(
                'resource_change_contract_mismatch',
                (string)__('FileManager MediaReference Observer 只接受 v1 契约'),
            );
        }
        $action = strtolower(trim($change->action()));
        if ($action !== 'delete') {
            return;
        }
        $type = strtolower(trim($change->resourceType()));
        $code = trim((string)($change->resourceCode() ?? ''));
        if ($type === '' || $code === '') {
            return;
        }
        $scope = trim((string)($change->resourceScope() ?? ''));
        if ($scope === '') {
            $scope = (string)($this->scopes->fromContext() ?? '');
        }
        if ($scope === '') {
            $website = trim((string)$change->websiteCode());
            if ($website !== '') {
                // Best-effort: website.default.default when only website known
                $candidate = strtolower($website) . '.default.default';
                if ($this->scopes->isValidStorageScope($candidate)) {
                    $scope = $candidate;
                }
            }
        }
        if ($scope === '' || !$this->scopes->isValidStorageScope($scope)) {
            return;
        }
        $this->mediaReferences->unbindWhere($type, $scope, $code);
    }
}
