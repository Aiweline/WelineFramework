<?php

declare(strict_types=1);

namespace Weline\Seo\Console\Health;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Service\Health\HealthIssueCatalog;
use Weline\Seo\Service\Health\HealthPolicyService;
use Weline\Seo\Service\Health\IssueClassifier;
use Weline\Seo\Service\Health\Soft404Detector;
use Weline\Seo\Service\Health\UrlHealthProbeService;
use Weline\Seo\Service\SeoWebsiteDirectory;

/**
 * php bin/w health:probe --website=CODE|ID [--max=300] [--dry-run]
 * php bin/w health:probe --url=https://example.com/page
 */
final class Probe extends CommandAbstract implements CommandInterface
{
    public function tip(): string
    {
        return (string)__('Probe sitemap URLs for one website and record 404/4xx/5xx/robots/soft-404 health');
    }

    public function help(): array|string
    {
        return [
            'php bin/w health:probe --website=CODE|ID' => (string)__('Probe active sitemap URLs for one website (default 300 per run)'),
            '--max=300' => (string)__('Max URLs to probe in this run (1-2000)'),
            '--dry-run' => (string)__('Probe without writing url_health / observations'),
            '--timeout=5' => (string)__('Per-request timeout seconds'),
            '--force' => (string)__('Ignore store-mode hard gate'),
            '--policy' => (string)__('Print the effective policy matrix for the website and exit'),
            '--tables' => (string)__('Check that the five health tables exist and exit'),
            'php bin/w health:probe --url=https://example.com/x' => (string)__('Probe a single URL and print the classification (no writes)'),
        ];
    }

