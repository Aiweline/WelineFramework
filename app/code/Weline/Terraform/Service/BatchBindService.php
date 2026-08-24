<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Terraform\Service;

use Weline\Cdn\Model\Account as CdnAccount;
use Weline\Cdn\Model\Domain as CdnDomain;
use Weline\Cdn\Service\ProviderManager;
use Weline\Framework\System\Process\Processer;
use Weline\Terraform\Model\Batch;
use Weline\Terraform\Model\BatchItem;
use Weline\Websites\Model\Website;

/**
 * Terraform 批量绑定服务
 *
 * @package Weline_Terraform
 */
class BatchBindService
{
    private Batch $batchModel;
    private BatchItem $batchItemModel;
    private CdnDomain $cdnDomainModel;
    private CdnAccount $cdnAccountModel;
    private ProviderManager $providerManager;
    private Website $websiteModel;

    public function __construct(
        Batch $batchModel,
        BatchItem $batchItemModel,
        CdnDomain $cdnDomainModel,
        CdnAccount $cdnAccountModel,
        ProviderManager $providerManager,
        Website $websiteModel
    ) {
        $this->batchModel = $batchModel;
        $this->batchItemModel = $batchItemModel;
        $this->cdnDomainModel = $cdnDomainModel;
        $this->cdnAccountModel = $cdnAccountModel;
        $this->providerManager = $providerManager;
        $this->websiteModel = $websiteModel;
    }

