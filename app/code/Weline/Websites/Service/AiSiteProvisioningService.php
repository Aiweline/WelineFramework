<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Api\AiSiteProvisioningInterface;
use Weline\Websites\Exception\AiSiteProvisioningException;
use Weline\Websites\Model\AiSiteProvisioningRequest;
use Weline\Websites\Model\Website;

class AiSiteProvisioningService implements AiSiteProvisioningInterface
{
    private const DEFAULT_SOURCE_MODULE = 'GuoLaiRen_PageBuilder';

    private ?AiSiteStartPageService $startPageService;

    private ?Website $websiteModel = null;

    public function __construct(
        private readonly AiSiteProvisioningRequestRepository $requestRepository,
        private readonly AiSiteProvisioningQueueGateway $queueGateway,
        private readonly AiSiteLocalDomainReadinessService $localDomainReadinessService,
        ?AiSiteStartPageService $startPageService = null,
    ) {
        $this->startPageService = $startPageService;
    }

    public function requestBinding(array $command): array
    {
        $normalized = $this->normalizeCommand($command);
        // Start is the caller's explicit requirement confirmation. Persist the
        // request first, then launch one bounded/non-blocking local authorization
        // handshake from the HTTP/WLS user session. The Queue remains the sole
        // owner of readiness polling and binding, so Plan still proceeds in
        // parallel and a cancelled desktop authorization cannot orphan work.
        $existing = $this->requestRepository->findByCommand(
            $normalized['source_module'],
            $normalized['client_request_id']
        );
        if ($existing instanceof AiSiteProvisioningRequest) {
            $this->repairLegacyPageBuilderBinding($existing, $normalized);
            $this->adoptShellRequestedWebsiteId($existing, $normalized);
            $this->assertSameCommand($existing, $normalized);
            $this->synchronizePageBuilderDefaultLanguage($existing, $normalized['default_locale']);

            if ($normalized['rearm_failed']) {
                $this->primeLocalDomainAuthorization($normalized);
                return $this->rearmRequest($existing);
            }

            return $this->enqueueRequest($existing);
        }

        $request = null;
        $created = false;
        try {
            $request = $this->requestRepository->create([
                AiSiteProvisioningRequest::schema_fields_REQUEST_ID => \bin2hex(\random_bytes(16)),
                AiSiteProvisioningRequest::schema_fields_SOURCE_MODULE => $normalized['source_module'],
                AiSiteProvisioningRequest::schema_fields_ADMIN_USER_ID => $normalized['admin_user_id'],
                AiSiteProvisioningRequest::schema_fields_SOURCE_PUBLIC_ID => $normalized['source_public_id'],
                AiSiteProvisioningRequest::schema_fields_CLIENT_REQUEST_ID => $normalized['client_request_id'],
                AiSiteProvisioningRequest::schema_fields_DOMAIN_MODE => $normalized['domain_mode'],
                AiSiteProvisioningRequest::schema_fields_TARGET_DOMAIN => $normalized['target_domain'],
                AiSiteProvisioningRequest::schema_fields_SUB_PATH => $normalized['sub_path'],
                AiSiteProvisioningRequest::schema_fields_REGISTRAR_ACCOUNT_ID => $normalized['registrar_account_id'],
                AiSiteProvisioningRequest::schema_fields_YEARS => $normalized['years'],
                AiSiteProvisioningRequest::schema_fields_PURCHASE_CONFIRMED => $normalized['purchase_confirmed'],
                AiSiteProvisioningRequest::schema_fields_PURCHASE_ATTEMPTED => 0,
                AiSiteProvisioningRequest::schema_fields_PURCHASE_ORDER_ID => 0,
                AiSiteProvisioningRequest::schema_fields_REQUESTED_WEBSITE_ID => $normalized['requested_website_id'],
                AiSiteProvisioningRequest::schema_fields_WEBSITE_BOUND => 0,
                AiSiteProvisioningRequest::schema_fields_WEBSITE_ID => 0,
                AiSiteProvisioningRequest::schema_fields_STATUS => AiSiteProvisioningRequest::STATUS_PENDING,
                AiSiteProvisioningRequest::schema_fields_QUEUE_ID => 0,
                AiSiteProvisioningRequest::schema_fields_EXECUTION_TOKEN => \bin2hex(\random_bytes(16)),
                AiSiteProvisioningRequest::schema_fields_ERROR_CODE => '',
                AiSiteProvisioningRequest::schema_fields_MESSAGE => (string)__('等待系统调度域名准备任务。'),
            ]);
            $created = true;
        } catch (\Throwable $throwable) {
            $request = $this->requestRepository->findByCommand(
                $normalized['source_module'],
                $normalized['client_request_id']
            );
            if (!$request instanceof AiSiteProvisioningRequest) {
                throw $throwable;
            }
            $this->assertSameCommand($request, $normalized);
        }

        if ($created) {
            $this->primeLocalDomainAuthorization($normalized);
        }

        return $this->enqueueRequest($request);
    }

