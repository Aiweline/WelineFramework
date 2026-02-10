<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Service;

use Weline\Cdn\Api\AdapterInterface;
use Weline\Cdn\Model\Account;
use Weline\Framework\Event\EventsManager;

/**
 * CDN 供应商与账户列表服务
 *
 * 通过事件允许其他模块补充供应商/账户
 *
 * @package Weline_Cdn
 */
class ProviderManager
{
    private AdapterResolver $adapterResolver;
    private Account $accountModel;
    private EventsManager $eventsManager;

    public function __construct(
        AdapterResolver $adapterResolver,
        Account $accountModel,
        EventsManager $eventsManager
    ) {
        $this->adapterResolver = $adapterResolver;
        $this->accountModel = $accountModel;
        $this->eventsManager = $eventsManager;
    }

    /**
     * 获取 CDN 供应商列表
     *
     * @return array<int, array{code:string,name:string,description:string,version:string}>
     */
    public function getProviders(): array
    {
        $providers = [];
        $adapters = $this->adapterResolver->getAllAdapters();
        foreach ($adapters as $code => $adapter) {
            if (!$adapter instanceof AdapterInterface) {
                continue;
            }
            $providers[] = [
                'code' => (string)$adapter->getAdapterCode(),
                'name' => (string)$adapter->getAdapterName(),
                'description' => (string)$adapter->getDescription(),
                'version' => (string)$adapter->getVersion(),
            ];
        }

        $eventData = [
            'providers' => $providers,
        ];
        $this->eventsManager->dispatch('Weline_Cdn::provider::list', $eventData);

        $providers = $eventData['providers'] ?? $providers;
        return is_array($providers) ? $providers : [];
    }

    /**
     * 获取账户列表（可按供应商/适配器过滤）
     *
     * @param string $adapterCode
     * @return array<int, array<string, mixed>>
     */
    public function getAccounts(string $adapterCode = ''): array
    {
        $query = $this->accountModel->reset()
            ->where(Account::fields_STATUS, Account::STATUS_ACTIVE)
            ->order(Account::fields_NAME, 'ASC');

        if ($adapterCode !== '') {
            $query->where(Account::fields_ADAPTER, $adapterCode);
        }

        $accounts = $query->select()->fetchArray();

        $eventData = [
            'adapter' => $adapterCode,
            'accounts' => $accounts,
        ];
        $this->eventsManager->dispatch('Weline_Cdn::account::list', $eventData);

        $accounts = $eventData['accounts'] ?? $accounts;
        return is_array($accounts) ? $accounts : [];
    }
}
