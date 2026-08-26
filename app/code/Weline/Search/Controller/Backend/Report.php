<?php

declare(strict_types=1);

namespace Weline\Search\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Search\Service\SearchReportService;

#[Acl(
    'Weline_Search::commerce:tax-search:report',
    '搜索报告',
    'chart',
    '查看搜索报告',
    'Weline_Search::commerce:tax-search:control-center'
)]
final class Report extends BackendController
{
    public function __construct(
        private readonly SearchReportService $reports,
    ) {
    }

    public function index(): string
    {
        $scope = $this->readScope();
        $range = (string)$this->request->getParam('range', '7d');
        if (!in_array($range, ['24h', '7d', '30d'], true)) {
            $range = '7d';
        }
        $data = $this->reports->buildReport($scope, $range);
        $this->assign('report', $data);
        $this->assign('range', $range);
        $this->assign('engine', 'mysql');

        return (string)$this->fetch('Weline_Search::templates/Backend/Report/index.phtml');
    }

    /** @return array{website_id:int,store_id:int,channel_id:int} */
    private function readScope(): array
    {
        return [
            'website_id' => max(0, (int)$this->request->getParam('website_id', 0)),
            'store_id' => max(0, (int)$this->request->getParam('store_id', 0)),
            'channel_id' => max(0, (int)$this->request->getParam('channel_id', 0)),
        ];
    }
}