    /**
     * Start only launches the exact-domain macOS/UAC handshake. It never waits
     * for approval, changes request truth, creates a Queue, or treats preparation
     * failure as build failure; the Scheduler re-inspects canonical OS state.
     *
     * @param array<string,mixed> $normalized
     */
    private function primeLocalDomainAuthorization(array $normalized): void
    {
        if (($normalized['domain_mode'] ?? '') !== AiSiteProvisioningRequest::DOMAIN_MODE_TEST) {
            return;
        }
        $domain = (string)($normalized['target_domain'] ?? '');
        if ($domain === '') {
            return;
        }
        try {
            $prepared = $this->localDomainReadinessService->prepare($domain, true);
            if (($prepared['can_start'] ?? false) === true) {
                return;
            }
            // Keep Start/Plan admission alive, but leave a durable diagnostic so
            // the operator can see why the local domain is still unreachable.
            \w_log_warning(
                '[Websites AI] local domain hosts prime incomplete: domain={domain} code={code} message={message}',
                [
                    'domain' => $domain,
                    'code' => (string)($prepared['code'] ?? 'LOCAL_DOMAIN_NOT_READY'),
                    'message' => (string)($prepared['message'] ?? ''),
                ],
                'websites_ai_site',
            );
        } catch (\Throwable $throwable) {
            \w_log_warning(
                '[Websites AI] local domain hosts prime failed: domain={domain} exception={exception}',
                [
                    'domain' => $domain,
                    'exception' => $throwable->getMessage(),
                ],
                'websites_ai_site',
            );
        }
    }

    /** @param array<string, mixed> $command */
    public function configureStartPage(array $command): array
    {
        $normalized = $this->normalizeCommand($command);
        $pageId = (int)($command['page_id'] ?? 0);
        if ($pageId <= 0) {
            throw new AiSiteProvisioningException(
                'START_PAGE_REQUIRED',
                (string)__('发布后的首页页面 ID 不能为空。')
            );
        }

        $request = $this->requestRepository->findByCommand(
            $normalized['source_module'],
            $normalized['client_request_id']
        );
        if (!$request instanceof AiSiteProvisioningRequest) {
            throw new AiSiteProvisioningException(
                'DOMAIN_BINDING_NOT_READY',
                (string)__('尚未找到当前 AI 建站会话的域名绑定。')
            );
        }
        // Publish often runs after intake dehydration. Align shell website id
        // from the durable request before asserting command identity.
        $this->alignRequestedWebsiteId($request, $normalized);
        $this->assertSameCommand($request, $normalized);

        $status = $this->formatRequest($request);
        $websiteId = (int)$request->getData(AiSiteProvisioningRequest::schema_fields_WEBSITE_ID);
        $bindingVerified = (string)$request->getData(AiSiteProvisioningRequest::schema_fields_STATUS)
                === AiSiteProvisioningRequest::STATUS_DONE
            && (int)$request->getData(AiSiteProvisioningRequest::schema_fields_WEBSITE_BOUND) === 1
            && $websiteId >= Website::ID_DEFAULT
            && ObjectManager::getInstance(AiSiteDomainPreparationService::class)
                ->isBound($normalized['target_domain'], $websiteId, $normalized['sub_path']);
        if ((int)$request->getData(AiSiteProvisioningRequest::schema_fields_ADMIN_USER_ID) !== $normalized['admin_user_id']
            || !$bindingVerified
        ) {
            throw new AiSiteProvisioningException(
                'DOMAIN_BINDING_NOT_READY',
                (string)__('域名绑定尚未完成，不能登记站点首页。')
            );
        }

        $startPage = $this->startPageService()->configure(
            (int)$request->getData(AiSiteProvisioningRequest::schema_fields_WEBSITE_ID),
            $normalized['target_domain'],
            $pageId,
            (string)($normalized['sub_path'] ?? '')
        );

        return \array_replace($status, [
            'success' => true,
            'code' => 'OK',
            'start_page' => $startPage,
        ]);
    }