    /**
     * 执行批量绑定
     *
     * @param array $payload
     * @return array
     */
    public function batchBind(array $payload): array
    {
        $provider = trim((string)($payload['provider'] ?? ''));
        $accountId = (int)($payload['account_id'] ?? 0);
        $manualSiteId = (int)($payload['manual_site_id'] ?? 0);
        $domainsText = (string)($payload['domains_text'] ?? '');
        $websiteIds = $this->normalizeIdList($payload['website_ids'] ?? []);
        $dnsRecordType = strtoupper(trim((string)($payload['dns_record_type'] ?? '')));
        $dnsRecordValue = trim((string)($payload['dns_record_value'] ?? ''));
        $override = (int)($payload['override'] ?? 0);
        $allowedRecordTypes = ['A', 'AAAA', 'CNAME', 'TXT'];

        if ($dnsRecordType !== '' && !in_array($dnsRecordType, $allowedRecordTypes, true)) {
            return $this->resultError(__('DNS记录类型不合法'));
        }
        if ($dnsRecordType === '' && $dnsRecordValue !== '') {
            return $this->resultError(__('DNS记录类型不能为空'));
        }
        if ($dnsRecordType !== '' && $dnsRecordValue === '') {
            return $this->resultError(__('DNS记录值不能为空'));
        }

        if ($provider === '') {
            return $this->resultError(__('请选择供应商'));
        }
        if ($accountId <= 0) {
            return $this->resultError(__('请选择账户'));
        }

        $providers = $this->providerManager->getProviders();
        $providerCodes = array_column($providers, 'code');
        if (!in_array($provider, $providerCodes, true)) {
            return $this->resultError(__('供应商不存在：%{provider}', ['provider' => $provider]));
        }

        $account = $this->cdnAccountModel->reset()->load($accountId);
        if (!$account->getId()) {
            return $this->resultError(__('账户不存在'));
        }
        if ($account->getData(CdnAccount::schema_fields_ADAPTER) !== $provider) {
            return $this->resultError(__('账户与供应商不匹配'));
        }

        $entries = [];

        $manualDomains = $this->parseDomainsText($domainsText);
        if (!empty($manualDomains)) {
            if ($manualSiteId <= 0) {
                return $this->resultError(__('手动输入域名时必须选择网站'));
            }
            foreach ($manualDomains as $domain) {
                $entries[] = [
                    'domain' => $domain,
                    'site_id' => $manualSiteId,
                    'source' => 'manual',
                ];
            }
        }

        if (!empty($websiteIds)) {
            $websiteEntries = $this->collectWebsiteDomains($websiteIds);
            $entries = array_merge($entries, $websiteEntries);
        }

        if (empty($entries)) {
            return $this->resultError(__('请输入域名或选择网站'));
        }

        $entries = $this->deduplicateEntries($entries);
        $domains = array_values(array_unique(array_column($entries, 'domain')));

        $conflicts = $this->collectConflicts($entries, $domains);
        if (!empty($conflicts) && $override !== 1) {
            return [
                'success' => false,
                'need_confirm' => true,
                'message' => __('本批次包含已绑定域名或已绑定网站，是否确认替换绑定？'),
                'conflicts' => $conflicts,
            ];
        }

        $dnsRecord = [
            'type' => $dnsRecordType,
            'value' => $dnsRecordValue,
            'name' => '@',
        ];

        $batch = $this->batchModel->reset();
        $batch->setData([
            Batch::schema_fields_PROVIDER => $provider,
            Batch::schema_fields_ACCOUNT_ID => $accountId,
            Batch::schema_fields_SITE_ID => $this->inferBatchSiteId($entries),
            Batch::schema_fields_DOMAINS_RAW => $domainsText,
            Batch::schema_fields_DNS_RECORD_TYPE => $dnsRecordType,
            Batch::schema_fields_DNS_RECORD_VALUE => $dnsRecordValue,
            Batch::schema_fields_OVERRIDE => $override,
            Batch::schema_fields_STATUS => Batch::STATUS_PENDING,
        ])->save();

        $batchId = (int)($batch->getData(Batch::schema_fields_BATCH_ID) ?? 0);
        if ($batchId <= 0) {
            return $this->resultError(__('批次创建失败'));
        }

        if ($provider !== 'cloudflare') {
            $this->markBatchFailed($batchId, __('暂不支持该供应商'));
            return $this->resultError(__('暂不支持该供应商'));
        }

        $credentials = $account->getCredentialsArray();
        $apiToken = trim((string)($credentials['api_token'] ?? ''));
        $accountRef = trim((string)($credentials['account_id'] ?? ''));
        if ($apiToken === '' || $accountRef === '') {
            $this->markBatchFailed($batchId, __('Cloudflare 账户缺少 API Token 或 Account ID'));
            return $this->resultError(__('Cloudflare 账户缺少 API Token 或 Account ID'));
        }

        $workDir = $this->prepareTerraformWorkspace($batchId, $domains, $apiToken, $accountRef, $dnsRecord);
        $tfResult = $this->runTerraform($workDir);
        if (!$tfResult['success']) {
            $this->markBatchFailed($batchId, $tfResult['message']);
        $this->createBatchItems($batchId, $entries, $provider, $accountId, $dnsRecord, BatchItem::STATUS_FAILED, $tfResult['message'], []);
            return $this->resultError(__('Terraform 执行失败：%{message}', ['message' => $tfResult['message']]));
        }

        $zoneMap = $tfResult['zones'] ?? [];
        $this->applyDomainBindings($entries, $provider, $accountId, $zoneMap, $override);

        $summary = [
            'total' => count($entries),
            'success' => count($entries),
            'skipped' => 0,
            'failed' => 0,
        ];

        $this->createBatchItems($batchId, $entries, $provider, $accountId, $dnsRecord, BatchItem::STATUS_SUCCESS, '', $zoneMap);
        $this->markBatchSuccess($batchId, $summary, $tfResult);

        return [
            'success' => true,
            'message' => __('批量绑定已完成'),
            'summary' => $summary,
            'zones' => $zoneMap,
            'name_servers' => $tfResult['name_servers'] ?? [],
        ];
    }

    /**
     * 解析域名输入
     *
     * @param string $text
     * @return array<int, string>
     */
    public function parseDomainsText(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        if (!$lines) {
            return [];
        }
        $domains = [];
        foreach ($lines as $line) {
            $domain = $this->normalizeDomain((string)$line);
            if ($domain === '') {
                continue;
            }
            $domains[] = $domain;
        }
        return array_values(array_unique($domains));
    }

    /**
     * 规范化域名
     */
    private function normalizeDomain(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $raw = preg_replace('/\s+/', '', $raw) ?? $raw;
        if (str_contains($raw, '://')) {
            $host = parse_url($raw, PHP_URL_HOST);
        } else {
            $host = $raw;
        }
        if (!is_string($host) || $host === '') {
            $host = $raw;
        }
        $host = explode('/', $host)[0];
        $host = explode(':', $host)[0];
        $host = strtolower(trim($host));
        if (str_starts_with($host, '*.')) {
            $host = substr($host, 2);
        }
        if ($host === '') {
            return '';
        }
        $valid = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
        return $valid ? $host : '';
    }

