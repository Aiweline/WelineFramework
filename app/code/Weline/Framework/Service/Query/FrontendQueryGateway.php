<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query;

use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\FrontendWorkerBackendAuthorizationException;
use Weline\Framework\Runtime\FrontendWorkerBackendAuthorizationProviderInterface;
use Weline\Framework\Runtime\FrontendWorkerBackendAttestationException;
use Weline\Framework\Runtime\FrontendWorkerBackendAttestationProviderInterface;
use Weline\Framework\Runtime\FrontendWorkerScopeException;
use Weline\Framework\Runtime\FrontendWorkerScopeProviderInterface;
use Weline\Framework\Runtime\RequestAuthority;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RuntimeProviderResolution;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Service\Query\Value\FrontendWorkerBackendAcl;
use Weline\Framework\Service\Query\Value\FrontendWorkerBackendBinding;
use Weline\Framework\Service\Query\Value\FrontendWorkerExecutionContext;
use Weline\Framework\Service\Query\Value\FrontendWorkerScopeBinding;
use Weline\Framework\Session\SessionFactory;

final class FrontendQueryGateway
{
    public const STREAM_OWNER_CONTEXT_KEY = 'frontend_worker.stream_owner';

    private const GRAPH_MAX_COST = 20;
    private const GRAPH_MAX_OPERATIONS = 10;
    private const AUTH_MODES = ['any', 'guest', 'customer', 'backend'];