    /** @param array<string, mixed> $lookup */
    public function getStatus(array $lookup): ?array
    {
        $adminUserId = $this->positiveInteger($lookup['admin_user_id'] ?? null);
        if ($adminUserId === null) {
            return null;
        }
        $requestId = \trim((string)($lookup['request_id'] ?? ''));
        if ($requestId !== '') {
            $request = $this->requestRepository->findByRequestId($requestId);
        } else {
            $sourceModule = \trim((string)($lookup['source_module'] ?? self::DEFAULT_SOURCE_MODULE));
            $clientRequestId = \trim((string)($lookup['client_request_id'] ?? ''));
            $request = $this->requestRepository->findByCommand($sourceModule, $clientRequestId);
        }

        if (!$request instanceof AiSiteProvisioningRequest
            || (int)$request->getData(AiSiteProvisioningRequest::schema_fields_ADMIN_USER_ID) !== $adminUserId
        ) {
            return null;
        }
        $sourcePublicId = \trim((string)($lookup['source_public_id'] ?? ''));
        if ($sourcePublicId !== ''
            && (string)$request->getData(AiSiteProvisioningRequest::schema_fields_SOURCE_PUBLIC_ID) !== $sourcePublicId
        ) {
            return null;
        }

        return $this->formatRequest($request);
    }

    /** @param array<string, mixed> $command */
    public function forceBindIgnoringLocalHosts(array $command): array
    {
        $normalized = $this->normalizeCommand($command);
        if ($normalized['domain_mode'] !== AiSiteProvisioningRequest::DOMAIN_MODE_TEST) {
            throw new AiSiteProvisioningException(
                'DOMAIN_MODE_UNSUPPORTED',
                (string)__('跳过本机 hosts 的强制绑定仅支持测试域名模式。')
            );
        }

        $request = $this->requestRepository->findByCommand(
            $normalized['source_module'],
            $normalized['client_request_id']
        );
        if (!$request instanceof AiSiteProvisioningRequest) {
            throw new AiSiteProvisioningException(
                'DOMAIN_BINDING_NOT_READY',
                (string)__('尚未找到当前 AI 建站会话的域名绑定。')
            );
        }
        $this->assertSameCommand($request, $normalized);
        if ((int)$request->getData(AiSiteProvisioningRequest::schema_fields_ADMIN_USER_ID)
            !== $normalized['admin_user_id']
        ) {
            throw new AiSiteProvisioningException(
                'DOMAIN_BINDING_NOT_READY',
                (string)__('域名绑定请求与当前管理员不匹配。')
            );
        }

        $existingWebsiteId = (int)$request->getData(AiSiteProvisioningRequest::schema_fields_WEBSITE_ID);
        $domain = $normalized['target_domain'];
        $subPath = $normalized['sub_path'];
        $domainPreparation = ObjectManager::getInstance(AiSiteDomainPreparationService::class);
        if ((string)$request->getData(AiSiteProvisioningRequest::schema_fields_STATUS)
                === AiSiteProvisioningRequest::STATUS_DONE
            && (int)$request->getData(AiSiteProvisioningRequest::schema_fields_WEBSITE_BOUND) === 1
            && $existingWebsiteId >= Website::ID_DEFAULT
            && $domainPreparation->isBound($domain, $existingWebsiteId, $subPath)
        ) {
            return $this->formatRequest($request);
        }

        $prepared = $domainPreparation->prepareIgnoringLocalHosts($request);
        $websiteId = (int)($prepared['website_id'] ?? 0);
        if (!$domainPreparation->isBound($domain, $websiteId, $subPath)) {
            throw new AiSiteProvisioningException(
                'WEBSITE_DOMAIN_BINDING_FAILED',
                (string)__('跳过 hosts 后仍未能验证站点绑定。')
            );
        }

        $hosts = \is_array($prepared['hosts'] ?? null) ? $prepared['hosts'] : [];
        $hostsReady = ($prepared['local_ready'] ?? false) === true;
        $message = $hostsReady
            ? (string)__('已完成站点绑定，并写入本机 hosts。')
            : (string)__(
                '已跳过强制 hosts 门禁并完成站点绑定；本机解析仍可能失败，请批准 hosts 写入后重试域名准备。%{1}',
                [\trim((string)($hosts['message'] ?? '')) !== '' ? ' ' . \trim((string)$hosts['message']) : '']
            );

        $request->setData(AiSiteProvisioningRequest::schema_fields_WEBSITE_BOUND, 1)
            ->setData(AiSiteProvisioningRequest::schema_fields_WEBSITE_ID, $websiteId)
            ->setData(AiSiteProvisioningRequest::schema_fields_STATUS, AiSiteProvisioningRequest::STATUS_DONE)
            ->setData(AiSiteProvisioningRequest::schema_fields_ERROR_CODE, '')
            ->setData(AiSiteProvisioningRequest::schema_fields_MESSAGE, $message);
        $this->requestRepository->save($request);
        $this->synchronizePageBuilderDefaultLanguage($request, $normalized['default_locale']);

        return $this->formatRequest($request);
    }