    /**
     * 收集网站域名
     *
     * @param array<int> $websiteIds
     * @return array<int, array{domain:string,site_id:int,source:string}>
     */
    private function collectWebsiteDomains(array $websiteIds): array
    {
        if (empty($websiteIds)) {
            return [];
        }
        $websites = $this->websiteModel->reset()
            ->where(Website::schema_fields_ID, $websiteIds, 'IN')
            ->select()
            ->fetchArray();

        $entries = [];
        foreach ($websites as $website) {
            $siteId = (int)($website[Website::schema_fields_ID] ?? 0);
            $url = (string)($website[Website::schema_fields_URL] ?? '');
            $domain = $this->normalizeDomain($url);
            if ($siteId > 0 && $domain !== '') {
                $entries[] = [
                    'domain' => $domain,
                    'site_id' => $siteId,
                    'source' => 'website',
                ];
            }
        }
        return $entries;
    }

    /**
     * 去重并合并条目
     *
     * @param array<int, array{domain:string,site_id:int,source:string}> $entries
     * @return array<int, array{domain:string,site_id:int,source:string}>
     */
    private function deduplicateEntries(array $entries): array
    {
        $map = [];
        foreach ($entries as $entry) {
            $domain = $entry['domain'] ?? '';
            if ($domain === '') {
                continue;
            }
            if (!isset($map[$domain])) {
                $map[$domain] = $entry;
            }
        }
        return array_values($map);
    }

    /**
     * 收集冲突
     */
    private function collectConflicts(array $entries, array $domains): array
    {
        $conflicts = [];
        $existingDomains = $this->cdnDomainModel->reset()
            ->where(CdnDomain::schema_fields_DOMAIN_NAME, $domains, 'IN')
            ->select()
            ->fetchArray();
        $existingMap = [];
        foreach ($existingDomains as $row) {
            $existingMap[(string)$row[CdnDomain::schema_fields_DOMAIN_NAME]] = $row;
        }

        $siteIds = array_values(array_unique(array_filter(array_column($entries, 'site_id'))));
        $siteExisting = [];
        if (!empty($siteIds)) {
            $siteDomains = $this->cdnDomainModel->reset()
                ->where(CdnDomain::schema_fields_SITE_ID, $siteIds, 'IN')
                ->select()
                ->fetchArray();
            foreach ($siteDomains as $row) {
                $siteId = (int)$row[CdnDomain::schema_fields_SITE_ID];
                $siteExisting[$siteId][] = $row;
            }
        }

        foreach ($entries as $entry) {
            $domain = $entry['domain'];
            $siteId = (int)$entry['site_id'];
            if (isset($existingMap[$domain])) {
                $conflicts[] = [
                    'type' => 'domain_exists',
                    'domain' => $domain,
                    'site_id' => $siteId,
                ];
            }
            if ($siteId > 0 && isset($siteExisting[$siteId])) {
                foreach ($siteExisting[$siteId] as $row) {
                    if ((string)$row[CdnDomain::schema_fields_DOMAIN_NAME] !== $domain) {
                        $conflicts[] = [
                            'type' => 'site_bound',
                            'domain' => (string)$row[CdnDomain::schema_fields_DOMAIN_NAME],
                            'site_id' => $siteId,
                        ];
                    }
                }
            }
        }

        return $conflicts;
    }

