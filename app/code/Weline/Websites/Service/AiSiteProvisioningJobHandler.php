<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Websites\Exception\AiSiteProvisioningException;
use Weline\Websites\Model\AiSiteProvisioningRequest;

class AiSiteProvisioningJobHandler
{
    public function __construct(
        private readonly AiSiteProvisioningRequestRepository $requestRepository,
        private readonly AiSiteDomainPreparationService $domainPreparationService
    ) {
    }

    public function canHandle(string $requestId, string $executionToken): bool
    {
        $request = $this->requestRepository->findByRequestId($requestId);
        if (!$request instanceof AiSiteProvisioningRequest) {
            return false;
        }

        $storedToken = (string)$request->getData(AiSiteProvisioningRequest::schema_fields_EXECUTION_TOKEN);
        if ($storedToken === '' || $executionToken === '' || !\hash_equals($storedToken, $executionToken)) {
            return false;
        }

        return (string)$request->getData(AiSiteProvisioningRequest::schema_fields_STATUS)
            !== AiSiteProvisioningRequest::STATUS_ERROR;
    }

    /** @return array<string, mixed> */
    public function handle(
        string $requestId,
        string $executionToken,
        bool $authorizationWaitExpired = false
    ): array
    {
        $request = $this->requestRepository->findByRequestId($requestId);
        if (!$request instanceof AiSiteProvisioningRequest) {
            throw new AiSiteProvisioningException('PROVISIONING_REQUEST_NOT_FOUND', (string)__('站点绑定请求不存在。'));
        }

        $storedToken = (string)$request->getData(AiSiteProvisioningRequest::schema_fields_EXECUTION_TOKEN);
        if ($storedToken === '' || $executionToken === '' || !\hash_equals($storedToken, $executionToken)) {
            throw new AiSiteProvisioningException('EXECUTION_TOKEN_MISMATCH', (string)__('站点绑定执行令牌不匹配。'));
        }

        $status = (string)$request->getData(AiSiteProvisioningRequest::schema_fields_STATUS);
        $domain = (string)$request->getData(AiSiteProvisioningRequest::schema_fields_TARGET_DOMAIN);
        $subPath = $this->normalizeSubPath(
            (string)$request->getData(AiSiteProvisioningRequest::schema_fields_SUB_PATH)
        );
        $websiteId = (int)$request->getData(AiSiteProvisioningRequest::schema_fields_WEBSITE_ID);
        if ($status === AiSiteProvisioningRequest::STATUS_DONE
            && (int)$request->getData(AiSiteProvisioningRequest::schema_fields_WEBSITE_BOUND) === 1
            && $this->domainPreparationService->isBound($domain, $websiteId, $subPath)
        ) {
            return $this->project($request);
        }
        if ($status === AiSiteProvisioningRequest::STATUS_DONE
            && (string)$request->getData(AiSiteProvisioningRequest::schema_fields_DOMAIN_MODE)
                === AiSiteProvisioningRequest::DOMAIN_MODE_PURCHASE
        ) {
            $message = (string)__('购买结果已存在，但域名没有绑定到站点；为避免重复扣费，请先核对域名订单。');
            $request->setData(AiSiteProvisioningRequest::schema_fields_WEBSITE_BOUND, 0)
                ->setData(AiSiteProvisioningRequest::schema_fields_STATUS, AiSiteProvisioningRequest::STATUS_ERROR)
                ->setData(AiSiteProvisioningRequest::schema_fields_ERROR_CODE, 'WEBSITE_DOMAIN_BINDING_MISSING')
                ->setData(AiSiteProvisioningRequest::schema_fields_MESSAGE, $message);
            $this->requestRepository->save($request);
            throw new AiSiteProvisioningException('WEBSITE_DOMAIN_BINDING_MISSING', $message);
        }
        if ($authorizationWaitExpired) {
            $message = (string)__('等待 macOS 管理员批准 hosts 配置已超时；请确认系统弹窗后再明确重试。');
            $request->setData(AiSiteProvisioningRequest::schema_fields_WEBSITE_BOUND, 0)
                ->setData(AiSiteProvisioningRequest::schema_fields_STATUS, AiSiteProvisioningRequest::STATUS_ERROR)
                ->setData(
                    AiSiteProvisioningRequest::schema_fields_ERROR_CODE,
                    'TEST_DOMAIN_HOSTS_AUTHORIZATION_EXPIRED'
                )
                ->setData(AiSiteProvisioningRequest::schema_fields_MESSAGE, $message);
            $this->requestRepository->save($request);

            throw new AiSiteProvisioningException(
                'TEST_DOMAIN_HOSTS_AUTHORIZATION_EXPIRED',
                $message
            );
        }

        $request->setData(AiSiteProvisioningRequest::schema_fields_STATUS, AiSiteProvisioningRequest::STATUS_RUNNING)
            ->setData(AiSiteProvisioningRequest::schema_fields_MESSAGE, (string)__('正在准备域名并绑定站点。'));
        $this->requestRepository->save($request);

        try {
            $prepared = $this->domainPreparationService->prepare(
                $request,
                function () use ($request): void {
                    $request->setData(AiSiteProvisioningRequest::schema_fields_PURCHASE_ATTEMPTED, 1);
                    $this->requestRepository->save($request);
                }
            );
            if (($prepared['authorization_pending'] ?? false) === true) {
                $message = \trim((string)($prepared['message'] ?? ''))
                    ?: (string)__('正在等待 macOS 管理员批准本地域名 hosts 配置。');
                $request->setData(
                    AiSiteProvisioningRequest::schema_fields_STATUS,
                    AiSiteProvisioningRequest::STATUS_PENDING
                )
                    ->setData(AiSiteProvisioningRequest::schema_fields_ERROR_CODE, '')
                    ->setData(AiSiteProvisioningRequest::schema_fields_MESSAGE, $message);
                $this->requestRepository->save($request);

                return \array_replace($this->project($request), [
                    'authorization_pending' => true,
                    'authorization_already_started' =>
                        ($prepared['authorization_already_started'] ?? false) === true,
                    'message' => $message,
                ]);
            }
            $websiteId = (int)($prepared['website_id'] ?? 0);
            if (!$this->domainPreparationService->isBound($domain, $websiteId, $subPath)) {
                throw new AiSiteProvisioningException(
                    'WEBSITE_DOMAIN_BINDING_FAILED',
                    (string)__('域名准备完成，但没有找到有效的站点绑定记录。')
                );
            }
            $request->setData(AiSiteProvisioningRequest::schema_fields_WEBSITE_BOUND, 1)
                ->setData(AiSiteProvisioningRequest::schema_fields_WEBSITE_ID, $websiteId)
                ->setData(
                    AiSiteProvisioningRequest::schema_fields_PURCHASE_ORDER_ID,
                    (int)($prepared['purchase_order_id'] ?? 0)
                )
                ->setData(AiSiteProvisioningRequest::schema_fields_STATUS, AiSiteProvisioningRequest::STATUS_DONE)
                ->setData(AiSiteProvisioningRequest::schema_fields_ERROR_CODE, '')
                ->setData(AiSiteProvisioningRequest::schema_fields_MESSAGE, (string)__('域名准备与站点绑定完成。'));
            $this->requestRepository->save($request);
        } catch (\Throwable $throwable) {
            $errorCode = $throwable instanceof AiSiteProvisioningException
                ? $throwable->getErrorCode()
                : 'PROVISIONING_FAILED';
            $request->setData(AiSiteProvisioningRequest::schema_fields_WEBSITE_BOUND, 0)
                ->setData(AiSiteProvisioningRequest::schema_fields_STATUS, AiSiteProvisioningRequest::STATUS_ERROR)
                ->setData(AiSiteProvisioningRequest::schema_fields_ERROR_CODE, $errorCode)
                ->setData(AiSiteProvisioningRequest::schema_fields_MESSAGE, $throwable->getMessage());
            $this->requestRepository->save($request);

            throw new \RuntimeException(
                (string)__('域名准备失败：%{1}', $throwable->getMessage()),
                0,
                $throwable
            );
        }

        return $this->project($request);
    }

