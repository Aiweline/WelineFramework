<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Runtime;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\FrontendWorkerBackendAttestationProviderInterface;
use Weline\Framework\Runtime\FrontendWorkerBackendAuthorizationProviderInterface;
use Weline\Framework\Runtime\RequestAuthority;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Resumable\ResumableTaskAccessDeniedException;
use Weline\Framework\Runtime\Resumable\TaskOwner;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Service\Query\Value\FrontendWorkerExecutionContext;

/** Enforces immutable type/area/source policy before task access or mutation. */
final class ResumableTaskAccessPolicy
{
    public function __construct(
        private readonly ResumableTaskHandlerRegistry $handlers,
        private readonly ?RuntimeProviderResolver $runtimeProviderResolver = null,
    ) {
    }

    /** @throws ResumableTaskAccessDeniedException */
    public function assertAllowed(
        TaskOwner $owner,
        string $typeCode,
        string $operation,
    ): ResumableTaskTypeDefinition {
        if (!\in_array($operation, ['start', 'status', 'touch', 'cancel', 'events'], true)) {
            throw $this->denied();
        }
        try {
            $definition = $this->handlers->definition($typeCode);
        } catch (\Throwable $exception) {
            throw $this->denied($exception);
        }
        if (!\in_array($owner->area, $definition->allowedAreas, true)) {
            throw $this->denied();
        }

        $executionContext = RequestContext::get(FrontendWorkerExecutionContext::REQUEST_CONTEXT_KEY);
        if (!$executionContext instanceof FrontendWorkerExecutionContext
            || !\hash_equals($owner->area, $executionContext->area)) {
            throw $this->denied();
        }
        if ($owner->area === FrontendWorkerExecutionContext::AREA_FRONTEND) {
            return $definition;
        }

        $binding = $executionContext->backendBinding;
        $sourceId = $definition->backendAclSourceId;
        if ($owner->area !== FrontendWorkerExecutionContext::AREA_BACKEND
            || $binding === null
            || $sourceId === null
            || !\hash_equals('backend:' . $binding->backendUserId, $owner->principal)) {
            throw $this->denied();
        }

        try {
            $resolver = $this->runtimeProviderResolver
                ?? ObjectManager::getInstance(RuntimeProviderResolver::class);
            $attestation = $resolver->resolveDetailed(
                FrontendWorkerBackendAttestationProviderInterface::class,
            );
            if (!$attestation->isAvailable()
                || !$attestation->provider instanceof FrontendWorkerBackendAttestationProviderInterface) {
                throw $this->denied();
            }
            $authorityHost = RequestAuthority::current();
            if ($authorityHost === '') {
                throw $this->denied();
            }
            $restored = $attestation->provider->restoreBinding($binding, $authorityHost);
            if (!\hash_equals($binding->digest(), $restored->digest())) {
                throw $this->denied();
            }

            $authorization = $resolver->resolveDetailed(
                FrontendWorkerBackendAuthorizationProviderInterface::class,
            );
            if (!$authorization->isAvailable()
                || !$authorization->provider instanceof FrontendWorkerBackendAuthorizationProviderInterface) {
                throw $this->denied();
            }
            $authorization->provider->assertSourceAllowed(
                $restored,
                $sourceId,
                'runtime_task',
                $typeCode . '.' . $operation,
            );
        } catch (ResumableTaskAccessDeniedException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw $this->denied($exception);
        }

        return $definition;
    }

    private function denied(?\Throwable $previous = null): ResumableTaskAccessDeniedException
    {
        return new ResumableTaskAccessDeniedException(
            'Runtime task access policy denied the request.',
            0,
            $previous,
        );
    }
}
