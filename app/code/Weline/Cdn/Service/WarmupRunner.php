<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Service;

use Weline\Cdn\Model\Domain;
use Weline\Cdn\Model\WarmupUrl;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Lock\SetupUpgradeIntent;

/**
 * 预热执行器
 *
 * @package Weline_Cdn
 */
class WarmupRunner
{
    private ObjectManager $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * @return array{processed:int,success:int,fail:int,skipped:int,yielded_for_upgrade?:bool}
     */
    public function run(
        int $limit = 50,
        ?int $domainId = null,
        ?string $providerFqcn = null,
        ?int $siteId = null,
    ): array {
        $processed = 0;
        $success = 0;
        $fail = 0;
        $skipped = 0;
        $yieldedForUpgrade = false;

        /** @var WarmupUrl $warmupUrlModel */
        $warmupUrlModel = $this->objectManager->getInstance(WarmupUrl::class);

        $query = $warmupUrlModel->reset()
            ->where(WarmupUrl::schema_fields_ENABLED, 1);
        if ($domainId !== null && $domainId > 0) {
            $query->where(WarmupUrl::schema_fields_DOMAIN_ID, $domainId);
        } elseif ($siteId !== null) {
            // site_id=0 是合法默认站，禁止用 >0 判空
            $query->where(WarmupUrl::schema_fields_SITE_ID, $siteId);
        }
        if ($providerFqcn !== null && $providerFqcn !== '') {
            $query->where(WarmupUrl::schema_fields_PROVIDER, $providerFqcn);
        }
        // SQL 层先筛未完成：禁止「按 ID ASC 取前 N 条」窗口被已完成行占满后 PHP 侧空转。
        $query->additional(
            'AND "' . WarmupUrl::schema_fields_PROCESSED_COUNT . '" < "'
            . WarmupUrl::schema_fields_TARGET_COUNT . '"'
        );
        $candidates = $query
            ->order(WarmupUrl::schema_fields_WARMUP_URL_ID, 'ASC')
            ->limit(max($limit * 5, $limit))
            ->select()
            ->fetch()
            ->getItems();

        $urls = [];
        foreach ($candidates as $candidate) {
            $processedCount = (int)$candidate->getData(WarmupUrl::schema_fields_PROCESSED_COUNT);
            $targetCount = (int)$candidate->getData(WarmupUrl::schema_fields_TARGET_COUNT);
            if ($processedCount < $targetCount) {
                $urls[] = $candidate;
                if (count($urls) >= $limit) {
                    break;
                }
            }
        }

        foreach ($urls as $warmupUrl) {
            if (SetupUpgradeIntent::shouldYield()) {
                $yieldedForUpgrade = true;
                break;
            }
            try {
                $outcome = $this->warmupUrl($warmupUrl);
                if ($outcome === 'skipped') {
                    $skipped++;
                    continue;
                }
                $processed++;
                if ($outcome === 'success') {
                    $success++;
                } else {
                    $fail++;
                }
            } catch (\Exception $e) {
                $processed++;
                $fail++;
                w_log_error('预热URL失败: ' . $warmupUrl->getData(WarmupUrl::schema_fields_URL) . ', 错误: ' . $e->getMessage());
            }
        }

        $out = [
            'processed' => $processed,
            'success' => $success,
            'fail' => $fail,
            'skipped' => $skipped,
        ];
        if ($yieldedForUpgrade) {
            $out['yielded_for_upgrade'] = true;
        }

        return $out;
    }

