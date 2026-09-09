<?php

declare(strict_types=1);

namespace Weline\Seo\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendPageController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Service\Duplicate\ContentDuplicateScanner;
use Weline\Seo\Service\Duplicate\DuplicateCheckScope;
use Weline\Seo\Service\Duplicate\DuplicateReportService;
use Weline\Seo\Service\Duplicate\DuplicateReportUrlBuilder;
use Weline\Seo\Service\SeoWebsiteDirectory;

#[Acl('Weline_Seo::seo_duplicate', '内容重复检测', 'copy', 'SEO 站内正文近重复检测', 'Weline_Backend::seo_group')]
class Duplicate extends BackendPageController
{
    /**
     * 面板：按范围触发扫描 + 历史 run 列表
     */
    #[Acl('Weline_Seo::seo_duplicate_index', '查看重复检测面板', 'copy', '查看重复检测面板')]
    public function index(): string
    {
        /** @var SeoWebsiteDirectory $directory */
        $directory = ObjectManager::getInstance(SeoWebsiteDirectory::class);
        /** @var DuplicateReportService $reports */
        $reports = ObjectManager::getInstance(DuplicateReportService::class);
        /** @var DuplicateReportUrlBuilder $urlBuilder */
        $urlBuilder = ObjectManager::getInstance(DuplicateReportUrlBuilder::class);

        $websiteIdFilter = $this->request->getGet('website_id');
        $websiteId = $websiteIdFilter !== null && $websiteIdFilter !== '' ? (int)$websiteIdFilter : null;

        $message = '';
        $error = '';
        $lastReportUrl = '';

        if ($this->request->isPost()) {
            try {
                $scope = DuplicateCheckScope::fromArray([
                    'website_id' => (int)$this->request->getPost('website_id'),
                    'entity_types' => (string)$this->request->getPost('entity_types', ''),
                    'path_prefix' => (string)$this->request->getPost('path_prefix', ''),
                    'sample_limit' => (int)$this->request->getPost('sample_limit', 500),
                    'jaccard_duplicate' => (float)$this->request->getPost('jaccard_duplicate', 0.85),
                    'jaccard_suspect' => (float)$this->request->getPost('jaccard_suspect', 0.70),
                    'mode' => (string)$this->request->getPost('mode', DuplicateCheckScope::MODE_SAMPLE),
                    'notify' => (bool)$this->request->getPost('notify', true),
                ]);
                /** @var ContentDuplicateScanner $scanner */
                $scanner = ObjectManager::getInstance(ContentDuplicateScanner::class);
                $result = $scanner->scan($scope);
                $lastReportUrl = (string)($result['report_url'] ?? '');
                $message = (string)__(
                    '扫描完成：重复 %1，疑似 %2。报告：%3',
                    (int)($result['stats']['duplicate'] ?? 0),
                    (int)($result['stats']['suspect'] ?? 0),
                    $lastReportUrl
                );
                if (!empty($result['run_id'])) {
                    $this->redirect($urlBuilder->pathForRun((int)$result['run_id']));

                    return '';
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $runs = $reports->listRecentRuns($websiteId, 50);
        foreach ($runs as &$run) {
            $rid = (int)($run['run_id'] ?? 0);
            if ($rid > 0) {
                // Always rebuild so stale rows without admin frontName do not 404.
                $run['report_path'] = $urlBuilder->pathForRun($rid);
                $run['report_url'] = $urlBuilder->absoluteForRun($rid);
            }
        }
        unset($run);

        if ($lastReportUrl === '' && $runs !== []) {
            $lastReportUrl = (string)($runs[0]['report_url'] ?? '');
        }

        $this->assign('seo_page_title', __('内容重复检测'));
        $this->assign('websites', $directory->listWebsites());
        $this->assign('runs', $runs);
        $this->assign('message', $message);
        $this->assign('error', $error);
        $this->assign('last_report_url', $lastReportUrl);
        $this->assign('panel_path', $urlBuilder->panelPath());
        $this->assign('filter_website_id', $websiteId);

        return $this->fetch('Weline_Seo::templates/Backend/Duplicate/index.phtml');
    }

    /**
     * 报告页：稳定 URL /seo/backend/duplicate/report?run_id=
     */
    #[Acl('Weline_Seo::seo_duplicate_report', '查看重复检测报告', 'copy', '查看重复检测报告')]
    public function report(): string
    {
        $runId = (int)$this->request->getGet('run_id', 0);
        $grade = \trim((string)$this->request->getGet('grade', ''));
        /** @var DuplicateReportService $reports */
        $reports = ObjectManager::getInstance(DuplicateReportService::class);
        /** @var DuplicateReportUrlBuilder $urlBuilder */
        $urlBuilder = ObjectManager::getInstance(DuplicateReportUrlBuilder::class);

        $run = $reports->getRun($runId);
        $gradeOrNull = $grade !== '' ? $grade : null;
        $panelPath = $urlBuilder->panelPath();
        if ($run === null) {
            $this->assign('seo_page_title', __('重复内容报告'));
            $this->assign('run', null);
            $this->assign('pairs', []);
            $this->assign('grade_filter', $grade);
            $this->assign('error', (string)__('报告不存在：run_id=%1', $runId));
            $this->assign('report_path', $runId > 0 ? $urlBuilder->pathForRun($runId, $gradeOrNull) : '');
            $this->assign('report_url', $runId > 0 ? $urlBuilder->absoluteForRun($runId, null, $gradeOrNull) : '');
            $this->assign('panel_path', $panelPath);
            $this->assign('report_path_all', $runId > 0 ? $urlBuilder->pathForRun($runId) : '');
            $this->assign('report_path_duplicate', $runId > 0 ? $urlBuilder->pathForRun($runId, 'duplicate') : '');
            $this->assign('report_path_suspect', $runId > 0 ? $urlBuilder->pathForRun($runId, 'suspect') : '');

            return $this->fetch('Weline_Seo::templates/Backend/Duplicate/report.phtml');
        }

        // Prefer live builder over persisted URLs (old rows omitted admin frontName → 404).
        $reportPath = $urlBuilder->pathForRun($runId, $gradeOrNull);
        $reportUrl = $urlBuilder->absoluteForRun($runId, null, $gradeOrNull);

        $this->assign('seo_page_title', __('重复内容报告') . ' #' . $runId);
        $this->assign('run', $run);
        $this->assign('pairs', $reports->listPairs($runId, $gradeOrNull));
        $this->assign('grade_filter', $grade);
        $this->assign('error', '');
        $this->assign('report_path', $reportPath);
        $this->assign('report_url', $reportUrl);
        $this->assign('panel_path', $panelPath);
        $this->assign('report_path_all', $urlBuilder->pathForRun($runId));
        $this->assign('report_path_duplicate', $urlBuilder->pathForRun($runId, 'duplicate'));
        $this->assign('report_path_suspect', $urlBuilder->pathForRun($runId, 'suspect'));

        return $this->fetch('Weline_Seo::templates/Backend/Duplicate/report.phtml');
    }
}
