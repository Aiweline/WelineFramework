<?php
declare(strict_types=1);

/**
 * DNS 记录管理能力接口
 *
 * 支持 DNS 记录 CRUD 的供应商实现此接口。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Api;

interface DnsManagementInterface
{
    /**
     * 是否支持 DNS 记录管理
     *
     * @return bool
     */
    public function supportsDnsManagement(): bool;

    /**
     * 获取域名的 DNS 解析记录列表
     *
     * @param string $domain 域名
     * @param array $credentials API 凭据
     * @return array<array{record_id: string, type: string, host: string, value: string, ttl: int, priority?: int}>
     */
    public function getDnsRecords(string $domain, array $credentials): array;

    /**
     * 添加 DNS 解析记录
     *
     * @param string $domain 域名
     * @param array $record {type, host, value, ttl, priority?}
     * @param array $credentials API 凭据
     * @return array{success: bool, record_id?: string, message?: string}
     */
    public function addDnsRecord(string $domain, array $record, array $credentials): array;

    /**
     * 更新 DNS 解析记录
     *
     * @param string $domain 域名
     * @param string $recordId 记录 ID
     * @param array $record {type, host, value, ttl, priority?}
     * @param array $credentials API 凭据
     * @return array{success: bool, message?: string}
     */
    public function updateDnsRecord(string $domain, string $recordId, array $record, array $credentials): array;

    /**
     * 删除 DNS 解析记录
     *
     * @param string $domain 域名
     * @param string $recordId 记录 ID
     * @param array $credentials API 凭据
     * @return array{success: bool, message?: string}
     */
    public function deleteDnsRecord(string $domain, string $recordId, array $credentials): array;

    /**
     * 批量添加 DNS 解析记录
     *
     * @param string $domain 域名
     * @param array $records 记录数组
     * @param array $credentials API 凭据
     * @return array{success: bool, added: int, failed: int, errors?: array}
     */
    public function batchAddDnsRecords(string $domain, array $records, array $credentials): array;
}