    /**
     * Apply PageBuilder's selected source language only after the owned website
     * binding is complete. The locale remains an optional request attribute, so
     * it does not alter the domain binding's idempotency identity.
     */
    private function synchronizePageBuilderDefaultLanguage(
        AiSiteProvisioningRequest $request,
        string $defaultLocale
    ): void {
        if ($defaultLocale === ''
            || (string)$request->getData(AiSiteProvisioningRequest::schema_fields_SOURCE_MODULE) !== 'GuoLaiRen_PageBuilder'
            || (string)$request->getData(AiSiteProvisioningRequest::schema_fields_STATUS) !== AiSiteProvisioningRequest::STATUS_DONE
            || (int)$request->getData(AiSiteProvisioningRequest::schema_fields_WEBSITE_BOUND) !== 1
        ) {
            return;
        }

        $websiteId = (int)$request->getData(AiSiteProvisioningRequest::schema_fields_WEBSITE_ID);
        if ($websiteId <= 0) {
            return;
        }

        $website = clone ($this->websiteModel ??= ObjectManager::getInstance(Website::class));
        $website->clearData()->clearQuery()->load($websiteId);
        if ($website->getWebsiteId() <= 0 || $website->getDefaultLanguage() === $defaultLocale) {
            return;
        }

        $website->setDefaultLanguage($defaultLocale)->save();
    }

    private function startPageService(): AiSiteStartPageService
    {
        return $this->startPageService ??= ObjectManager::getInstance(AiSiteStartPageService::class);
    }

    /** @return array<string, mixed> */
    /**
     * Requeue historical local PageBuilder requests that incorrectly completed
     * against the system default website instead of a session-owned website.
     *
     * @param array<string, mixed> $command
     */
    private function repairLegacyPageBuilderBinding(
        AiSiteProvisioningRequest $request,
        array $command
    ): void {
        if (($command['source_module'] ?? '') !== 'GuoLaiRen_PageBuilder'
            || (string)$request->getData(AiSiteProvisioningRequest::schema_fields_DOMAIN_MODE)
                !== AiSiteProvisioningRequest::DOMAIN_MODE_TEST
            || (string)$request->getData(AiSiteProvisioningRequest::schema_fields_STATUS)
                !== AiSiteProvisioningRequest::STATUS_DONE
            || (int)$request->getData(AiSiteProvisioningRequest::schema_fields_WEBSITE_BOUND) !== 1
            || (int)$request->getData(AiSiteProvisioningRequest::schema_fields_WEBSITE_ID) > 0
        ) {
            return;
        }

        $request->setData(AiSiteProvisioningRequest::schema_fields_WEBSITE_BOUND, 0)
            ->setData(AiSiteProvisioningRequest::schema_fields_WEBSITE_ID, 0)
            ->setData(AiSiteProvisioningRequest::schema_fields_QUEUE_ID, 0)
            ->setData(AiSiteProvisioningRequest::schema_fields_STATUS, AiSiteProvisioningRequest::STATUS_PENDING)
            ->setData(AiSiteProvisioningRequest::schema_fields_EXECUTION_TOKEN, \bin2hex(\random_bytes(16)))
            ->setData(AiSiteProvisioningRequest::schema_fields_ERROR_CODE, '')
            ->setData(
                AiSiteProvisioningRequest::schema_fields_MESSAGE,
                (string)__('检测到历史默认站点绑定，正在为当前会话重新创建独立站点。')
            );
        $this->requestRepository->save($request);
    }