    /**
     * 生成 Terraform 工作目录
     */
    private function prepareTerraformWorkspace(int $batchId, array $domains, string $apiToken, string $accountId, array $dnsRecord): string
    {
        $workDir = BP . 'var' . DIRECTORY_SEPARATOR . 'terraform' . DIRECTORY_SEPARATOR . 'batch_' . $batchId;
        if (!is_dir($workDir)) {
            mkdir($workDir, 0755, true);
        }

        $mainTf = <<<TF
terraform {
  required_providers {
    cloudflare = {
      source  = "cloudflare/cloudflare"
      version = "~> 4.0"
    }
  }
}

provider "cloudflare" {
  api_token = var.api_token
}

variable "api_token" {
  type = string
}

variable "account_id" {
  type = string
}

variable "domains" {
  type = list(string)
}

variable "record_type" {
  type    = string
  default = ""
}

variable "record_value" {
  type    = string
  default = ""
}

variable "record_name" {
  type    = string
  default = "@"
}

locals {
  create_record = length(var.record_value) > 0 && length(var.record_type) > 0
}

resource "cloudflare_zone" "zones" {
  for_each = toset(var.domains)
  name     = each.value
  account = {
    id = var.account_id
  }
  type = "full"
}

resource "cloudflare_record" "records" {
  for_each = local.create_record ? toset(var.domains) : toset([])
  zone_id  = cloudflare_zone.zones[each.value].id
  name     = var.record_name
  type     = var.record_type
  value    = var.record_value
  ttl      = 1
  proxied  = true
}

output "zones" {
  value = { for k, v in cloudflare_zone.zones : k => v.id }
}

output "name_servers" {
  value = { for k, v in cloudflare_zone.zones : k => v.name_servers }
}
TF;

        $vars = [
            'api_token' => $apiToken,
            'account_id' => $accountId,
            'domains' => array_values($domains),
            'record_type' => $dnsRecord['type'] ?? '',
            'record_value' => $dnsRecord['value'] ?? '',
            'record_name' => $dnsRecord['name'] ?? '@',
        ];

        file_put_contents($workDir . DIRECTORY_SEPARATOR . 'main.tf', $mainTf);
        file_put_contents($workDir . DIRECTORY_SEPARATOR . 'terraform.tfvars.json', json_encode($vars, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $workDir;
    }

    /**
     * 执行 Terraform
     */
    private function runTerraform(string $workDir): array
    {
        $version = $this->execInDir($workDir, 'terraform -version');
        if (!$version['success']) {
            return $this->resultFailedExec($version, __('Terraform 未安装或不可用'));
        }

        $init = $this->execInDir($workDir, 'terraform init -input=false -no-color');
        if (!$init['success']) {
            return $this->resultFailedExec($init, __('初始化失败'));
        }

        $apply = $this->execInDir($workDir, 'terraform apply -auto-approve -input=false -no-color');
        if (!$apply['success']) {
            return $this->resultFailedExec($apply, __('应用失败'));
        }

        $output = $this->execInDir($workDir, 'terraform output -json');
        if (!$output['success']) {
            return $this->resultFailedExec($output, __('输出失败'));
        }

        $outputJson = json_decode($output['output'] ?? '', true);
        if (!is_array($outputJson)) {
            return [
                'success' => false,
                'message' => __('解析 Terraform 输出失败'),
            ];
        }

        $zones = $outputJson['zones']['value'] ?? [];
        $nameServers = $outputJson['name_servers']['value'] ?? [];

        return [
            'success' => true,
            'zones' => is_array($zones) ? $zones : [],
            'name_servers' => is_array($nameServers) ? $nameServers : [],
        ];
    }

    /**
     * 在目录中执行命令
     */
    private function execInDir(string $workDir, string $command): array
    {
        $output = [];
        $returnCode = 0;
        $cmd = $this->buildShellCommand($workDir, $command);
        $success = Processer::execute($cmd, $output, $returnCode);
        return [
            'success' => $success,
            'command' => $cmd,
            'output' => implode("\n", $output),
            'code' => $returnCode,
        ];
    }

    /**
     * 构建命令
     */
    private function buildShellCommand(string $workDir, string $command): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $dir = str_replace('/', '\\', $workDir);
            return 'cd /d "' . $dir . '" && ' . $command;
        }
        return 'cd ' . escapeshellarg($workDir) . ' && ' . $command;
    }

