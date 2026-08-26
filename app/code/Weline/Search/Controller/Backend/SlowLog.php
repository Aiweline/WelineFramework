<?php

declare(strict_types=1);

namespace Weline\Search\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Search\Service\SearchReportService;

#[Acl(
    'Weline_Search::commerce:tax-search:slow-log',
    '性能慢日志',
    'clock',
    '查看搜索性能慢日志',
    'Weline_Search::commerce:tax-search:control-center'
)]
final class SlowLog extends BackendController
{
    public function __construct(
        private readonly SearchReportService $reports,
    ) {
    }

    public function index(): string
    {
        $scope = $this->readScope();
        $range = (string)$this->request->getParam('range', '24h');
        if (!in_array($range, ['24h', '7d', '30d'], true)) {
            $range = '24h';
        }
        $page = max(1, min(100, (int)$this->request->getParam('page', 1)));
        $data = $this->reports->buildSlowLog($scope, $range, $page);
        $this->assign('slow', $data);
        $this->assign('range', $range);
        $this->assign('threshold_ms', $data['threshold_ms'] ?? 200);

        return (string)$this->fetch('Weline_Search::templates/Backend/SlowLog/index.phtml');
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