    /** @return array<string, mixed> */
    private function project(AiSiteProvisioningRequest $request): array
    {
        return [
            'request_id' => $request->getRequestId(),
            'status' => (string)$request->getData(AiSiteProvisioningRequest::schema_fields_STATUS),
            'website_bound' => (int)$request->getData(AiSiteProvisioningRequest::schema_fields_WEBSITE_BOUND),
            'website_id' => (int)$request->getData(AiSiteProvisioningRequest::schema_fields_WEBSITE_ID),
            'domain_mode' => (string)$request->getData(AiSiteProvisioningRequest::schema_fields_DOMAIN_MODE),
            'target_domain' => (string)$request->getData(AiSiteProvisioningRequest::schema_fields_TARGET_DOMAIN),
            'sub_path' => $this->normalizeSubPath(
                (string)$request->getData(AiSiteProvisioningRequest::schema_fields_SUB_PATH)
            ),
            'registrar_account_id' => $request->getData(AiSiteProvisioningRequest::schema_fields_REGISTRAR_ACCOUNT_ID) === null
                ? null
                : (int)$request->getData(AiSiteProvisioningRequest::schema_fields_REGISTRAR_ACCOUNT_ID),
            'purchase_order_id' => (int)$request->getData(AiSiteProvisioningRequest::schema_fields_PURCHASE_ORDER_ID),
        ];
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
}