    /**
     * @return 'success'|'fail'|'skipped'
     */
    private function warmupUrl(WarmupUrl $warmupUrl): string
    {
        $url = (string)$warmupUrl->getData(WarmupUrl::schema_fields_URL);
        $domainId = $warmupUrl->getData(WarmupUrl::schema_fields_DOMAIN_ID);

        if ($domainId) {
            /** @var Domain $domainModel */
            $domainModel = $this->objectManager->getInstance(Domain::class)->reset()->load((int)$domainId);
            if ($domainModel->getData(Domain::schema_fields_DOMAIN_ID)) {
                $interval = (int)($domainModel->getData(Domain::schema_fields_WARMUP_INTERVAL_SECONDS) ?: 300);
                $lastWarmed = (int)$warmupUrl->getData(WarmupUrl::schema_fields_LAST_WARMED_AT);
                if ($lastWarmed > 0 && (time() - $lastWarmed) < $interval) {
                    return 'skipped';
                }
            }
        }

        $fetchUrl = $this->resolveFetchUrl($url);
        $localDevFetch = $fetchUrl !== $url;
        $ch = curl_init($fetchUrl);
        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'CF-Prewarm: t0k3n-' . md5($url . time()),
            ],
        ];
        // 本机 WLS 开发证书为自签链：仅对「已注入 listen 端口」的 fetch 关闭校验，生产 443 仍校验。
        if ($localDevFetch) {
            $curlOpts[CURLOPT_SSL_VERIFYPEER] = false;
            $curlOpts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $curlOpts);

        curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $processedCount = (int)$warmupUrl->getData(WarmupUrl::schema_fields_PROCESSED_COUNT) + 1;
        $warmupUrl->setData(WarmupUrl::schema_fields_PROCESSED_COUNT, $processedCount);
        $warmupUrl->setData(WarmupUrl::schema_fields_LAST_WARMED_AT, time());

        if ($error || $httpCode < 200 || $httpCode >= 300) {
            $failCount = (int)$warmupUrl->getData(WarmupUrl::schema_fields_FAIL_COUNT) + 1;
            $warmupUrl->setData(WarmupUrl::schema_fields_FAIL_COUNT, $failCount);
            $warmupUrl->setData(WarmupUrl::schema_fields_STATUS, WarmupUrl::STATUS_FAIL);
            $retries = (int)$warmupUrl->getData(WarmupUrl::schema_fields_RETRIES) + 1;
            $warmupUrl->setData(WarmupUrl::schema_fields_RETRIES, $retries);
            $warmupUrl->save();

            return 'fail';
        }

        $successCount = (int)$warmupUrl->getData(WarmupUrl::schema_fields_SUCCESS_COUNT) + 1;
        $warmupUrl->setData(WarmupUrl::schema_fields_SUCCESS_COUNT, $successCount);
        $warmupUrl->setData(WarmupUrl::schema_fields_STATUS, WarmupUrl::STATUS_SUCCESS);
        $warmupUrl->save();

        return 'success';
    }

    /**
     * 本机 WLS 常以非默认端口监听（如 :9555），而 Website.url 常省略端口。
     * 对「主机名 = wls/server.host 且 URL 未带端口」的绝对 URL，注入 listen port，避免 curl 打到 :443 空转失败。
     * 生产 listen=443/80 时不改写。
     */
    public function resolveFetchUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['port'])) {
            return $url;
        }

        $listenHost = '';
        $listenPort = 0;
        foreach (['wls.host', 'server.host'] as $hostKey) {
            $candidate = trim((string)(Env::get($hostKey) ?: ''));
            if ($candidate !== '') {
                $listenHost = $candidate;
                break;
            }
        }
        foreach (['wls.port', 'server.port', 'server.ssl_port', 'wls.ssl_port'] as $portKey) {
            $candidate = (int)(Env::get($portKey) ?: 0);
            if ($candidate > 0) {
                $listenPort = $candidate;
                break;
            }
        }
        if ($listenHost === '' || $listenPort <= 0) {
            return $url;
        }
        if (strcasecmp((string)$parts['host'], $listenHost) !== 0) {
            return $url;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
        $defaultPort = $scheme === 'http' ? 80 : 443;
        if ($listenPort === $defaultPort) {
            return $url;
        }

        $userInfo = '';
        if (isset($parts['user'])) {
            $userInfo = (string)$parts['user'];
            if (isset($parts['pass'])) {
                $userInfo .= ':' . (string)$parts['pass'];
            }
            $userInfo .= '@';
        }
        $path = (string)($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . '://' . $userInfo . $parts['host'] . ':' . $listenPort . $path . $query . $fragment;
    }
}