    public function execute(array $args = [], array $data = [])
    {
        $websiteArg = $this->option($args, 'website');
        $urlArg = $this->option($args, 'url');
        $max = max(1, min(2000, (int)($this->option($args, 'max') ?: UrlHealthProbeService::DEFAULT_MAX_URLS)));
        $timeout = max(1, min(30, (int)($this->option($args, 'timeout') ?: 5)));
        $dryRun = $this->flag($args, 'dry-run');
        $force = $this->flag($args, 'force');

        /** @var UrlHealthProbeService $service */
        $service = ObjectManager::getInstance(UrlHealthProbeService::class);

        if ($urlArg !== '') {
            $result = $service->probeUrl($urlArg, ['timeout' => $timeout]);
            $probe = (array)($result['probe'] ?? []);
            $robots = (array)($result['robots'] ?? []);
            $soft404 = (array)($result['soft404'] ?? []);
            $classified = (array)($result['classified'] ?? []);
            $issueCode = (string)($classified['issue_code'] ?? HealthIssueCatalog::UNKNOWN);

            $this->printer->success((string)__(
                'URL: %{1} | status=%{2} | issue=%{3} (%{4}) | robots=%{5} | soft404=%{6} | final=%{7}',
                [
                    $urlArg,
                    (string)(int)($probe['status'] ?? 0),
                    $issueCode,
                    HealthIssueCatalog::label($issueCode),
                    (string)($robots['state'] ?? 'unknown'),
                    !empty($soft404['matched']) ? 'yes' : 'no',
                    (string)($probe['final_url'] ?? ''),
                ]
            ));
            if (($probe['error'] ?? '') !== '') {
                $this->printer->warning((string)__('Error: %{1}', [(string)$probe['error']]));
            }
            $this->printEvidence((array)($classified['evidence'] ?? []));

            return 0;
        }

        if ($this->flag($args, 'tables')) {
            $models = [
                \Weline\Seo\Model\UrlHealth::class,
                \Weline\Seo\Model\UrlHealthObservation::class,
                \Weline\Seo\Model\HealthPolicy::class,
                \Weline\Seo\Model\HealthRun::class,
                \Weline\Seo\Model\SitemapAction::class,
            ];
            foreach ($models as $modelClass) {
                $model = ObjectManager::getInstance($modelClass);
                $exists = $model->getConnection()->getConnector()->tableExist($model->getTable());
                $this->printer->note(sprintf('  %-55s %s', $model->getTable(), $exists ? 'OK' : 'MISSING'));
            }

            return 0;
        }

        if ($websiteArg === '') {
            $this->printer->error((string)__('Required: --website=CODE|ID or --url=URL'));

            return 1;
        }

        /** @var SeoWebsiteDirectory $directory */
        $directory = ObjectManager::getInstance(SeoWebsiteDirectory::class);
        $websiteId = $this->resolveWebsiteId($directory, $websiteArg);
        if ($websiteId < 0) {
            $this->printer->error((string)__('Website not found: %{1}', [$websiteArg]));

            return 1;
        }

        if ($this->flag($args, 'policy')) {
            /** @var HealthPolicyService $policyService */
            $policyService = ObjectManager::getInstance(HealthPolicyService::class);
            $this->printer->note((string)__('Effective policy matrix for website_id=%{1}:', [(string)$websiteId]));
            foreach ($policyService->matrix($websiteId) as $row) {
                $this->printer->note(sprintf(
                    '  %-20s auto=%-8s action=%-12s grace=%d restore=%s scope=%s',
                    (string)($row['issue_code'] ?? ''),
                    (string)($row['auto_mode'] ?? ''),
                    (string)($row['action'] ?? ''),
                    (int)($row['grace_runs'] ?? 0),
                    !empty($row['restore_enabled']) ? 'yes' : 'no',
                    (string)($row['scope'] ?? '')
                ));
            }

            return 0;
        }

        $result = $service->probeWebsite($websiteId, [
            'max_urls' => $max,
            'timeout' => $timeout,
            'dry_run' => $dryRun,
            'force' => $force,
            'include_results' => true,
        ]);

        $status = (string)($result['status'] ?? '');
        if ($status === 'failed') {
            $this->printer->error((string)__('Health probe failed: %{1}', [(string)($result['message'] ?? 'unknown')]));

            return 1;
        }
        if ($status === 'skipped') {
            $this->printer->warning((string)($result['message'] ?? __('Skipped by store mode hard gate')));

            return 0;
        }

        $this->printer->success((string)__(
            'Health probe website_id=%{1} status=%{2} probed=%{3} ok=%{4} issues=%{5} duration=%{6}ms run=%{7}',
            [
                (string)$websiteId,
                $status,
                (string)(int)($result['probed'] ?? 0),
                (string)(int)($result['ok'] ?? 0),
                (string)(int)($result['issues'] ?? 0),
                (string)(int)($result['duration_ms'] ?? 0),
                (string)(int)($result['run_id'] ?? 0),
            ]
        ));

        if (!empty($result['truncated'])) {
            $this->printer->warning((string)__(
                'Only %{1} of %{2} active URLs were considered in this run; next run continues with oldest checked.',
                [
                    (string)(int)($result['probed'] ?? 0),
                    (string)(int)($result['total_active'] ?? 0),
                ]
            ));
        }

        $byIssue = (array)($result['by_issue'] ?? []);
        if ($byIssue !== []) {
            $this->printer->note((string)__('Issues by category:'));
            foreach ($byIssue as $code => $count) {
                $this->printer->note(sprintf('  %-20s %5d  %s', $code, (int)$count, HealthIssueCatalog::label((string)$code)));
            }
        }

        foreach ((array)($result['results'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $issueCode = (string)($row['issue_code'] ?? '');
            $this->printer->note(sprintf(
                '  %-6s %-20s %s',
                (string)(int)($row['status'] ?? 0),
                $issueCode,
                (string)($row['url'] ?? '')
            ));
        }

        if ($dryRun) {
            $this->printer->note((string)__('Dry run: nothing was written.'));
        }

        return 0;
    }

    /** @param array<string, mixed> $evidence */
    private function printEvidence(array $evidence): void
    {
        $redirects = (array)($evidence['redirects'] ?? []);
        if ($redirects !== []) {
            $this->printer->note((string)__('Redirect chain:'));
            foreach ($redirects as $redirect) {
                if (!is_array($redirect)) {
                    continue;
                }
                $this->printer->note(sprintf(
                    '  %s -> %s',
                    (string)(int)($redirect['status'] ?? 0),
                    (string)($redirect['location'] ?? '')
                ));
            }
        }
        $robots = (array)($evidence['robots'] ?? []);
        if (($robots['state'] ?? '') === 'blocked') {
            $this->printer->warning((string)__(
                'Blocked by robots.txt rule at line %{1}: %{2}',
                [(string)(int)($robots['line'] ?? 0), (string)($robots['rule'] ?? '')]
            ));
        }
        $soft = (array)($evidence['soft_404'] ?? []);
        if (!empty($soft['matched'])) {
            $this->printer->warning((string)__('Soft 404: %{1}', [(string)($soft['reason'] ?? '')]));
        }
        if (!empty($evidence['noindex']) || !empty($evidence['canonical_mismatch'])) {
            $this->printer->note((string)__(
                'Signals: noindex=%{1} canonical_mismatch=%{2} canonical=%{3}',
                [
                    !empty($evidence['noindex']) ? 'yes' : 'no',
                    !empty($evidence['canonical_mismatch']) ? 'yes' : 'no',
                    (string)($evidence['canonical'] ?? ''),
                ]
            ));
        }
    }

    /** @param array<string, mixed> $args */
    private function option(array $args, string $name): string
    {
        $value = trim((string)($args[$name] ?? ''));
        if ($value !== '') {
            return $value;
        }
        $prefix = '--' . $name . '=';
        foreach ($args as $arg) {
            if (is_string($arg) && str_starts_with($arg, $prefix)) {
                return trim(substr($arg, strlen($prefix)));
            }
        }

        return '';
    }

    /** @param array<string, mixed> $args */
    private function flag(array $args, string $name): bool
    {
        if (!empty($args[$name])) {
            return true;
        }
        foreach ($args as $arg) {
            if (is_string($arg) && $arg === '--' . $name) {
                return true;
            }
        }

        return false;
    }

    private function resolveWebsiteId(SeoWebsiteDirectory $directory, string $arg): int
    {
        if (ctype_digit($arg)) {
            $id = (int)$arg;
            $row = $directory->getWebsiteById($id);

            return is_array($row) ? $id : -1;
        }

        foreach ($directory->listWebsites() as $website) {
            if (!is_array($website)) {
                continue;
            }
            if (strcasecmp((string)($website['code'] ?? ''), $arg) === 0
                || strcasecmp((string)($website['domain'] ?? ''), $arg) === 0
            ) {
                return (int)($website['website_id'] ?? -1);
            }
        }

        return -1;
    }
}