    /**
     * 应用域名绑定
     */
    private function applyDomainBindings(array $entries, string $provider, int $accountId, array $zoneMap, int $override): void
    {
        $keepBySite = [];
        foreach ($entries as $entry) {
            $keepBySite[(int)$entry['site_id']][] = $entry['domain'];
        }

        if ($override === 1) {
            foreach ($keepBySite as $siteId => $keepDomains) {
                if ($siteId <= 0) {
                    continue;
                }
                $this->cdnDomainModel->reset()
                    ->where(CdnDomain::schema_fields_SITE_ID, $siteId)
                    ->where(CdnDomain::schema_fields_DOMAIN_NAME, $keepDomains, 'NOT IN')
                    ->update([CdnDomain::schema_fields_ENABLED => 0])
                    ->fetch();
            }
        }

        foreach ($entries as $entry) {
            $domain = $entry['domain'];
            $siteId = (int)$entry['site_id'];
            $zoneId = (string)($zoneMap[$domain] ?? '');

            $existing = $this->cdnDomainModel->reset()
                ->where(CdnDomain::schema_fields_DOMAIN_NAME, $domain)
                ->find()
                ->fetch();

            $data = [
                CdnDomain::schema_fields_SITE_ID => $siteId,
                CdnDomain::schema_fields_ADAPTER => $provider,
                CdnDomain::schema_fields_ZONE_ID => $zoneId,
                CdnDomain::schema_fields_DOMAIN_NAME => $domain,
                CdnDomain::schema_fields_ACCOUNT_ID => $accountId,
                CdnDomain::schema_fields_INHERIT_DEFAULT => 0,
                CdnDomain::schema_fields_ENABLED => 1,
            ];

            if ($existing->getId()) {
                $this->cdnDomainModel->reset()
                    ->where(CdnDomain::schema_fields_DOMAIN_ID, (int)$existing->getId())
                    ->update($data)
                    ->fetch();
            } else {
                $this->cdnDomainModel->reset()
                    ->setData($data)
                    ->save();
            }
        }
    }

    /**
     * 创建批次项
     */
    private function createBatchItems(int $batchId, array $entries, string $provider, int $accountId, array $dnsRecord, string $status, string $message, array $zoneMap): void
    {
        foreach ($entries as $entry) {
            $domain = $entry['domain'];
            $zoneId = (string)($zoneMap[$domain] ?? '');
            $this->batchItemModel->reset()
                ->setData([
                    BatchItem::schema_fields_BATCH_ID => $batchId,
                    BatchItem::schema_fields_DOMAIN_NAME => $domain,
                    BatchItem::schema_fields_SITE_ID => (int)$entry['site_id'],
                    BatchItem::schema_fields_PROVIDER => $provider,
                    BatchItem::schema_fields_ACCOUNT_ID => $accountId,
                    BatchItem::schema_fields_ZONE_ID => $zoneId,
                    BatchItem::schema_fields_STATUS => $status,
                    BatchItem::schema_fields_MESSAGE => $message,
                    BatchItem::schema_fields_DNS_RECORD => json_encode($dnsRecord, JSON_UNESCAPED_UNICODE),
                ])
                ->save();
        }
    }

    private function markBatchFailed(int $batchId, string $message): void
    {
        $this->batchModel->reset()
            ->where(Batch::schema_fields_BATCH_ID, $batchId)
            ->update([
                Batch::schema_fields_STATUS => Batch::STATUS_FAILED,
                Batch::schema_fields_RESULT_SUMMARY => json_encode(['message' => $message], JSON_UNESCAPED_UNICODE),
            ])
            ->fetch();
    }

    private function markBatchSuccess(int $batchId, array $summary, array $tfResult): void
    {
        $this->batchModel->reset()
            ->where(Batch::schema_fields_BATCH_ID, $batchId)
            ->update([
                Batch::schema_fields_STATUS => Batch::STATUS_SUCCESS,
                Batch::schema_fields_RESULT_SUMMARY => json_encode([
                    'summary' => $summary,
                    'zones' => $tfResult['zones'] ?? [],
                    'name_servers' => $tfResult['name_servers'] ?? [],
                ], JSON_UNESCAPED_UNICODE),
            ])
            ->fetch();
    }

    private function resultError(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
        ];
    }

    private function resultFailedExec(array $execResult, string $prefix): array
    {
        $msg = $execResult['output'] ?? '';
        return [
            'success' => false,
            'message' => __('%{prefix}：%{detail}', ['prefix' => $prefix, 'detail' => $msg]),
        ];
    }

    private function normalizeIdList($ids): array
    {
        if (!is_array($ids)) {
            return [];
        }
        $list = [];
        foreach ($ids as $id) {
            $intId = (int)$id;
            if ($intId > 0) {
                $list[] = $intId;
            }
        }
        return array_values(array_unique($list));
    }

    private function inferBatchSiteId(array $entries): int
    {
        $siteIds = array_values(array_unique(array_filter(array_column($entries, 'site_id'))));
        return count($siteIds) === 1 ? (int)$siteIds[0] : 0;
    }
}
