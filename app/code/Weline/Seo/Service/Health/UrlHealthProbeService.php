<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Health;

use Weline\Seo\Model\HealthRun;
use Weline\Seo\Model\UrlHealth;
use Weline\Seo\Model\UrlHealthObservation;
use Weline\Seo\Service\SeoWebsiteDirectory;
use Weline\Seo\Service\StoreModeSeoHardGate;

/**
 * 一次站点健康探测：开跑批 → 取 404 指纹 → 选样 → 逐条探测 → 写状态/历史 → 收尾。
 *
 * 只负责“发现与记录”，不修改 sitemap；自动处理在阶段 2 的动作台账里做。
 */
final class UrlHealthProbeService
{
    public const DEFAULT_MAX_URLS = 300;
    public const DEFAULT_BUDGET_MS = 240000;
    public const DEFAULT_TIMEOUT = 5;
    public const DEFAULT_MIN_INTERVAL_MS = 120;

    public function __construct(
        private SeoWebsiteDirectory $websiteDirectory,
        private HealthProbePlanner $planner,
        private HttpProbe $httpProbe,
        private RobotsEvaluator $robotsEvaluator,
        private Soft404Detector $soft404Detector,
        private IssueClassifier $classifier,
        private UrlHealth $healthModel,
        private UrlHealthObservation $observationModel,
        private HealthRun $runModel
    ) {
    }

    /**
     * @param array{
     *   max_urls?:int, budget_ms?:int, timeout?:int, connect_timeout?:int,
     *   user_agent?:string, min_interval_ms?:int, dry_run?:bool, trigger?:string,
     *   force?:bool, allow_insecure?:bool, include_results?:bool
     * } $options
     * @return array<string, mixed>
     */
    public function probeWebsite(int $websiteId, array $options = []): array
    {
        $startedAt = microtime(true);
        $maxUrls = max(1, (int)($options['max_urls'] ?? self::DEFAULT_MAX_URLS));
        $budgetMs = max(1000, (int)($options['budget_ms'] ?? self::DEFAULT_BUDGET_MS));
        $timeout = max(1, (int)($options['timeout'] ?? self::DEFAULT_TIMEOUT));
        $connectTimeout = max(1, (int)($options['connect_timeout'] ?? min(3, $timeout)));
        $minIntervalMs = max(0, (int)($options['min_interval_ms'] ?? self::DEFAULT_MIN_INTERVAL_MS));
        $dryRun = (bool)($options['dry_run'] ?? false);
        $trigger = (string)($options['trigger'] ?? HealthRun::TRIGGER_MANUAL);
        $userAgent = (string)($options['user_agent'] ?? HttpProbe::DEFAULT_USER_AGENT);
        $allowInsecure = (bool)($options['allow_insecure'] ?? false);
        $includeResults = (bool)($options['include_results'] ?? false);

        $website = $this->websiteDirectory->getWebsiteById($websiteId);
        if ($website === null) {
            return $this->failedResult('website not found');
        }
        if (empty($options['force']) && (new StoreModeSeoHardGate())->isHardNoIndexMode()) {
            return [
                'status' => HealthRun::STATUS_SKIPPED,
                'message' => (string)__('Store mode 硬门禁：dev/test 不做站点健康探测'),
                'run_id' => 0,
                'probed' => 0,
                'ok' => 0,
                'issues' => 0,
                'by_issue' => [],
                'duration_ms' => 0,
            ];
        }

        $baseUrl = rtrim($this->websiteDirectory->effectivePublicBaseUrl($website), '/');
        if ($baseUrl === '' || !preg_match('#^https?://#i', $baseUrl)) {
            return $this->failedResult('website base url unavailable');
        }

        $run = null;
        $runId = 0;
        if (!$dryRun) {
            $run = $this->openRun($websiteId, $trigger, $budgetMs);
            $runId = (int)$run->getId();
        }
        $probeToken = $runId > 0 ? (string)$runId : (string)time();

        $stats = [
            'run_id' => $runId,
            'status' => HealthRun::STATUS_COMPLETED,
            'probed' => 0,
            'ok' => 0,
            'issues' => 0,
            'by_issue' => [],
            'total_active' => 0,
            'truncated' => false,
            'results' => [],
        ];

        try {
            $fingerprint = $this->fingerprint404($baseUrl, $probeToken, $timeout, $connectTimeout, $userAgent, $allowInsecure);
            $plan = $this->planner->plan($websiteId, $maxUrls);
            $stats['total_active'] = (int)($plan['total_active'] ?? 0);
            $stats['truncated'] = (bool)($plan['truncated'] ?? false);

            foreach ($plan['urls'] as $entry) {
            if (((microtime(true) - $startedAt) * 1000) >= $budgetMs) {
                $stats['status'] = HealthRun::STATUS_PARTIAL;
                break;
            }

            $url = (string)($entry['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $probe = $this->httpProbe->probe($url, [
                'timeout' => $timeout,
                'connect_timeout' => $connectTimeout,
                'user_agent' => $userAgent,
                'cache_bust' => $probeToken,
                'allow_insecure' => $allowInsecure,
            ]);
            $robots = $this->evaluateRobots($url, $timeout, $userAgent, $allowInsecure);
            $soft404 = $this->soft404Detector->match($probe, $fingerprint);
            $classified = $this->classifier->classify($probe, $robots, $soft404);
            $issueCode = (string)$classified['issue_code'];

            $stats['probed']++;
            if (HealthIssueCatalog::isHealthy($issueCode)) {
                $stats['ok']++;
            } else {
                $stats['issues']++;
                $stats['by_issue'][$issueCode] = (int)($stats['by_issue'][$issueCode] ?? 0) + 1;
            }

            if (!$dryRun) {
                $this->persist($websiteId, $entry, $probe, $robots, $soft404, $classified, $runId);
            }
            if ($includeResults && count($stats['results']) < 50) {
                $stats['results'][] = [
                    'url' => $url,
                    'status' => (int)$probe['status'],
                    'issue_code' => $issueCode,
                    'final_url' => (string)$probe['final_url'],
                    'duration_ms' => (int)$probe['duration_ms'],
                ];
            }

            if ($minIntervalMs > 0) {
                usleep($minIntervalMs * 1000);
            }
        }

        } catch (\Throwable $e) {
            $stats['status'] = HealthRun::STATUS_FAILED;
            $stats['error'] = $e->getMessage();
            $stats['duration_ms'] = (int)round((microtime(true) - $startedAt) * 1000);
            if ($run !== null) {
                $this->closeRun($run, $stats);
            }
            throw $e;
        }

        $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
        $stats['duration_ms'] = $durationMs;
        ksort($stats['by_issue']);

        if ($run !== null) {
            $this->closeRun($run, $stats);
        }

        return $stats;
    }