    public function __construct(
        private readonly FrameworkQueryService $queryService,
        private readonly QueryProviderRegistry $registry,
        private readonly FrontendWorkerSessionService $workerSessionService,
        private readonly ?RuntimeProviderResolver $runtimeProviderResolver = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(
        array $payload,
        string $capability,
        ?FrontendWorkerScopeBinding $scopeBinding = null,
        ?FrontendWorkerExecutionContext $executionContext = null,
    ): mixed
    {
        $executionContext = $this->normalizeExecutionContext($scopeBinding, $executionContext);
        $this->installExecutionContext($executionContext);
        $type = (string)($payload['type'] ?? 'call');

        return match ($type) {
            'call' => $this->executeCall($payload, $capability, $executionContext),
            'graph' => $this->executeGraph($payload, $capability, $executionContext),
            'stream-ticket' => $this->createStreamTicket($payload, $capability, $executionContext),
            default => throw new FrontendQueryException('protocol_error', 'Unsupported worker query type.', 400),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function executeCall(
        array $payload,
        string $capability,
        FrontendWorkerExecutionContext $executionContext,
    ): mixed
    {
        $stepStart = \microtime(true);
        $provider = (string)($payload['provider'] ?? '');
        $operation = (string)($payload['operation'] ?? '');
        $params = $this->normalizeParams($payload['params'] ?? []);
        $this->recordGatewayStep('call_normalize', $stepStart, [
            'provider' => $provider,
            'operation' => $operation,
        ]);

        $stepStart = \microtime(true);
        $descriptor = $this->requireOperation($provider, $operation);
        $this->recordGatewayStep('require_operation', $stepStart);

        $stepStart = \microtime(true);
        $expectedCapability = $provider . '.' . $operation;
        if ($capability !== $expectedCapability) {
            throw new FrontendQueryException('capability_denied', 'Worker capability does not match operation.', 403);
        }

        $mode = (string)($descriptor['mode'] ?? '');
        if ($mode === 'stream') {
            throw new FrontendQueryException('capability_denied', 'Stream operation requires Weline.Api.stream().', 403);
        }
        $this->recordGatewayStep('capability_check', $stepStart);

        $stepStart = \microtime(true);
        $params = $this->validateParams($params, $descriptor);
        $this->recordGatewayStep('validate_params', $stepStart);

        $stepStart = \microtime(true);
        $this->requireDescriptorAuthorization(
            $provider,
            $operation,
            $params,
            $descriptor,
            $executionContext,
        );
        $this->recordGatewayStep('authorization_check', $stepStart);

        if ($this->isContextuallyBackendOperation($provider, $executionContext)) {
            $binding = $executionContext->backendBinding;
            if (!$binding instanceof FrontendWorkerBackendBinding) {
                $this->denyAuthorization();
            }
            $this->restoreBackendWorkerBinding($binding);
        }
        if (!$this->isBackendDescriptor($descriptor)
            && !$this->isContextuallyBackendOperation($provider, $executionContext)) {
            $stepStart = \microtime(true);
            $this->restoreFrontendWorkerScope($executionContext->scopeBinding);
            $this->recordGatewayStep('scope_binding_restore', $stepStart);
        }

        $stepStart = \microtime(true);
        $result = $this->queryService->execute($provider, $operation, $params, 'frontend_worker');
        $this->recordGatewayStep('query_service_execute', $stepStart);

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function executeGraph(
        array $payload,
        string $capability,
        FrontendWorkerExecutionContext $executionContext,
    ): array
    {
        if ($capability !== 'graph') {
            throw new FrontendQueryException('capability_denied', 'Worker capability does not allow graph.', 403);
        }

        $operations = $this->normalizeGraphOperations($payload['graph'] ?? $payload['operations'] ?? []);
        if ($operations === []) {
            throw new FrontendQueryException('validation_error', 'Graph operations cannot be empty.', 422);
        }
        if (\count($operations) > self::GRAPH_MAX_OPERATIONS) {
            throw new FrontendQueryException('validation_error', 'Graph operation count exceeds limit.', 422);
        }

        $totalCost = 0;
        $preparedOperations = [];
        $requiresFrontendScope = false;
        $aliases = [];
        foreach ($operations as $node) {
            $provider = (string)($node['provider'] ?? '');
            $operation = (string)($node['operation'] ?? '');
            $alias = (string)($node['as'] ?? ($provider . '.' . $operation));
            if (\preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,127}$/D', $alias) !== 1
                || isset($aliases[$alias])) {
                throw new FrontendQueryException('validation_error', 'Graph aliases must be unique and valid.', 422);
            }
            $aliases[$alias] = true;
            $params = $this->normalizeParams($node['params'] ?? []);
            $descriptor = $this->requireOperation($provider, $operation);

            if (($descriptor['mode'] ?? '') !== 'read' || ($descriptor['graph'] ?? false) !== true) {
                throw new FrontendQueryException('capability_denied', 'Only read graph operations are allowed.', 403);
            }

            $totalCost += max(1, (int)($descriptor['cost'] ?? 1));
            if ($totalCost > self::GRAPH_MAX_COST) {
                throw new FrontendQueryException('capability_denied', 'Graph cost exceeds limit.', 403);
            }

            $params = $this->validateParams($params, $descriptor);
            $this->requireDescriptorAuthorization(
                $provider,
                $operation,
                $params,
                $descriptor,
                $executionContext,
            );
            if (!$this->isBackendDescriptor($descriptor)) {
                $requiresFrontendScope = true;
            }
            $preparedOperations[] = [
                'provider' => $provider,
                'operation' => $operation,
                'alias' => $alias,
                'params' => $params,
            ];
        }

        // Every graph node is fully preflighted before Scope restoration or
        // provider execution, so a later denial can never produce partial data.
        if ($requiresFrontendScope) {
            $this->restoreFrontendWorkerScope($executionContext->scopeBinding);
        }

        $result = [];
        foreach ($preparedOperations as $preparedOperation) {
            $result[$preparedOperation['alias']] = $this->queryService->execute(
                $preparedOperation['provider'],
                $preparedOperation['operation'],
                $preparedOperation['params'],
                'frontend_worker'
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function createStreamTicket(
        array $payload,
        string $capability,
        FrontendWorkerExecutionContext $executionContext,
    ): array
    {
        if ($capability !== 'stream-ticket') {
            throw new FrontendQueryException('capability_denied', 'Worker capability does not allow stream tickets.', 403);
        }

        $channel = (string)($payload['channel'] ?? '');
        if (!\preg_match('/^[a-z][a-z0-9_]*\.[A-Za-z][A-Za-z0-9_]*$/', $channel)) {
            throw new FrontendQueryException('validation_error', 'Invalid stream channel.', 422);
        }

        [$provider, $operation] = \explode('.', $channel, 2);
        $descriptor = $this->requireOperation($provider, $operation);
        if (($descriptor['mode'] ?? '') !== 'stream') {
            throw new FrontendQueryException('capability_denied', 'Operation is not a frontend stream.', 403);
        }
        $isBackendRuntimeTask = $provider === 'runtime_task'
            && $executionContext->area === FrontendWorkerExecutionContext::AREA_BACKEND;
        if ($isBackendRuntimeTask) {
            // runtime_task is dual-area. Backend observation is allowed when the
            // page Worker already carries a live backend binding; type ACL and
            // task owner/lease checks still run inside prepareEvents.
            if ($executionContext->backendBinding === null) {
                throw new FrontendQueryException(
                    'auth_error',
                    (string)__('后台实时流证明无效，请刷新页面后重试。'),
                    401,
                );
            }
        } elseif ($this->isBackendDescriptor($descriptor)) {
            // Backend-authoritative QueryProvider streams remain fail-closed
            // until a shared revocation epoch exists. runtime_task is the
            // explicit exception above.
            throw new FrontendQueryException(
                'backend_stream_disabled',
                (string)__('后台实时流暂未启用，请使用普通后台请求。'),
                403,
            );
        }

        $params = $this->validateParams($this->normalizeParams($payload['params'] ?? []), $descriptor);
        $this->requireDescriptorAuthorization(
            $provider,
            $operation,
            $params,
            $descriptor,
            $executionContext,
        );
        if ($isBackendRuntimeTask) {
            return $this->workerSessionService->createStreamTicket(
                $channel,
                $params,
                null,
                null,
                $executionContext,
            );
        }
        $ticketScopeBinding = null;
        if (!$this->isBackendDescriptor($descriptor)) {
            $ticketScopeBinding = $this->restoreFrontendWorkerScope($executionContext->scopeBinding);
        }
        $owner = $this->resolveStreamOwner($descriptor);

        return $this->workerSessionService->createStreamTicket(
            $channel,
            $params,
            $owner,
            $ticketScopeBinding,
            // A public/customer stream does not inherit backend page authority
            // merely because it was opened from a backend document.
            FrontendWorkerExecutionContext::frontend($ticketScopeBinding),
        );
    }

    /**
     * @param array{channel:string, params:array<string, mixed>, expires_at:int, owner?:array<string, mixed>|null, scope_binding?:FrontendWorkerScopeBinding} $ticket
     * @return iterable<int|string, mixed>
     */
    public function executeStream(array $ticket): iterable
    {
        $channel = (string)($ticket['channel'] ?? '');
        if (!\preg_match('/^[a-z][a-z0-9_]*\.[A-Za-z][A-Za-z0-9_]*$/', $channel)) {
            throw new FrontendQueryException('validation_error', 'Invalid stream channel.', 422);
        }

        [$provider, $operation] = \explode('.', $channel, 2);
        $descriptor = $this->requireOperation($provider, $operation);
        if (($descriptor['mode'] ?? '') !== 'stream') {
            throw new FrontendQueryException('capability_denied', 'Operation is not a frontend stream.', 403);
        }

        $params = $this->validateParams($this->normalizeParams($ticket['params'] ?? []), $descriptor);
        $scopeBinding = $ticket['scope_binding'] ?? null;
        if ($scopeBinding !== null && !$scopeBinding instanceof FrontendWorkerScopeBinding) {
            throw new FrontendQueryException('protocol_error', 'Invalid worker stream Scope binding.', 400);
        }
        $executionContext = $ticket['execution_context'] ?? null;
        if ($executionContext === null) {
            $executionContext = FrontendWorkerExecutionContext::frontend($scopeBinding);
        }
        if (!$executionContext instanceof FrontendWorkerExecutionContext) {
            throw new FrontendQueryException('protocol_error', 'Invalid worker stream execution context.', 400);
        }
        $executionContext = $this->normalizeExecutionContext($scopeBinding, $executionContext);
        // Backend runtime_task.events tickets keep their attested backend
        // binding. Type ACL + owner/lease checks authorize inside prepareEvents.
        $this->installExecutionContext($executionContext);
        $this->requireDescriptorAuthorization(
            $provider,
            $operation,
            $params,
            $descriptor,
            $executionContext,
        );

        if ($executionContext->area === FrontendWorkerExecutionContext::AREA_BACKEND) {
            if ($provider !== 'runtime_task' || $executionContext->backendBinding === null) {
                throw new FrontendQueryException(
                    'backend_stream_disabled',
                    (string)__('后台实时流暂未启用，请使用普通后台请求。'),
                    403,
                );
            }
            $this->restoreBackendWorkerBinding($executionContext->backendBinding);
        } else {
            $this->restoreFrontendWorkerScope($executionContext->scopeBinding);
        }

        $ticketOwner = \is_array($ticket['owner'] ?? null) ? $ticket['owner'] : null;
        $owner = $this->assertStreamOwner($ticketOwner, $this->resolveStreamOwner($descriptor));
        if (\is_array($owner) && $owner !== []) {
            RequestContext::set(self::STREAM_OWNER_CONTEXT_KEY, $owner);
        }

        // Provider invocation must also happen before the controller opens the
        // SSE transport.  In particular, runtime_task.events performs its
        // owner/type/ACL preflight while returning the long-lived Generator;
        // deferring this call into our wrapper would emit 200/SSE headers before
        // a denied subscription can still fail closed as a normal HTTP error.
        $this->assertStreamExecutionContextFresh($executionContext);
        $result = $this->queryService->execute($provider, $operation, $params, 'frontend_worker_stream');

        return $this->iteratePreparedStream($result, $executionContext);
    }

    /**
     * @param array<string, mixed> $params
     * @return iterable<int|string, mixed>
     */
    private function iteratePreparedStream(
        mixed $result,
        FrontendWorkerExecutionContext $executionContext,
    ): iterable
    {
        if ($result instanceof \Traversable) {
            foreach ($result as $key => $event) {
                $this->assertStreamExecutionContextFresh($executionContext);
                yield $key => $event;
            }
            return;
        }

        if (\is_array($result) && \array_is_list($result)) {
            foreach ($result as $key => $event) {
                $this->assertStreamExecutionContextFresh($executionContext);
                yield $key => $event;
            }
            return;
        }

        $this->assertStreamExecutionContextFresh($executionContext);
        yield [
            'event' => 'message',
            'data' => $result,
        ];
    }

    private function assertStreamExecutionContextFresh(FrontendWorkerExecutionContext $executionContext): void
    {
        $scopeBinding = $executionContext->scopeBinding;
        if ($scopeBinding !== null && $scopeBinding->tokenExpiresAt <= \time()) {
            throw new FrontendQueryException(
                'scope_reload_required',
                (string)__('商城 Scope 已过期，请刷新页面后重试'),
                401,
            );
        }
        if ($executionContext->backendBinding !== null
            && $executionContext->backendBinding->expiresAt <= \time()) {
            throw new FrontendQueryException(
                'backend_attestation_invalid',
                (string)__('后台页面凭证已失效，请刷新页面后重试。'),
                401,
            );
        }
    }

    /** @param array<string, mixed> $descriptor */
    private function isBackendDescriptor(array $descriptor): bool
    {
        return $this->resolveDescriptorAuthMode($descriptor) === 'backend';
    }

    private function restoreFrontendWorkerScope(
        ?FrontendWorkerScopeBinding $scopeBinding,
    ): ?FrontendWorkerScopeBinding
    {
        try {
            $resolver = $this->runtimeProviderResolver
                ?? ObjectManager::getInstance(RuntimeProviderResolver::class);
            $resolution = $resolver->resolveDetailed(FrontendWorkerScopeProviderInterface::class);
        } catch (\Throwable $exception) {
            throw $this->scopeProviderUnavailable($exception);
        }

        if ($resolution->status === RuntimeProviderResolution::NOT_CONFIGURED) {
            // Optional capability absent means Scope Token authority is off.
            return null;
        }
        if (!$resolution->isAvailable()
            || !$resolution->provider instanceof FrontendWorkerScopeProviderInterface) {
            throw $this->scopeProviderUnavailable();
        }

        $authorityHost = $this->requestAuthorityHost();
        try {
            $resolution->provider->restoreBinding(
                $scopeBinding,
                WelineEnv::getRequestScheme(),
                $authorityHost,
            );
        } catch (FrontendWorkerScopeException $exception) {
            throw new FrontendQueryException(
                $exception->reason,
                $exception->getMessage(),
                $exception->httpStatus,
                $exception,
            );
        } catch (\Throwable $exception) {
            throw $this->scopeProviderUnavailable($exception);
        }

        // A successfully revalidated proof remains attached to a stream ticket
        // even when shadow/allowlist policy does not install it authoritatively.
        return $scopeBinding;
    }

    private function scopeProviderUnavailable(?\Throwable $previous = null): FrontendQueryException
    {
        return new FrontendQueryException(
            'scope_service_unavailable',
            (string)__('商城范围服务暂不可用'),
            503,
            $previous,
        );
    }

    /**
     * @param array<string, mixed> $descriptor
     * @return array{area:string, principal:string}|null
     */
    private function resolveStreamOwner(array $descriptor): ?array
    {
        $authMode = $this->resolveDescriptorAuthMode($descriptor);
        if (!\in_array($authMode, ['customer', 'backend'], true)) {
            return null;
        }

        $principal = $this->currentPrincipal($authMode);
        if ($principal === null) {
            $this->denyAuthorization();
        }

        return [
            'area' => $authMode === 'backend' ? 'backend' : 'frontend',
            'principal' => $authMode . ':' . $principal,
        ];
    }

    /**
     * A stream ticket is a bearer secret, but an authenticated stream must also
     * remain bound to the same principal that created it. This closes the gap
     * where a different logged-in account could consume a leaked ticket.
     *
     * @param array<string, mixed>|null $ticketOwner
     * @param array<string, mixed>|null $currentOwner
     * @return array{area:string, principal:string}|null
     */
    private function assertStreamOwner(?array $ticketOwner, ?array $currentOwner): ?array
    {
        if ($currentOwner === null) {
            if ($ticketOwner !== null) {
                $this->denyAuthorization();
            }
            return null;
        }

        if ($ticketOwner === null
            || \array_keys($ticketOwner) !== ['area', 'principal']
            || !\is_string($ticketOwner['area'])
            || !\is_string($ticketOwner['principal'])
            || !\hash_equals($currentOwner['area'], $ticketOwner['area'])
            || !\hash_equals($currentOwner['principal'], $ticketOwner['principal'])) {
            $this->denyAuthorization();
        }

        return $currentOwner;
    }

    /** @param array<string,mixed> $params @param array<string,mixed> $descriptor */
    private function requireDescriptorAuthorization(
        string $provider,
        string $operation,
        array $params,
        array $descriptor,
        FrontendWorkerExecutionContext $executionContext,
    ): void
    {
        $authMode = $this->resolveDescriptorAuthMode($descriptor);
        if ($authMode === null || $authMode === 'any') {
            return;
        }

        if ($authMode === 'backend') {
            $binding = $executionContext->backendBinding;
            if ($executionContext->area !== FrontendWorkerExecutionContext::AREA_BACKEND
                || !$binding instanceof FrontendWorkerBackendBinding) {
                $this->denyAuthorization();
            }
            $this->restoreBackendWorkerBinding($binding);
            $principal = $this->currentPrincipal('backend');
            if ($principal === null
                || !\hash_equals((string)$binding->backendUserId, $principal)) {
                $this->denyAuthorization();
            }
            $this->requireBackendDescriptorAcl(
                $provider,
                $operation,
                $params,
                $descriptor,
                $binding,
            );
            return;
        }

        if ($executionContext->area !== FrontendWorkerExecutionContext::AREA_FRONTEND) {
            $this->denyAuthorization();
        }
        $sessionFactory = SessionFactory::getInstance();
        $authorized = match ($authMode) {
            'guest' => !$sessionFactory->createFrontendSession()->isLoggedIn(),
            'customer' => $this->currentPrincipal($authMode) !== null,
            default => false,
        };

        if (!$authorized) {
            $this->denyAuthorization();
        }
    }

    /** @param array<string,mixed> $params @param array<string,mixed> $descriptor */
    private function requireBackendDescriptorAcl(
        string $provider,
        string $operation,
        array $params,
        array $descriptor,
        FrontendWorkerBackendBinding $binding,
    ): void {
        try {
            $policy = FrontendWorkerBackendAcl::normalize(
                $descriptor['backend_acl'] ?? null,
                $descriptor['params'] ?? [],
            );
            $sourceId = FrontendWorkerBackendAcl::resolveSourceId($policy, $params);
        } catch (\InvalidArgumentException) {
            $this->denyAuthorization();
        }
        if ($sourceId === null) {
            return;
        }

        try {
            $resolver = $this->runtimeProviderResolver
                ?? ObjectManager::getInstance(RuntimeProviderResolver::class);
            $resolution = $resolver->resolveDetailed(
                FrontendWorkerBackendAuthorizationProviderInterface::class,
            );
        } catch (\Throwable $exception) {
            throw $this->backendAuthorizationUnavailable($exception);
        }
        if (!$resolution->isAvailable()
            || !$resolution->provider instanceof FrontendWorkerBackendAuthorizationProviderInterface) {
            throw $this->backendAuthorizationUnavailable();
        }

        try {
            $resolution->provider->assertSourceAllowed(
                $binding,
                $sourceId,
                $provider,
                $operation,
            );
        } catch (FrontendWorkerBackendAuthorizationException $exception) {
            throw new FrontendQueryException(
                $exception->reason,
                $exception->getMessage(),
                $exception->httpStatus,
                $exception,
            );
        } catch (\Throwable $exception) {
            throw $this->backendAuthorizationUnavailable($exception);
        }
    }

    private function backendAuthorizationUnavailable(?\Throwable $previous = null): FrontendQueryException
    {
        return new FrontendQueryException(
            'backend_authorization_unavailable',
            (string)__('后台权限服务暂不可用。'),
            503,
            $previous,
        );
    }

    private function normalizeExecutionContext(
        ?FrontendWorkerScopeBinding $scopeBinding,
        ?FrontendWorkerExecutionContext $executionContext,
    ): FrontendWorkerExecutionContext {
        if ($executionContext === null) {
            return FrontendWorkerExecutionContext::frontend($scopeBinding);
        }
        if ($scopeBinding === null) {
            return $executionContext;
        }
        if ($executionContext->scopeBinding === null
            || !\hash_equals($scopeBinding->digest(), $executionContext->scopeBinding->digest())) {
            throw new FrontendQueryException('auth_error', 'Worker execution context is inconsistent.', 401);
        }
        return $executionContext;
    }

    private function installExecutionContext(FrontendWorkerExecutionContext $executionContext): void
    {
        // Providers may derive principals only from this server-constructed,
        // request-local value. Browser payload fields and ambient cookies are
        // not authority to switch between frontend and backend areas.
        RequestContext::set(FrontendWorkerExecutionContext::REQUEST_CONTEXT_KEY, $executionContext);
    }

    private function isContextuallyBackendOperation(
        string $provider,
        FrontendWorkerExecutionContext $executionContext,
    ): bool
    {
        // runtime_task deliberately serves both storefront and backend owners,
        // so it cannot use one fixed descriptor auth mode. The attested area is
        // authoritative; backend events keep the same binding for type ACL.
        return $provider === 'runtime_task'
            && $executionContext->area === FrontendWorkerExecutionContext::AREA_BACKEND;
    }

    private function restoreBackendWorkerBinding(FrontendWorkerBackendBinding $binding): void
    {
        $requestKey = 'frontend_worker.backend_attestation.' . $binding->digest();
        if (RequestContext::get($requestKey) === true) {
            return;
        }

        try {
            $resolver = $this->runtimeProviderResolver
                ?? ObjectManager::getInstance(RuntimeProviderResolver::class);
            $resolution = $resolver->resolveDetailed(
                FrontendWorkerBackendAttestationProviderInterface::class,
            );
        } catch (\Throwable $exception) {
            throw $this->backendAttestationUnavailable($exception);
        }
        if (!$resolution->isAvailable()
            || !$resolution->provider instanceof FrontendWorkerBackendAttestationProviderInterface) {
            throw $this->backendAttestationUnavailable();
        }

        $authorityHost = $this->requestAuthorityHost();
        try {
            $resolution->provider->restoreBinding($binding, $authorityHost);
        } catch (FrontendWorkerBackendAttestationException $exception) {
            throw new FrontendQueryException(
                $exception->reason,
                $exception->getMessage(),
                $exception->httpStatus,
                $exception,
            );
        } catch (\Throwable $exception) {
            throw $this->backendAttestationUnavailable($exception);
        }
        RequestContext::set($requestKey, true);
    }

    private function requestAuthorityHost(): string
    {
        $host = RequestAuthority::current();
        if ($host === '') {
            throw new FrontendQueryException(
                'protocol_error',
                'Worker request authority is invalid.',
                400,
            );
        }
        return $host;
    }

    private function backendAttestationUnavailable(?\Throwable $previous = null): FrontendQueryException
    {
        return new FrontendQueryException(
            'backend_attestation_unavailable',
            (string)__('后台页面凭证服务暂不可用。'),
            503,
            $previous,
        );
    }

    /**
     * @param array<string, mixed> $descriptor
     */
    private function resolveDescriptorAuthMode(array $descriptor): ?string
    {
        if (\array_key_exists('backend', $descriptor) && !\is_bool($descriptor['backend'])) {
            $this->denyAuthorization();
        }
        if (\array_key_exists('auth', $descriptor)) {
            $authMode = $descriptor['auth'];
            if (!\is_string($authMode) || !\in_array($authMode, self::AUTH_MODES, true)) {
                $this->denyAuthorization();
            }
            if (($descriptor['backend'] ?? false) === true && $authMode !== 'backend') {
                $this->denyAuthorization();
            }

            return $authMode;
        }

        if (($descriptor['backend'] ?? false) === true) {
            return 'backend';
        }

        return null;
    }

    private function currentPrincipal(string $authMode): ?string
    {
        $sessionFactory = SessionFactory::getInstance();
        $session = $authMode === 'backend'
            ? $sessionFactory->createBackendSession()
            : $sessionFactory->createFrontendSession();
        if (!$session->isLoggedIn()) {
            return null;
        }

        $identifier = $session->getUserId();
        if (!\is_int($identifier) && !\is_string($identifier)) {
            return null;
        }
        $identifier = \trim((string)$identifier);
        if ($identifier === '' || $identifier === '0' || \strlen($identifier) > 190) {
            return null;
        }

        return $identifier;
    }

    private function denyAuthorization(): void
    {
        throw new FrontendQueryException(
            'auth_error',
            (string)__('Operation authorization requirements are not satisfied.'),
            403,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOperation(string $provider, string $operation): array
    {
        if ($provider === '' || $operation === '') {
            throw new FrontendQueryException('validation_error', 'Provider and operation are required.', 422);
        }
        if ($provider === 'framework' || $provider === 'crud') {
            throw new FrontendQueryException('capability_denied', 'Provider is not exposed to frontend worker API.', 403);
        }

        $operationDescriptor = $this->registry->getOperationDescriptor($provider, $operation);
        if ($operationDescriptor !== null) {
            if (($operationDescriptor['frontend'] ?? false) !== true) {
                throw new FrontendQueryException('capability_denied', 'Operation is not exposed to frontend worker API.', 403);
            }
            if (!isset($operationDescriptor['mode']) || (string)$operationDescriptor['mode'] === '') {
                throw new FrontendQueryException('capability_denied', 'Frontend operation is missing explicit mode.', 403);
            }

            return $operationDescriptor;
        }

        throw new FrontendQueryException('capability_denied', 'Frontend worker operation is not allowed.', 403);
    }

    /**
     * @param mixed $params
     * @return array<string, mixed>
     */
    private function normalizeParams(mixed $params): array
    {
        if ($params === null) {
            return [];
        }
        if (!\is_array($params)) {
            throw new FrontendQueryException('validation_error', 'Operation params must be a map.', 422);
        }
        if (\array_is_list($params) && $params !== []) {
            throw new FrontendQueryException('validation_error', 'Operation params must be a map.', 422);
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $operationDescriptor
     * @return array<string, mixed>
     */
    private function validateParams(array $params, array $operationDescriptor): array
    {
        $rules = $this->normalizeParamRules($operationDescriptor['params'] ?? []);
        foreach ($params as $name => $_value) {
            if (!\array_key_exists((string)$name, $rules)) {
                throw new FrontendQueryException('validation_error', 'Unknown frontend worker param: ' . (string)$name, 422);
            }
        }

        foreach ($rules as $name => $rule) {
            $required = (bool)($rule['required'] ?? false);
            if (!\array_key_exists($name, $params)) {
                if ($required) {
                    throw new FrontendQueryException('validation_error', 'Missing required param: ' . $name, 422);
                }
                continue;
            }

            $params[$name] = $this->normalizeParamValue($name, $params[$name], $rule);
            $this->validateParamValue($name, $params[$name], $rule);
        }

        return $params;
    }

    /**
     * @param mixed $paramsDescriptor
     * @return array<string, array<string, mixed>>
     */
    private function normalizeParamRules(mixed $paramsDescriptor): array
    {
        if (!\is_array($paramsDescriptor)) {
            return [];
        }

        $rules = [];
        foreach ($paramsDescriptor as $key => $rule) {
            if (!\is_array($rule)) {
                continue;
            }

            $name = \is_string($key) ? $key : (string)($rule['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $rule['name'] = $name;
            $rules[$name] = $rule;
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function normalizeParamValue(string $name, mixed $value, array $rule): mixed
    {
        $type = \strtolower((string)($rule['type'] ?? 'mixed'));

        // WQB1 保留 JS 数字/布尔类型；HTTP 表单则全是字符串。声明为 string 时接受标量并规范化。
        if ($type === 'string' && (\is_int($value) || \is_float($value) || \is_bool($value))) {
            if (\is_bool($value)) {
                return $value ? '1' : '0';
            }

            return (string)$value;
        }

        if (!\is_string($value)) {
            return $value;
        }

        $trimmed = \trim($value);

        if (($type === 'int' || $type === 'integer') && \preg_match('/^-?\d+$/', $trimmed) === 1) {
            return (int)$trimmed;
        }

        if (($type === 'float' || $type === 'double' || $type === 'number') && \is_numeric($trimmed)) {
            return (float)$trimmed;
        }

        if ($type === 'bool' || $type === 'boolean') {
            $normalized = \strtolower($trimmed);
            if (\in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (\in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function validateParamValue(string $name, mixed $value, array $rule): void
    {
        if ($value === null && ($rule['nullable'] ?? false) === true) {
            return;
        }

        $type = \strtolower((string)($rule['type'] ?? 'mixed'));
        $valid = match ($type) {
            'int', 'integer' => \is_int($value),
            'float', 'double', 'number' => \is_int($value) || \is_float($value),
            'string' => \is_string($value),
            'bool', 'boolean' => \is_bool($value),
            'list' => \is_array($value) && \array_is_list($value),
            'array' => \is_array($value),
            'map', 'object' => \is_array($value) && !\array_is_list($value),
            'mixed' => true,
            default => true,
        };
        if (!$valid) {
            throw new FrontendQueryException('validation_error', 'Invalid param type: ' . $name, 422);
        }

        if (\is_int($value) || \is_float($value)) {
            if (isset($rule['max']) && $value > (float)$rule['max']) {
                throw new FrontendQueryException('validation_error', 'Param exceeds max: ' . $name, 422);
            }
            if (isset($rule['min']) && $value < (float)$rule['min']) {
                throw new FrontendQueryException('validation_error', 'Param below min: ' . $name, 422);
            }
        }

        if (\is_string($value) && isset($rule['max_length']) && \strlen($value) > (int)$rule['max_length']) {
            throw new FrontendQueryException('validation_error', 'Param string exceeds max length: ' . $name, 422);
        }

        if (\is_array($value) && isset($rule['max_items']) && \count($value) > (int)$rule['max_items']) {
            throw new FrontendQueryException('validation_error', 'Param list exceeds max items: ' . $name, 422);
        }
    }

    /**
     * @param mixed $graph
     * @return array<int, array<string, mixed>>
     */
    private function normalizeGraphOperations(mixed $graph): array
    {
        if (!\is_array($graph)) {
            throw new FrontendQueryException('validation_error', 'Graph operations must be a list or map.', 422);
        }
        if (\array_is_list($graph)) {
            $operations = [];
            foreach ($graph as $node) {
                if (!\is_array($node) || \array_is_list($node)) {
                    throw new FrontendQueryException('validation_error', 'Every graph node must be a map.', 422);
                }
                $keys = \array_keys($node);
                \sort($keys, SORT_STRING);
                if (\array_diff($keys, ['as', 'operation', 'params', 'provider']) !== []) {
                    throw new FrontendQueryException('validation_error', 'Graph node contains unknown fields.', 422);
                }
                $operations[] = $node;
            }
            return $operations;
        }

        $operations = [];
        foreach ($graph as $provider => $providerOperations) {
            if (!\is_string($provider)
                || $provider === ''
                || !\is_array($providerOperations)
                || \array_is_list($providerOperations)) {
                throw new FrontendQueryException('validation_error', 'Graph provider operations must be a map.', 422);
            }
            foreach ($providerOperations as $operation => $params) {
                if (!\is_string($operation) || $operation === '') {
                    throw new FrontendQueryException('validation_error', 'Graph operation name is invalid.', 422);
                }
                $operations[] = [
                    'provider' => $provider,
                    'operation' => $operation,
                    'params' => $params,
                    'as' => $provider . '.' . $operation,
                ];
            }
        }

        return $operations;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function recordGatewayStep(string $name, float $startedAt, array $meta = []): void
    {
        $profile = RequestContext::get('query_bin.gateway_profile');
        if (!\is_array($profile)) {
            $profile = [];
        }

        $step = [
            'name' => $name,
            'duration_ms' => \round((\microtime(true) - $startedAt) * 1000, 2),
        ];
        if ($meta !== []) {
            $step['meta'] = $meta;
        }
        $profile[] = $step;
        RequestContext::set('query_bin.gateway_profile', $profile);
    }
}