    private function enqueueRequest(AiSiteProvisioningRequest $request): array
    {
        $status = (string)$request->getData(AiSiteProvisioningRequest::schema_fields_STATUS);
        if ((int)$request->getData(AiSiteProvisioningRequest::schema_fields_QUEUE_ID) > 0
            || \in_array($status, [AiSiteProvisioningRequest::STATUS_DONE, AiSiteProvisioningRequest::STATUS_ERROR], true)
        ) {
            return $this->formatRequest($request);
        }

        try {
            $queue = $this->queueGateway->enqueue(
                $request->getRequestId(),
                (string)$request->getData(AiSiteProvisioningRequest::schema_fields_EXECUTION_TOKEN)
            );
            $queueId = (int)($queue['queue_id'] ?? 0);
            if ($queueId <= 0) {
                throw new AiSiteProvisioningException('QUEUE_CREATE_FAILED', (string)__('创建域名准备队列失败。'));
            }
            $request->setData(AiSiteProvisioningRequest::schema_fields_QUEUE_ID, $queueId)
                ->setData(AiSiteProvisioningRequest::schema_fields_STATUS, AiSiteProvisioningRequest::STATUS_PENDING)
                ->setData(AiSiteProvisioningRequest::schema_fields_MESSAGE, (string)__('域名准备任务已排队，等待系统调度。'));
            $this->requestRepository->save($request);
        } catch (\Throwable $throwable) {
            $request->setData(AiSiteProvisioningRequest::schema_fields_STATUS, AiSiteProvisioningRequest::STATUS_ERROR)
                ->setData(AiSiteProvisioningRequest::schema_fields_ERROR_CODE, 'QUEUE_CREATE_FAILED')
                ->setData(AiSiteProvisioningRequest::schema_fields_MESSAGE, $throwable->getMessage());
            $this->requestRepository->save($request);
        }

        return $this->formatRequest($request);
    }