    /**
     * 单 URL 探测（CLI/排障），不写库。
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function probeUrl(string $url, array $options = []): array
    {
        $timeout = max(1, (int)($options['timeout'] ?? self::DEFAULT_TIMEOUT));
        $connectTimeout = max(1, (int)($options['connect_timeout'] ?? min(3, $timeout)));
        $userAgent = (string)($options['user_agent'] ?? HttpProbe::DEFAULT_USER_AGENT);
        $allowInsecure = (bool)($options['allow_insecure'] ?? false);

        $probe = $this->httpProbe->probe($url, [
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
            'user_agent' => $userAgent,
            'cache_bust' => (string)($options['cache_bust'] ?? time()),
            'allow_insecure' => $allowInsecure,
        ]);
        $robots = $this->evaluateRobots($url, $timeout, $userAgent, $allowInsecure);
        $soft404 = $this->soft404Detector->match($probe, null);
        $classified = $this->classifier->classify($probe, $robots, $soft404);

        return [
            'url' => $url,
            'probe' => $probe,
            'robots' => $robots,
            'soft404' => $soft404,
            'classified' => $classified,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $probe
     * @param array<string, mixed> $robots
     * @param array<string, mixed> $soft404
     * @param array<string, mixed> $classified
     */
    private function persist(
        int $websiteId,
        array $entry,
        array $probe,
        array $robots,
        array $soft404,
        array $classified,
        int $runId
    ): void {
        $hash = (string)($entry['url_hash'] ?? UrlHealthKey::hash((string)($entry['url'] ?? '')));
        $now = date('Y-m-d H:i:s');
        $issueCode = (string)($classified['issue_code'] ?? HealthIssueCatalog::UNKNOWN);
        $healthy = HealthIssueCatalog::isHealthy($issueCode);

        $record = $this->findHealth($websiteId, $hash);
        $isNew = $record === null;
        if ($record === null) {
            $record = clone $this->healthModel;
            $record->reset();
        }

        $previousState = (string)($record->getData(UrlHealth::schema_fields_STATE) ?? '');
        $previousFailures = (int)($record->getData(UrlHealth::schema_fields_CONSECUTIVE_FAILURES) ?? 0);
        $previousOk = (int)($record->getData(UrlHealth::schema_fields_CONSECUTIVE_OK) ?? 0);
        $lastOkAt = $record->getData(UrlHealth::schema_fields_LAST_OK_AT);

        if ($healthy) {
            $failures = 0;
            $okCount = $previousOk + 1;
            $firstSeenAt = $record->getData(UrlHealth::schema_fields_FIRST_SEEN_AT) ?: $now;
            // 已自动移除的 URL 恢复正常时要保留 auto_removed，
            // 决策器才能按恢复阈值生成 restore；恢复后 applier 改成 auto_restored。
            $state = match ($previousState) {
                UrlHealth::STATE_AUTO_REMOVED => UrlHealth::STATE_AUTO_REMOVED,
                UrlHealth::STATE_OPEN, UrlHealth::STATE_FIXED => UrlHealth::STATE_FIXED,
                default => UrlHealth::STATE_OK,
            };
        } else {
            $failures = $previousFailures + 1;
            $okCount = 0;
            $firstSeenAt = $record->getData(UrlHealth::schema_fields_FIRST_SEEN_AT) ?: $now;
            $state = $previousState === UrlHealth::STATE_IGNORED
                ? UrlHealth::STATE_IGNORED
                : ($previousState === UrlHealth::STATE_AUTO_REMOVED
                    ? UrlHealth::STATE_AUTO_REMOVED
                    : UrlHealth::STATE_OPEN);
        }

        $redirectChain = is_array($probe['redirects'] ?? null) ? $probe['redirects'] : [];

        $data = [
            UrlHealth::schema_fields_WEBSITE_ID => $websiteId,
            UrlHealth::schema_fields_URL_HASH => $hash,
            UrlHealth::schema_fields_URL => (string)($entry['url'] ?? ''),
            UrlHealth::schema_fields_MODULE => (string)($entry['module'] ?? ''),
            UrlHealth::schema_fields_SCOPE => (string)($entry['scope'] ?? ''),
            UrlHealth::schema_fields_URL_KEY => (string)($entry['url_key'] ?? ''),
            UrlHealth::schema_fields_LOCALE => (string)($entry['locale'] ?? ''),
            UrlHealth::schema_fields_SITEMAP_URL_ID => (int)($entry['sitemap_url_id'] ?? 0),
            UrlHealth::schema_fields_ISSUE_CODE => $issueCode,
            UrlHealth::schema_fields_HTTP_STATUS => (int)($probe['status'] ?? 0),
            UrlHealth::schema_fields_REDIRECT_CHAIN => json_encode($redirectChain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            UrlHealth::schema_fields_FINAL_URL => (string)($probe['final_url'] ?? ''),
            UrlHealth::schema_fields_ROBOTS_STATE => (string)($robots['state'] ?? 'unknown'),
            UrlHealth::schema_fields_SOFT_404 => !empty($soft404['matched']) ? 1 : 0,
            UrlHealth::schema_fields_RESPONSE_MS => (int)($probe['duration_ms'] ?? 0),
            UrlHealth::schema_fields_FIRST_SEEN_AT => $firstSeenAt,
            UrlHealth::schema_fields_LAST_CHECKED_AT => $now,
            UrlHealth::schema_fields_LAST_OK_AT => $healthy ? $now : $lastOkAt,
            UrlHealth::schema_fields_CONSECUTIVE_FAILURES => $failures,
            UrlHealth::schema_fields_CONSECUTIVE_OK => $okCount,
            UrlHealth::schema_fields_STATE => $state,
            UrlHealth::schema_fields_EVIDENCE => json_encode($classified['evidence'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        if ($isNew) {
            $data[UrlHealth::schema_fields_CREATED_AT] = $now;
        }
        $record->setData($data)->save();

        if ((int)$record->getId() > 0) {
            $observation = clone $this->observationModel;
            $observation->reset()->setData([
                UrlHealthObservation::schema_fields_HEALTH_ID => (int)$record->getId(),
                UrlHealthObservation::schema_fields_RUN_ID => $runId,
                UrlHealthObservation::schema_fields_WEBSITE_ID => $websiteId,
                UrlHealthObservation::schema_fields_ISSUE_CODE => $issueCode,
                UrlHealthObservation::schema_fields_HTTP_STATUS => (int)($probe['status'] ?? 0),
                UrlHealthObservation::schema_fields_RESPONSE_MS => (int)($probe['duration_ms'] ?? 0),
                UrlHealthObservation::schema_fields_REDIRECT_TO => (string)($probe['final_url'] ?? ''),
                UrlHealthObservation::schema_fields_ROBOTS_STATE => (string)($robots['state'] ?? 'unknown'),
                UrlHealthObservation::schema_fields_SOFT_404 => !empty($soft404['matched']) ? 1 : 0,
                UrlHealthObservation::schema_fields_EVIDENCE => json_encode($classified['evidence'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                UrlHealthObservation::schema_fields_CHECKED_AT => $now,
                UrlHealthObservation::schema_fields_CREATED_AT => $now,
            ])->save();
        }
    }

    private function findHealth(int $websiteId, string $hash): ?UrlHealth
    {
        $record = (clone $this->healthModel)->reset()
            ->where(UrlHealth::schema_fields_WEBSITE_ID, $websiteId)
            ->where(UrlHealth::schema_fields_URL_HASH, $hash)
            ->find()
            ->fetch();

        if ($record instanceof UrlHealth && (int)$record->getId() > 0) {
            return $record;
        }

        return null;
    }

    private function openRun(int $websiteId, string $trigger, int $budgetMs): HealthRun
    {
        $run = clone $this->runModel;
        $run->reset()->setData([
            HealthRun::schema_fields_WEBSITE_ID => $websiteId,
            HealthRun::schema_fields_TRIGGER_TYPE => $trigger,
            HealthRun::schema_fields_STATUS => HealthRun::STATUS_RUNNING,
            HealthRun::schema_fields_BUDGET_MS => $budgetMs,
            HealthRun::schema_fields_STARTED_AT => date('Y-m-d H:i:s'),
            HealthRun::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
        ])->save();

        return $run;
    }

    /** @param array<string, mixed> $stats */
    private function closeRun(HealthRun $run, array $stats): void
    {
        $run->setData([
            HealthRun::schema_fields_STATUS => (string)($stats['status'] ?? HealthRun::STATUS_COMPLETED),
            HealthRun::schema_fields_PROBED => (int)($stats['probed'] ?? 0),
            HealthRun::schema_fields_OK_COUNT => (int)($stats['ok'] ?? 0),
            HealthRun::schema_fields_ISSUE_COUNT => (int)($stats['issues'] ?? 0),
            HealthRun::schema_fields_BY_ISSUE => json_encode((array)($stats['by_issue'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            HealthRun::schema_fields_DURATION_MS => (int)($stats['duration_ms'] ?? 0),
            HealthRun::schema_fields_ERROR => (string)($stats['error'] ?? ''),
            HealthRun::schema_fields_FINISHED_AT => date('Y-m-d H:i:s'),
        ])->save();
    }

    /**
     * @return array{state:string, rule:string, line:int, directive?:string, reason:string}
     */
    private function evaluateRobots(string $url, int $timeout, string $userAgent, bool $allowInsecure): array
    {
        $origin = UrlHealthKey::origin($url);
        if ($origin === '') {
            return ['state' => 'unknown', 'rule' => '', 'line' => 0, 'reason' => 'invalid url'];
        }

        $robots = $this->robotsEvaluator->fetch($origin, $timeout, $allowInsecure);
        if (!$robots['ok']) {
            return ['state' => 'unknown', 'rule' => '', 'line' => 0, 'reason' => $robots['error']];
        }

        return $this->robotsEvaluator->evaluate($robots['content'], $url, $userAgent);
    }

    /**
     * @return array{status:int, title:string, text_length:int, marker_hits:list<string>}|null
     */
    private function fingerprint404(
        string $baseUrl,
        string $token,
        int $timeout,
        int $connectTimeout,
        string $userAgent,
        bool $allowInsecure
    ): ?array {
        $probe = $this->httpProbe->probe($baseUrl . '/__w_seo_probe_404_' . $token, [
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
            'user_agent' => $userAgent,
            'cache_bust' => $token,
            'allow_insecure' => $allowInsecure,
        ]);
        if ((int)($probe['status'] ?? 0) !== 404) {
            return null;
        }

        $fingerprint = $this->soft404Detector->buildFingerprint($probe);

        return $fingerprint['text_length'] > 0 ? $fingerprint : null;
    }

    /** @return array<string, mixed> */
    private function failedResult(string $message): array
    {
        return [
            'status' => HealthRun::STATUS_FAILED,
            'message' => $message,
            'run_id' => 0,
            'probed' => 0,
            'ok' => 0,
            'issues' => 0,
            'by_issue' => [],
            'duration_ms' => 0,
        ];
    }
}
