<?php

declare(strict_types=1);

namespace WeShop\Promotion\Controller\Backend\Campaign;

use Weline\Framework\App\Controller\BackendController;
use WeShop\Promotion\Repository\CampaignRepository;

/**
 * Campaign backend controller - List
 */
class Index extends BackendController
{
    public function index(): string
    {
        $repository = $this->getRepository();

        $searchQuery = trim((string) $this->request->getGet('q', ''));
        $page = (int) ($this->request->getGet('page', 1));
        $pageSize = 20;

        $filters = [];
        if ($searchQuery !== '') {
            $filters['name'] = $searchQuery;
        }

        $campaigns = $repository->listCampaigns($filters, $page, $pageSize);
        $totalCount = $repository->countCampaigns($filters);
        $summary = $repository->getCampaignSummary();

        $pagination = $this->buildPagination($page, $pageSize, $totalCount);

        $this->assign('title', __('促销活动管理'));
        $this->assign('campaigns', $campaigns);
        $this->assign('summary', $summary);
        $this->assign('pagination', $pagination);
        $this->assign('search_query', $searchQuery);

        // 显式指定真实模板目录：view/backend/templates/Campaign/Index/index.phtml
        return (string) $this->fetch('WeShop_Promotion::backend/templates/Campaign/Index/index.phtml');
    }

    private function getRepository(): CampaignRepository
    {
        return new CampaignRepository();
    }

    private function buildPagination(int $page, int $pageSize, int $totalCount): string
    {
        if ($totalCount <= $pageSize) {
            return '';
        }

        $totalPages = (int) ceil($totalCount / $pageSize);
        $baseUrl = $this->_url->getBackendUrl('*/backend/campaign');

        $html = '<nav aria-label="Campaign pagination"><ul class="pagination justify-content-center">';

        if ($page > 1) {
            $prevPage = $page - 1;
            $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?page=' . $prevPage . '">' . __('上一页') . '</a></li>';
        }

        $start = max(1, $page - 2);
        $end = min($totalPages, $page + 2);

        if ($start > 1) {
            $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?page=1">1</a></li>';
            if ($start > 2) {
                $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
        }

        for ($i = $start; $i <= $end; $i++) {
            $active = $i === $page ? ' active' : '';
            $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . $baseUrl . '?page=' . $i . '">' . $i . '</a></li>';
        }

        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
            $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?page=' . $totalPages . '">' . $totalPages . '</a></li>';
        }

        if ($page < $totalPages) {
            $nextPage = $page + 1;
            $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?page=' . $nextPage . '">' . __('下一页') . '</a></li>';
        }

        $html .= '</ul></nav>';

        return $html;
    }
}
