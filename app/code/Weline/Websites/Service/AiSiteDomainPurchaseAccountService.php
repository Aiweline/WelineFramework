<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Websites\Api\DomainRegistrarInterface;
use Weline\Websites\Exception\AiSiteProvisioningException;
use Weline\Websites\Model\DomainRegistrar;
use Weline\Websites\Model\DomainRegistrarAccount;

/** Safe registrar-account catalog and availability boundary for AI-site purchases. */
final class AiSiteDomainPurchaseAccountService
{
    public function __construct(
        private readonly DomainRegistrarAccount $accountModel,
        private readonly DomainRegistrar $registrarModel,
        private readonly DomainRegistrarResolverService $resolverService,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function listSelectable(): array
    {
        $model = clone $this->accountModel;
        $rows = $model->clearQuery()
            ->where(DomainRegistrarAccount::schema_fields_STATUS, DomainRegistrarAccount::STATUS_ACTIVE)
            ->order(DomainRegistrarAccount::schema_fields_ACCOUNT_NAME, 'ASC')
            ->select()
            ->fetchArray();
        $result = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $accountId = (int)($row[DomainRegistrarAccount::schema_fields_ID] ?? 0);
            $registrarId = (int)($row[DomainRegistrarAccount::schema_fields_REGISTRAR_ID] ?? 0);
            if ($accountId <= 0 || $registrarId <= 0) {
                continue;
            }
            $registrar = $this->registrar($registrarId);
            $adapter = $this->adapter($registrar);
            if (!$adapter instanceof DomainRegistrarInterface || !$adapter->isDomainRegistrar()) {
                continue;
            }
            $result[] = [
                'account_id' => $accountId,
                'account_name' => (string)($row[DomainRegistrarAccount::schema_fields_ACCOUNT_NAME] ?? ''),
                'registrar_code' => (string)$registrar->getData(DomainRegistrar::schema_fields_CODE),
                'registrar_name' => (string)$registrar->getData(DomainRegistrar::schema_fields_NAME),
                'region' => (string)($row[DomainRegistrarAccount::schema_fields_REGION] ?? ''),
                'status' => DomainRegistrarAccount::STATUS_ACTIVE,
                'purchase_capable' => true,
            ];
        }

        return $result;
    }

    /** @return array{account:DomainRegistrarAccount,adapter:DomainRegistrarInterface} */
    public function requireSelectable(int $accountId): array
    {
        $account = clone $this->accountModel;
        $account->clearData()->clearQuery()->load($accountId);
        if ($account->getAccountId() <= 0) {
            throw new AiSiteProvisioningException('REGISTRAR_ACCOUNT_NOT_FOUND', (string)__('域名购买账户不存在。'));
        }
        if ($account->getStatus() !== DomainRegistrarAccount::STATUS_ACTIVE) {
            throw new AiSiteProvisioningException('REGISTRAR_ACCOUNT_DISABLED', (string)__('域名购买账户已停用。'));
        }
        $registrar = $this->registrar($account->getRegistrarId());
        $adapter = $this->adapter($registrar);
        if (!$adapter instanceof DomainRegistrarInterface || !$adapter->isDomainRegistrar()) {
            throw new AiSiteProvisioningException(
                'REGISTRAR_ACCOUNT_NOT_PURCHASE_CAPABLE',
                (string)__('所选账户不支持域名购买。')
            );
        }

        return ['account' => $account, 'adapter' => $adapter];
    }

    /** @return array{domain:string,available:bool,price:?float,currency:string,premium:bool,message:string} */
    public function checkAvailability(int $accountId, string $domain): array
    {
        $selection = $this->requireSelectable($accountId);
        try {
            $result = $selection['adapter']->checkAvailability($domain, $selection['account']->getCredentials());
        } catch (\Throwable $throwable) {
            throw new AiSiteProvisioningException(
                'DOMAIN_AVAILABILITY_CHECK_FAILED',
                (string)__('域名可用性检查失败：%{1}', $throwable->getMessage())
            );
        }
        if (!\is_array($result)) {
            throw new AiSiteProvisioningException(
                'DOMAIN_AVAILABILITY_CHECK_FAILED',
                (string)__('域名可用性服务返回了无效结果。')
            );
        }

        return [
            'domain' => \strtolower(\trim((string)($result['domain'] ?? $domain))),
            'available' => ($result['available'] ?? false) === true,
            'price' => isset($result['price']) && \is_numeric($result['price']) ? (float)$result['price'] : null,
            'currency' => \trim((string)($result['currency'] ?? '')),
            'premium' => ($result['premium'] ?? false) === true,
            'message' => \trim((string)($result['message'] ?? $result['error'] ?? '')),
        ];
    }

    private function registrar(int $registrarId): DomainRegistrar
    {
        $registrar = clone $this->registrarModel;
        $registrar->clearData()->clearQuery()->load($registrarId);
        if ((int)$registrar->getData(DomainRegistrar::schema_fields_ID) <= 0) {
            throw new AiSiteProvisioningException('REGISTRAR_NOT_FOUND', (string)__('域名注册商不存在。'));
        }

        return $registrar;
    }

    private function adapter(DomainRegistrar $registrar): ?DomainRegistrarInterface
    {
        $code = \trim((string)$registrar->getData(DomainRegistrar::schema_fields_CODE));

        return $code !== '' ? $this->resolverService->getAdapter($code) : null;
    }
}
