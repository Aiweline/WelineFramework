<?php

declare(strict_types=1);

namespace Weline\Seo\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendPageController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Service\Health\HealthPolicyService;
use Weline\Seo\Service\Health\HealthReportService;
use Weline\Seo\Service\Health\SitemapActionApplier;
use Weline\Seo\Service\Health\UrlHealthProbeService;
use Weline\Seo\Service\SeoWebsiteDirectory;

/**
 * 索引健康：本地版 Search Console 索引覆盖 + 处理规则 + 动作台账 + 探测记录。
 *
 * 探测只记录；规则决策写动作台账；动作在 sitemap cron 的发布前执行。
 */
#[Acl('Weline_Seo::sitemap_health', '索引健康', 'search', 'SEO 站点索引健康', 'Weline_Backend::seo_group')]
class Health extends BackendPageController
{
    private const VIEWS = ['health', 'policy', 'actions', 'runs'];
    private const PER_PAGE = 25;

    #[Acl('Weline_Seo::sitemap_health_index', '查看索引健康', 'search', '查看索引健康')]
    public function index(): string
    {
        /** @var SeoWebsiteDirectory $directory */
        $directory = ObjectManager::getInstance(SeoWebsiteDirectory::class);
        /** @var HealthReportService $reports */
        $reports = ObjectManager::getInstance(HealthReportService::class);
        /** @var HealthPolicyService $policies */
        $policies = ObjectManager::getInstance(HealthPolicyService::class);

        $websites = $directory->listWebsites();
        $websiteId = $this->resolveWebsiteId($websites);
        $website = $websiteId >= 0 ? $directory->getWebsiteById($websiteId) : null;

        $view = (string)$this->request->getGet('view', 'health');
        if (!in_array($view, self::VIEWS, true)) {
            $view = 'health';
        }

        $filters = [
            'issue' => trim((string)$this->request->getGet('issue', '')),
            'q' => trim((string)$this->request->getGet('q', '')),
            'state' => trim((string)$this->request->getGet('state', '')),
            'locale' => trim((string)$this->request->getGet('locale', '')),
        ];
        $page = max(1, (int)$this->request->getGet('page', 1));

        $summary = $reports->summary($websiteId, $filters);
        $tabs = $reports->tabs($websiteId, $filters);
        $list = $reports->list($websiteId, $filters, $page, self::PER_PAGE);
        $runs = $reports->runs($websiteId, 20);

        $this->assign('websites', $websites);
        $this->assign('website_id', $websiteId);
        $this->assign('website', $website);
        $this->assign('view', $view);
        $this->assign('filters', $filters);
        $this->assign('summary', $summary);
        $this->assign('tabs', $tabs);
        $this->assign('list', $list);
        $this->assign('runs', $runs);
        $this->assign('policy_matrix', $policies->matrix($websiteId));
        $this->assign('actions', $reports->actions($websiteId, 50));

        return $this->fetch();
    }

    #[Acl('Weline_Seo::sitemap_health_data', '读取索引健康数据', 'search', '读取索引健康数据')]
    public function data(): string
    {
        $websiteId = (int)$this->request->getGet('website_id', 0);
        $filters = [
            'issue' => trim((string)$this->request->getGet('issue', '')),
            'q' => trim((string)$this->request->getGet('q', '')),
            'state' => trim((string)$this->request->getGet('state', '')),
            'locale' => trim((string)$this->request->getGet('locale', '')),
        ];
        $page = max(1, (int)$this->request->getGet('page', 1));

        /** @var HealthReportService $reports */
        $reports = ObjectManager::getInstance(HealthReportService::class);

        return $this->jsonResponse(true, '', [
            'summary' => $reports->summary($websiteId, $filters),
            'tabs' => $reports->tabs($websiteId, $filters),
            'list' => $reports->list($websiteId, $filters, $page, self::PER_PAGE),
        ]);
    }

    #[Acl('Weline_Seo::sitemap_health_probe', '执行索引健康探测', 'refresh', '执行索引健康探测')]
    public function probe(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(false, (string)__('无效的请求方法'));
        }

        $websiteId = (int)$this->request->getPost('website_id', -1);
        if ($websiteId < 0) {
            return $this->jsonResponse(false, (string)__('请先选择站点'));
        }