    /** @return array<string, mixed> */
    private function formatRequest(AiSiteProvisioningRequest $request): array
    {
        $status = (string)$request->getData(AiSiteProvisioningRequest::schema_fields_STATUS);
        $queueId = (int)$request->getData(AiSiteProvisioningRequest::schema_fields_QUEUE_ID);
        $queue = $this->queueGateway->get($queueId);
        $errorCode = (string)$request->getData(AiSiteProvisioningRequest::schema_fields_ERROR_CODE);
        $queueStatus = \strtolower(\trim((string)($queue['status'] ?? '')));
        $effectiveStatus = $status;
        $effectiveErrorCode = $errorCode;
        $message = (string)$request->getData(AiSiteProvisioningRequest::schema_fields_MESSAGE);
        if ($status !== AiSiteProvisioningRequest::STATUS_DONE
            && \in_array($queueStatus, ['error', 'failed', 'stop'], true)
        ) {
            // Queue is the authoritative lifecycle fact. Project a terminal
            // failure even when an older worker died after setting the request
            // to running but before persisting its error. This read remains
            // side-effect free and gives V2 an explicit operator retry target.
            $effectiveStatus = AiSiteProvisioningRequest::STATUS_ERROR;
            if ($effectiveErrorCode === '') {
                $effectiveErrorCode = 'PROVISIONING_QUEUE_FAILED';
                $message = (string)__('域名准备任务执行失败，请明确重试。');
            }
        }

        return [
            'success' => $effectiveStatus !== AiSiteProvisioningRequest::STATUS_ERROR,
            'code' => $effectiveStatus === AiSiteProvisioningRequest::STATUS_ERROR
                ? ($effectiveErrorCode ?: 'PROVISIONING_FAILED')
                : 'OK',
            'message' => $message,
            'request_id' => $request->getRequestId(),
            'source_module' => (string)$request->getData(AiSiteProvisioningRequest::schema_fields_SOURCE_MODULE),
            'source_public_id' => (string)$request->getData(AiSiteProvisioningRequest::schema_fields_SOURCE_PUBLIC_ID),
            'client_request_id' => (string)$request->getData(AiSiteProvisioningRequest::schema_fields_CLIENT_REQUEST_ID),
            'domain_mode' => (string)$request->getData(AiSiteProvisioningRequest::schema_fields_DOMAIN_MODE),
            'target_domain' => (string)$request->getData(AiSiteProvisioningRequest::schema_fields_TARGET_DOMAIN),
            'sub_path' => $this->normalizeSubPath(
                (string)$request->getData(AiSiteProvisioningRequest::schema_fields_SUB_PATH)
            ),
            'registrar_account_id' => $request->getData(AiSiteProvisioningRequest::schema_fields_REGISTRAR_ACCOUNT_ID) === null
                ? null
                : (int)$request->getData(AiSiteProvisioningRequest::schema_fields_REGISTRAR_ACCOUNT_ID),
            'years' => (int)$request->getData(AiSiteProvisioningRequest::schema_fields_YEARS),
            'purchase_order_id' => (int)$request->getData(AiSiteProvisioningRequest::schema_fields_PURCHASE_ORDER_ID),
            'website_bound' => (int)$request->getData(AiSiteProvisioningRequest::schema_fields_WEBSITE_BOUND),
            'website_id' => (int)$request->getData(AiSiteProvisioningRequest::schema_fields_WEBSITE_ID),
            'requested_website_id' => (int)($request->getRequestedWebsiteId() ?? 0),
            'status' => $effectiveStatus,
            'queue' => [
                'queue_id' => $queueId,
                'status' => (string)($queue['status'] ?? ''),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function rearmRequest(AiSiteProvisioningRequest $request): array
    {
        $executionToken = \trim((string)$request->getData(
            AiSiteProvisioningRequest::schema_fields_EXECUTION_TOKEN
        ));
        if ($executionToken === '') {
            $executionToken = \bin2hex(\random_bytes(16));
        }

        $queue = $this->queueGateway->rearm($request->getRequestId(), $executionToken);
        $queueId = (int)($queue['queue_id'] ?? 0);
        if ($queueId <= 0) {
            throw new AiSiteProvisioningException(
                'QUEUE_CREATE_FAILED',
                (string)__('域名准备任务重试失败，请稍后再试。')
            );
        }

        $queueStatus = \strtolower(\trim((string)($queue['status'] ?? '')));
        $rearmed = !empty($queue['rearmed']);
        $requestStatus = (string)$request->getData(AiSiteProvisioningRequest::schema_fields_STATUS);
        $nextRequestStatus = $queueStatus === 'running'
            ? AiSiteProvisioningRequest::STATUS_RUNNING
            : AiSiteProvisioningRequest::STATUS_PENDING;
        $reconcileInProgress = \in_array($queueStatus, ['pending', 'queued', 'running'], true)
            && (
                $rearmed
                || $requestStatus !== $nextRequestStatus
                || (int)$request->getData(AiSiteProvisioningRequest::schema_fields_QUEUE_ID) !== $queueId
                || (string)$request->getData(AiSiteProvisioningRequest::schema_fields_ERROR_CODE) !== ''
            );

        if ($reconcileInProgress) {
            $request->setData(AiSiteProvisioningRequest::schema_fields_STATUS, $nextRequestStatus);
            $request->setData(AiSiteProvisioningRequest::schema_fields_QUEUE_ID, $queueId);
            $request->setData(AiSiteProvisioningRequest::schema_fields_EXECUTION_TOKEN, $executionToken);
            $request->setData(AiSiteProvisioningRequest::schema_fields_ERROR_CODE, '');
            $request->setData(
                AiSiteProvisioningRequest::schema_fields_MESSAGE,
                (string)__('域名准备任务已重新排队，等待系统调度。')
            );
            $this->requestRepository->save($request);
        }

        return \array_replace($this->formatRequest($request), [
            'queue' => $queue,
            'queue_id' => $queueId,
            'queue_status' => $queueStatus,
            'biz_key' => (string)($queue['biz_key'] ?? $this->queueGateway->buildBizKey($request->getRequestId())),
            'rearmed' => $rearmed,
            'idempotent' => (bool)($queue['idempotent'] ?? !$rearmed),
        ]);
    }

    /**
     * @param array<string, mixed> $command
     * @return array{source_module:string,admin_user_id:int,source_public_id:string,client_request_id:string,domain_mode:string,target_domain:string,sub_path:string,registrar_account_id:?int,years:int,purchase_confirmed:int,requested_website_id:?int}
     */
    private function normalizeCommand(array $command): array
    {
        $sourceModule = \trim((string)($command['source_module'] ?? self::DEFAULT_SOURCE_MODULE));
        $adminUserId = $this->positiveInteger($command['admin_user_id'] ?? null);
        $sourcePublicId = \trim((string)($command['source_public_id'] ?? ''));
        $clientRequestId = \trim((string)($command['client_request_id'] ?? $command['command_id'] ?? ''));
        $domainMode = \strtolower(\trim((string)($command['domain_mode'] ?? '')));
        $defaultLocale = \trim((string)($command['default_locale'] ?? ''));
        if ($defaultLocale !== '' && (\strlen($defaultLocale) > 64 || \preg_match('/^[A-Za-z][A-Za-z0-9_@-]*$/D', $defaultLocale) !== 1)) {
            throw new AiSiteProvisioningException('DEFAULT_LOCALE_INVALID', (string)__('默认语言格式无效。'));
        }

        if ($sourceModule === '' || \strlen($sourceModule) > 64 || !\preg_match('/^[A-Za-z0-9_]+$/', $sourceModule)) {
            throw new AiSiteProvisioningException('INVALID_SOURCE_MODULE', (string)__('请求来源模块无效。'));
        }
        if ($adminUserId === null) {
            throw new AiSiteProvisioningException('ADMIN_USER_REQUIRED', (string)__('必须由已登录的后台管理员发起域名准备。'));
        }
        if ($sourcePublicId === '' || \strlen($sourcePublicId) > 64) {
            throw new AiSiteProvisioningException('SOURCE_PUBLIC_ID_REQUIRED', (string)__('来源会话公开 ID 不能为空。'));
        }
        if ($clientRequestId === '' || \strlen($clientRequestId) > 128) {
            throw new AiSiteProvisioningException('CLIENT_REQUEST_ID_REQUIRED', (string)__('幂等命令 ID 不能为空。'));
        }
        if (!\in_array($domainMode, [
            AiSiteProvisioningRequest::DOMAIN_MODE_TEST,
            AiSiteProvisioningRequest::DOMAIN_MODE_PURCHASE,
            AiSiteProvisioningRequest::DOMAIN_MODE_BIND,
        ], true)) {
            throw new AiSiteProvisioningException(
                'DOMAIN_MODE_UNSUPPORTED',
                (string)__('当前站点绑定不支持域名模式：%{1}', $domainMode)
            );
        }
        $targetDomain = $this->normalizeDomain((string)($command['target_domain'] ?? $command['domain'] ?? ''));
        if ($targetDomain === '') {
            throw new AiSiteProvisioningException('TARGET_DOMAIN_REQUIRED', (string)__('目标域名格式无效。'));
        }
        $subPath = $this->normalizeSubPath((string)($command['sub_path'] ?? $command['mount_path'] ?? ''));
        $managedLocal = $this->isManagedLocalDomain($targetDomain);
        if ($domainMode === AiSiteProvisioningRequest::DOMAIN_MODE_TEST && !$managedLocal) {
            throw new AiSiteProvisioningException('TEST_DOMAIN_REQUIRED', (string)__('测试模式必须使用 *.weline.test 本地域名。'));
        }
        if ($domainMode === AiSiteProvisioningRequest::DOMAIN_MODE_PURCHASE && $managedLocal) {
            throw new AiSiteProvisioningException('PUBLIC_DOMAIN_REQUIRED', (string)__('正式购买模式必须选择可公开注册的域名。'));
        }
        if ($domainMode === AiSiteProvisioningRequest::DOMAIN_MODE_BIND && $managedLocal) {
            // Local hosts still go through bind-only; keep mode as bind when selected from pool.
        }

        $registrarAccountId = null;
        $years = 1;
        $purchaseConfirmed = 0;
        if ($domainMode === AiSiteProvisioningRequest::DOMAIN_MODE_PURCHASE) {
            $registrarAccountId = $this->positiveInteger($command['registrar_account_id'] ?? $command['account_id'] ?? null);
            if ($registrarAccountId === null) {
                throw new AiSiteProvisioningException('REGISTRAR_ACCOUNT_REQUIRED', (string)__('正式购买前必须选择域名购买账户。'));
            }
            $years = \max(1, \min(10, (int)($command['years'] ?? 1)));
            $purchaseConfirmed = $this->truthy($command['confirm'] ?? $command['purchase_confirmed'] ?? false) ? 1 : 0;
            if ($purchaseConfirmed !== 1) {
                throw new AiSiteProvisioningException('PURCHASE_CONFIRMATION_REQUIRED', (string)__('正式域名购买需要明确确认。'));
            }
        }

        return [
            'source_module' => $sourceModule,
            'admin_user_id' => $adminUserId,
            'source_public_id' => $sourcePublicId,
            'client_request_id' => $clientRequestId,
            'domain_mode' => $domainMode,
            'target_domain' => $targetDomain,
            'sub_path' => $subPath,
            'registrar_account_id' => $registrarAccountId,
            'years' => $years,
            'purchase_confirmed' => $purchaseConfirmed,
            'requested_website_id' => $this->requestedWebsiteId($command['requested_website_id'] ?? null),
            'default_locale' => $defaultLocale,
            'rearm_failed' => $this->truthy($command['rearm_failed'] ?? false),
        ];
    }

    /**
     * Upgrade legacy requests that stored requested_website_id=0 so a later
     * Start can attach the shell website allocated at requirements confirmation.
     * Also backfill a dehydrated publish command from the durable request.
     *
     * @param array<string, mixed> $command
     */
    private function adoptShellRequestedWebsiteId(
        AiSiteProvisioningRequest $request,
        array &$command
    ): void {
        $this->alignRequestedWebsiteId($request, $command);
    }

    /**
     * Keep request ↔ command requested_website_id aligned in either direction:
     * - command has shell id, request still 0 → persist onto request
     * - request already has shell id, dehydrated command has 0 → fill command
     *
     * @param array<string, mixed> $command
     */
    private function alignRequestedWebsiteId(
        AiSiteProvisioningRequest $request,
        array &$command
    ): void {
        $incoming = $command['requested_website_id'] ?? null;
        $incomingId = \is_int($incoming) ? $incoming : 0;
        $existing = $request->getRequestedWebsiteId();
        $existingId = $existing === null ? 0 : (int)$existing;

        if ($incomingId <= Website::ID_DEFAULT && $existingId > Website::ID_DEFAULT) {
            $command['requested_website_id'] = $existingId;

            return;
        }
        if ($incomingId <= Website::ID_DEFAULT || $existingId > Website::ID_DEFAULT) {
            return;
        }
        $request->setData(AiSiteProvisioningRequest::schema_fields_REQUESTED_WEBSITE_ID, $incomingId);
        $this->requestRepository->save($request);
    }

    /**
     * @param array{source_module:string,admin_user_id:int,source_public_id:string,client_request_id:string,domain_mode:string,target_domain:string,sub_path:string,registrar_account_id:?int,years:int,purchase_confirmed:int,requested_website_id:?int} $command
     */
    private function assertSameCommand(AiSiteProvisioningRequest $request, array $command): void
    {
        if (
            (string)$request->getData(AiSiteProvisioningRequest::schema_fields_SOURCE_PUBLIC_ID) !== $command['source_public_id']
            || (int)$request->getData(AiSiteProvisioningRequest::schema_fields_ADMIN_USER_ID) !== $command['admin_user_id']
            || (string)$request->getData(AiSiteProvisioningRequest::schema_fields_DOMAIN_MODE) !== $command['domain_mode']
            || (string)$request->getData(AiSiteProvisioningRequest::schema_fields_TARGET_DOMAIN) !== $command['target_domain']
            || $this->normalizeSubPath((string)$request->getData(AiSiteProvisioningRequest::schema_fields_SUB_PATH))
                !== $this->normalizeSubPath((string)($command['sub_path'] ?? ''))
            || (int)($request->getData(AiSiteProvisioningRequest::schema_fields_REGISTRAR_ACCOUNT_ID) ?? 0) !== (int)($command['registrar_account_id'] ?? 0)
            || (int)$request->getData(AiSiteProvisioningRequest::schema_fields_YEARS) !== $command['years']
            || (int)$request->getData(AiSiteProvisioningRequest::schema_fields_PURCHASE_CONFIRMED) !== $command['purchase_confirmed']
            || (int)($request->getRequestedWebsiteId() ?? 0) !== (int)($command['requested_website_id'] ?? 0)
        ) {
            throw new AiSiteProvisioningException(
                'PROVISIONING_COMMAND_CONFLICT',
                (string)__('同一幂等命令 ID 的站点绑定参数不一致。')
            );
        }
    }

    private function requestedWebsiteId(mixed $value): int
    {
        if (\is_int($value) && $value >= 0) {
            return $value;
        }
        if (\is_string($value) && \preg_match('/^(0|[1-9]\d*)$/D', $value) === 1) {
            return (int)$value;
        }

        return 0;
    }

    private function normalizeDomain(string $value): string
    {
        $value = \strtolower(\trim($value));
        $host = \parse_url(\str_contains($value, '://') ? $value : 'http://' . $value, PHP_URL_HOST);
        $domain = \is_string($host) ? \trim($host, '.[]') : '';
        if ($domain === '' || \strlen($domain) > 253) {
            return '';
        }

        return \preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $domain) === 1
            ? $domain
            : '';
    }

    private function normalizeSubPath(string $subPath): string
    {
        $subPath = \trim($subPath);
        if ($subPath === '' || $subPath === '/') {
            return '';
        }
        $subPath = '/' . \trim($subPath, '/');

        return $subPath === '/' ? '' : $subPath;
    }

    private function isManagedLocalDomain(string $domain): bool
    {
        return \preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.weline\.test$/D', $domain) === 1;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (\is_int($value) && $value > 0) {
            return $value;
        }
        if (\is_string($value) && \preg_match('/^[1-9]\d*$/D', $value) === 1) {
            return (int)$value;
        }

        return null;
    }

    private function truthy(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || \in_array(\strtolower(\trim((string)$value)), ['true', 'yes', 'confirmed'], true);
    }
}
