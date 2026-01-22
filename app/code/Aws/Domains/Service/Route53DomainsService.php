<?php

declare(strict_types=1);

/*
 * AWS Domains 管理模块
 * AWS Route 53 Domains 服务类
 */

namespace Aws\Domains\Service;

use Aws\Domains\Model\AwsConfig;
use Aws\Domains\Model\DomainOperation;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Weline\Framework\Manager\ObjectManager;

/**
 * AWS Route 53 Domains 服务
 * 封装 AWS Route 53 Domains API 调用
 */
class Route53DomainsService
{
    private ?AwsConfig $config = null;
    private Client $httpClient;
    private string $service = 'route53domains';
    private string $apiVersion = '2014-05-15';

    public function __construct(?AwsConfig $config = null)
    {
        if ($config !== null) {
            $this->config = $config;
        }
        $this->httpClient = new Client([
            'timeout' => 30,
            'http_errors' => false,
        ]);
    }

    /**
     * 设置 AWS 配置
     */
    public function setConfig(AwsConfig $config): self
    {
        $this->config = $config;
        return $this;
    }

    /**
     * 获取当前配置
     */
    public function getConfig(): ?AwsConfig
    {
        return $this->config;
    }

    /**
     * 使用默认配置
     */
    public function useDefaultConfig(): self
    {
        $this->config = AwsConfig::getDefaultConfig();
        return $this;
    }