        $maxUrls = (int)$this->request->getPost('max_urls', UrlHealthProbeService::DEFAULT_MAX_URLS);
        $maxUrls = min(1000, max(1, $maxUrls));
        $dryRun = (string)$this->request->getPost('dry_run', '') === '1';

        try {
            /** @var UrlHealthProbeService $probeService */
            $probeService = ObjectManager::getInstance(UrlHealthProbeService::class);
            $result = $probeService->probeWebsite($websiteId, [
                'max_urls' => $maxUrls,
                'budget_ms' => 180000,
                'dry_run' => $dryRun,
                // 后台手动探测是明确动作：dev/test 的 sitemap 硬门禁不阻止健康检查。
                'force' => true,
                'include_results' => false,
            ]);

            $status = (string)($result['status'] ?? '');
            $message = (string)__(
                '探测完成：%{1} 条，正常 %{2}，问题 %{3}',
                [
                    (int)($result['probed'] ?? 0),
                    (int)($result['ok'] ?? 0),
                    (int)($result['issues'] ?? 0),
                ]
            );
            if ($status === 'partial') {
                $message .= ' ' . __('（预算用完，下一轮继续）');
            }
            if ($status === 'skipped') {
                $message = (string)($result['message'] ?? __('当前站点模式跳过探测'));
            }

            return $this->jsonResponse($status !== 'failed', $message, $result);
        } catch (\Throwable $e) {
            w_log_error('[Weline_Seo] health probe error: ' . $e->getMessage());

            return $this->jsonResponse(false, (string)__('探测失败：%{1}', $e->getMessage()));
        }
    }

    #[Acl('Weline_Seo::sitemap_health_policy_save', '保存索引健康规则', 'settings', '保存索引健康规则')]
    public function savePolicy(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(false, (string)__('无效的请求方法'));
        }

        $websiteId = (int)$this->request->getPost('website_id', -1);
        if ($websiteId < 0) {
            return $this->jsonResponse(false, (string)__('请先选择站点'));
        }

        $raw = $this->request->getPost('policies', '');
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw) || $raw === []) {
            return $this->jsonResponse(false, (string)__('没有可保存的规则'));
        }

        try {
            /** @var HealthPolicyService $policies */
            $policies = ObjectManager::getInstance(HealthPolicyService::class);
            $result = $policies->saveWebsitePolicies($websiteId, $raw);
            $message = (string)__('已保存 %{1} 条规则', [(int)$result['saved']]);
            if (($result['errors'] ?? []) !== []) {
                $message .= '；' . (string)__('忽略 %{1} 条无效项', [count($result['errors'])]);
            }

            return $this->jsonResponse(true, $message, $result);
        } catch (\Throwable $e) {
            w_log_error('[Weline_Seo] health policy save error: ' . $e->getMessage());

            return $this->jsonResponse(false, (string)__('保存失败：%{1}', [$e->getMessage()]));
        }
    }

    #[Acl('Weline_Seo::sitemap_health_action_revert', '撤销索引健康动作', 'undo', '撤销索引健康动作')]
    public function revertAction(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(false, (string)__('无效的请求方法'));
        }
        $actionId = (int)$this->request->getPost('action_id', 0);
        if ($actionId <= 0) {
            return $this->jsonResponse(false, (string)__('缺少动作 ID'));
        }

        try {
            /** @var SitemapActionApplier $applier */
            $applier = ObjectManager::getInstance(SitemapActionApplier::class);
            $result = $applier->revert($actionId);

            return $this->jsonResponse((bool)$result['ok'], (string)$result['message'], $result);
        } catch (\Throwable $e) {
            w_log_error('[Weline_Seo] health action revert error: ' . $e->getMessage());

            return $this->jsonResponse(false, (string)__('撤销失败：%{1}', [$e->getMessage()]));
        }
    }

    /**
     * @param list<array<string, mixed>> $websites
     */
    private function resolveWebsiteId(array $websites): int
    {
        $raw = $this->request->getGet('website_id');
        if ($raw !== null && $raw !== '') {
            return (int)$raw;
        }
        foreach ($websites as $website) {
            if (is_array($website) && isset($website['website_id'])) {
                return (int)$website['website_id'];
            }
        }

        return 0;
    }

    /** @param array<string, mixed> $data */
    private function jsonResponse(bool $success, string $message, array $data = []): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');

        return (string)json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
    }
}
