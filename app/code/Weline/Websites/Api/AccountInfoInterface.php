<?php
declare(strict_types=1);

/**
 * 账户信息能力接口（可选）
 *
 * 提供账户余额、TLD 价格、联系人模板等附加信息的供应商实现此接口。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Api;

interface AccountInfoInterface
{
    /**
     * 获取账户余额
     *
     * @param array $credentials API 凭据
     * @return array{balance: string, currency: string}
     */
    public function getAccountBalance(array $credentials): array;

    /**
     * 获取 TLD 价格列表
     *
     * @param array $credentials API 凭据
     * @return array<array{Tld: string, Register: string, Renew: string, Transfer: string}>
     */
    public function getTldPrices(array $credentials): array;

    /**
     * 获取联系人模板列表
     *
     * @param array $credentials API 凭据
     * @return array
     */
    public function getContactTemplates(array $credentials): array;
}