    /**
     * 检查域名可用性
     *
     * @param string $domainName 域名
     * @return array ['available' => bool, 'availability' => string, 'price' => array|null]
     */
    public function checkDomainAvailability(string $domainName): array
    {
        $operation = DomainOperation::createLog(
            DomainOperation::OPERATION_CHECK_AVAILABILITY,
            $this->config?->getId(),
            $domainName
        );

        try {
            $result = $this->callApi('CheckDomainAvailability', [
                'DomainName' => $domainName,
            ]);

            $operation->markSuccess(null, $result);

            return [
                'success' => true,
                'available' => ($result['Availability'] ?? '') === 'AVAILABLE',
                'availability' => $result['Availability'] ?? 'UNKNOWN',
                'data' => $result,
            ];
        } catch (\Throwable $e) {
            $operation->markFailed($e->getMessage());
            return [
                'success' => false,
                'available' => false,
                'availability' => 'ERROR',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 批量检查域名可用性
     *
     * @param array $domainNames 域名列表
     * @return array
     */
    public function checkDomainAvailabilityBatch(array $domainNames): array
    {
        $results = [];
        foreach ($domainNames as $domain) {
            $results[$domain] = $this->checkDomainAvailability($domain);
        }
        return $results;
    }

    /**
     * 获取域名价格
     *
     * @param string $tld 顶级域名（如 com, net, org）
     * @return array
     */
    public function getDomainPrices(string $tld): array
    {
        $operation = DomainOperation::createLog(
            DomainOperation::OPERATION_GET_PRICE,
            $this->config?->getId(),
            '*.' . $tld
        );

        try {
            $result = $this->callApi('ListPrices', [
                'Tld' => $tld,
            ]);

            $operation->markSuccess(null, $result);

            return [
                'success' => true,
                'prices' => $result['Prices'] ?? [],
                'data' => $result,
            ];
        } catch (\Throwable $e) {
            $operation->markFailed($e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 获取所有 TLD 价格列表
     *
     * @param string|null $marker 分页标记
     * @param int $maxItems 最大返回数量
     * @return array
     */
    public function listAllPrices(?string $marker = null, int $maxItems = 100): array
    {
        $operation = DomainOperation::createLog(
            DomainOperation::OPERATION_GET_PRICE,
            $this->config?->getId(),
            'all-tlds'
        );

        try {
            $params = ['MaxItems' => $maxItems];
            if ($marker !== null) {
                $params['Marker'] = $marker;
            }

            $result = $this->callApi('ListPrices', $params);

            $operation->markSuccess(null, $result);

            return [
                'success' => true,
                'prices' => $result['Prices'] ?? [],
                'next_marker' => $result['NextPageMarker'] ?? null,
                'data' => $result,
            ];
        } catch (\Throwable $e) {
            $operation->markFailed($e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 注册域名
     *
     * @param string $domainName 域名
     * @param int $durationYears 注册年数
     * @param array $adminContact 管理员联系人
     * @param array $registrantContact 注册人联系人
     * @param array $techContact 技术联系人
     * @param bool $autoRenew 是否自动续费
     * @param bool $privacyProtect 是否启用隐私保护
     * @return array
     */
    public function registerDomain(
        string $domainName,
        int $durationYears,
        array $adminContact,
        array $registrantContact,
        array $techContact,
        bool $autoRenew = true,
        bool $privacyProtect = true
    ): array {
        $requestData = [
            'DomainName' => $domainName,
            'DurationInYears' => $durationYears,
            'AdminContact' => $this->formatContactInfo($adminContact),
            'RegistrantContact' => $this->formatContactInfo($registrantContact),
            'TechContact' => $this->formatContactInfo($techContact),
            'AutoRenew' => $autoRenew,
            'PrivacyProtectAdminContact' => $privacyProtect,
            'PrivacyProtectRegistrantContact' => $privacyProtect,
            'PrivacyProtectTechContact' => $privacyProtect,
        ];

        $operation = DomainOperation::createLog(
            DomainOperation::OPERATION_REGISTER,
            $this->config?->getId(),
            $domainName,
            $requestData
        );

        try {
            $result = $this->callApi('RegisterDomain', $requestData);

            $awsOperationId = $result['OperationId'] ?? null;
            $operation->markInProgress($awsOperationId);

            return [
                'success' => true,
                'operation_id' => $awsOperationId,
                'data' => $result,
            ];
        } catch (\Throwable $e) {
            $operation->markFailed($e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 续费域名
     *
     * @param string $domainName 域名
     * @param int $durationYears 续费年数
     * @param int $currentExpiryYear 当前到期年份
     * @return array
     */
    public function renewDomain(string $domainName, int $durationYears, int $currentExpiryYear): array
    {
        $requestData = [
            'DomainName' => $domainName,
            'DurationInYears' => $durationYears,
            'CurrentExpiryYear' => $currentExpiryYear,
        ];

        $operation = DomainOperation::createLog(
            DomainOperation::OPERATION_RENEW,
            $this->config?->getId(),
            $domainName,
            $requestData
        );

        try {
            $result = $this->callApi('RenewDomain', $requestData);

            $awsOperationId = $result['OperationId'] ?? null;
            $operation->markInProgress($awsOperationId);

            return [
                'success' => true,
                'operation_id' => $awsOperationId,
                'data' => $result,
            ];
        } catch (\Throwable $e) {
            $operation->markFailed($e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 获取域名列表
     *
     * @param string|null $marker 分页标记
     * @param int $maxItems 最大返回数量
     * @param string|null $sortCondition 排序条件
     * @param string $sortOrder 排序顺序 ASC/DESC
     * @return array
     */
    public function listDomains(
        ?string $marker = null,
        int $maxItems = 20,
        ?string $sortCondition = null,
        string $sortOrder = 'ASC'
    ): array {
        $operation = DomainOperation::createLog(
            DomainOperation::OPERATION_LIST_DOMAINS,
            $this->config?->getId()
        );

        try {
            $params = ['MaxItems' => $maxItems];

            if ($marker !== null) {
                $params['Marker'] = $marker;
            }

            if ($sortCondition !== null) {
                $params['SortCondition'] = [
                    'Name' => $sortCondition,
                    'SortOrder' => $sortOrder,
                ];
            }

            $result = $this->callApi('ListDomains', $params);

            $operation->markSuccess(null, $result);

            return [
                'success' => true,
                'domains' => $result['Domains'] ?? [],
                'next_marker' => $result['NextPageMarker'] ?? null,
                'data' => $result,
            ];
        } catch (\Throwable $e) {
            $operation->markFailed($e->getMessage());
            return [
                'success' => false,
                'domains' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 获取域名详情
     *
     * @param string $domainName 域名
     * @return array
     */
    public function getDomainDetail(string $domainName): array
    {
        $operation = DomainOperation::createLog(
            DomainOperation::OPERATION_GET_DOMAIN_DETAIL,
            $this->config?->getId(),
            $domainName
        );

        try {
            $result = $this->callApi('GetDomainDetail', [
                'DomainName' => $domainName,
            ]);

            $operation->markSuccess(null, $result);

            return [
                'success' => true,
                'domain' => $result,
            ];
        } catch (\Throwable $e) {
            $operation->markFailed($e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 更新域名服务器
     *
     * @param string $domainName 域名
     * @param array $nameservers 域名服务器列表 [['Name' => 'ns1.example.com', 'GlueIps' => []], ...]
     * @return array
     */
    public function updateDomainNameservers(string $domainName, array $nameservers): array
    {
        $requestData = [
            'DomainName' => $domainName,
            'Nameservers' => $nameservers,
        ];

        $operation = DomainOperation::createLog(
            DomainOperation::OPERATION_UPDATE_NAMESERVER,
            $this->config?->getId(),
            $domainName,
            $requestData
        );

        try {
            $result = $this->callApi('UpdateDomainNameservers', $requestData);

            $awsOperationId = $result['OperationId'] ?? null;
            $operation->markSuccess($awsOperationId, $result);

            return [
                'success' => true,
                'operation_id' => $awsOperationId,
                'data' => $result,
            ];
        } catch (\Throwable $e) {
            $operation->markFailed($e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 更新域名联系人信息
     *
     * @param string $domainName 域名
     * @param array|null $adminContact 管理员联系人
     * @param array|null $registrantContact 注册人联系人
     * @param array|null $techContact 技术联系人
     * @return array
     */
    public function updateDomainContact(
        string $domainName,
        ?array $adminContact = null,
        ?array $registrantContact = null,
        ?array $techContact = null
    ): array {
        $requestData = ['DomainName' => $domainName];

        if ($adminContact !== null) {
            $requestData['AdminContact'] = $this->formatContactInfo($adminContact);
        }
        if ($registrantContact !== null) {
            $requestData['RegistrantContact'] = $this->formatContactInfo($registrantContact);
        }
        if ($techContact !== null) {
            $requestData['TechContact'] = $this->formatContactInfo($techContact);
        }

        $operation = DomainOperation::createLog(
            DomainOperation::OPERATION_UPDATE_CONTACT,
            $this->config?->getId(),
            $domainName,
            $requestData
        );

        try {
            $result = $this->callApi('UpdateDomainContact', $requestData);

            $awsOperationId = $result['OperationId'] ?? null;
            $operation->markSuccess($awsOperationId, $result);

            return [
                'success' => true,
                'operation_id' => $awsOperationId,
                'data' => $result,
            ];
        } catch (\Throwable $e) {
            $operation->markFailed($e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 启用/禁用自动续费
     *
     * @param string $domainName 域名
     * @param bool $enable 是否启用
     * @return array
     */
    public function updateAutoRenew(string $domainName, bool $enable): array
    {
        $operation = DomainOperation::createLog(
            $enable ? DomainOperation::OPERATION_ENABLE_AUTO_RENEW : DomainOperation::OPERATION_DISABLE_AUTO_RENEW,
            $this->config?->getId(),
            $domainName
        );

        try {
            $action = $enable ? 'EnableDomainAutoRenew' : 'DisableDomainAutoRenew';
            $result = $this->callApi($action, [
                'DomainName' => $domainName,
            ]);

            $operation->markSuccess(null, $result);

            return [
                'success' => true,
                'data' => $result,
            ];
        } catch (\Throwable $e) {
            $operation->markFailed($e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 获取操作详情
     *
     * @param string $operationId AWS 操作 ID
     * @return array
     */
    public function getOperationDetail(string $operationId): array
    {
        try {
            $result = $this->callApi('GetOperationDetail', [
                'OperationId' => $operationId,
            ]);

            return [
                'success' => true,
                'operation' => $result,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 获取域名建议
     *
     * @param string $domainName 域名
     * @param int $suggestionCount 建议数量
     * @param bool $onlyAvailable 只返回可用的
     * @return array
     */
    public function getDomainSuggestions(string $domainName, int $suggestionCount = 10, bool $onlyAvailable = true): array
    {
        try {
            $result = $this->callApi('GetDomainSuggestions', [
                'DomainName' => $domainName,
                'SuggestionCount' => $suggestionCount,
                'OnlyAvailable' => $onlyAvailable,
            ]);

            return [
                'success' => true,
                'suggestions' => $result['SuggestionsList'] ?? [],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 转入域名
     *
     * @param string $domainName 域名
     * @param int $durationYears 年数
     * @param string $authCode 授权码
     * @param array $adminContact 管理员联系人
     * @param array $registrantContact 注册人联系人
     * @param array $techContact 技术联系人
     * @param bool $autoRenew 是否自动续费
     * @param bool $privacyProtect 是否隐私保护
     * @return array
     */
    public function transferDomain(
        string $domainName,
        int $durationYears,
        string $authCode,
        array $adminContact,
        array $registrantContact,
        array $techContact,
        bool $autoRenew = true,
        bool $privacyProtect = true
    ): array {
        $requestData = [
            'DomainName' => $domainName,
            'DurationInYears' => $durationYears,
            'AuthCode' => $authCode,
            'AdminContact' => $this->formatContactInfo($adminContact),
            'RegistrantContact' => $this->formatContactInfo($registrantContact),
            'TechContact' => $this->formatContactInfo($techContact),
            'AutoRenew' => $autoRenew,
            'PrivacyProtectAdminContact' => $privacyProtect,
            'PrivacyProtectRegistrantContact' => $privacyProtect,
            'PrivacyProtectTechContact' => $privacyProtect,
        ];

        $operation = DomainOperation::createLog(
            DomainOperation::OPERATION_TRANSFER_IN,
            $this->config?->getId(),
            $domainName,
            $requestData
        );

        try {
            $result = $this->callApi('TransferDomain', $requestData);

            $awsOperationId = $result['OperationId'] ?? null;
            $operation->markInProgress($awsOperationId);

            return [
                'success' => true,
                'operation_id' => $awsOperationId,
                'data' => $result,
            ];
        } catch (\Throwable $e) {
            $operation->markFailed($e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 格式化联系人信息
     */
    private function formatContactInfo(array $contact): array
    {
        return [
            'FirstName' => $contact['first_name'] ?? $contact['FirstName'] ?? '',
            'LastName' => $contact['last_name'] ?? $contact['LastName'] ?? '',
            'ContactType' => $contact['contact_type'] ?? $contact['ContactType'] ?? 'PERSON',
            'OrganizationName' => $contact['organization'] ?? $contact['OrganizationName'] ?? null,
            'AddressLine1' => $contact['address1'] ?? $contact['AddressLine1'] ?? '',
            'AddressLine2' => $contact['address2'] ?? $contact['AddressLine2'] ?? null,
            'City' => $contact['city'] ?? $contact['City'] ?? '',
            'State' => $contact['state'] ?? $contact['State'] ?? '',
            'CountryCode' => $contact['country_code'] ?? $contact['CountryCode'] ?? '',
            'ZipCode' => $contact['zip_code'] ?? $contact['ZipCode'] ?? '',
            'PhoneNumber' => $contact['phone'] ?? $contact['PhoneNumber'] ?? '',
            'Email' => $contact['email'] ?? $contact['Email'] ?? '',
        ];
    }

    /**
     * 调用 AWS API
     */
    private function callApi(string $action, array $params = []): array
    {
        if ($this->config === null || !$this->config->isActive()) {
            throw new \RuntimeException('AWS 配置未设置或未启用');
        }

        $region = $this->config->getData(AwsConfig::fields_REGION) ?: 'us-east-1';
        $accessKeyId = $this->config->getData(AwsConfig::fields_ACCESS_KEY_ID);
        $secretAccessKey = $this->config->getData(AwsConfig::fields_SECRET_ACCESS_KEY);

        $host = "route53domains.{$region}.amazonaws.com";
        $endpoint = "https://{$host}/";

        $body = json_encode($params);
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        // 创建规范请求
        $canonicalUri = '/';
        $canonicalQuerystring = '';
        $contentType = 'application/x-amz-json-1.1';
        $amzTarget = "Route53Domains_v{$this->apiVersion}.{$action}";

        $canonicalHeaders = implode("\n", [
            "content-type:{$contentType}",
            "host:{$host}",
            "x-amz-date:{$amzDate}",
            "x-amz-target:{$amzTarget}",
        ]) . "\n";

        $signedHeaders = 'content-type;host;x-amz-date;x-amz-target';
        $payloadHash = hash('sha256', $body);

        $canonicalRequest = implode("\n", [
            'POST',
            $canonicalUri,
            $canonicalQuerystring,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        // 创建待签名字符串
        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = "{$dateStamp}/{$region}/{$this->service}/aws4_request";
        $stringToSign = implode("\n", [
            $algorithm,
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        // 计算签名
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretAccessKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $this->service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        // 创建授权头
        $authorizationHeader = "{$algorithm} Credential={$accessKeyId}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        // 发送请求
        $response = $this->httpClient->post($endpoint, [
            'headers' => [
                'Content-Type' => $contentType,
                'X-Amz-Date' => $amzDate,
                'X-Amz-Target' => $amzTarget,
                'Authorization' => $authorizationHeader,
            ],
            'body' => $body,
        ]);

        $statusCode = $response->getStatusCode();
        $responseBody = $response->getBody()->getContents();
        $result = json_decode($responseBody, true);

        if ($statusCode !== 200) {
            $errorType = $result['__type'] ?? 'UnknownError';
            $errorMessage = $result['message'] ?? $result['Message'] ?? $responseBody;
            throw new \RuntimeException("[{$errorType}] {$errorMessage}", $statusCode);
        }

        return $result ?? [];
    }
}
