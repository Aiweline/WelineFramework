<?php

namespace Weline\Visitor\Api\Rest\V1;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Visitor\Model\Pixel;

/**
 * 像素统计API
 * 提供像素数据的统计查询接口
 */
class Statistics extends FrontendRestController
{
    /**
     * 获取站点统计信息
     * 
     * @return string
     * @Document(summary='获取站点统计信息', description='获取指定站点的像素统计信息，包括总记录数、事件统计等', tags=['像素', '统计'], category='像素接口')
     * @example
     * Method: GET
     * Path: /visitor/rest/v1/statistics/website
     * Request Parameters:
     * - websiteId: 1 (可选，从URL参数、GET参数或SERVER变量获取，默认使用当前请求的站点ID)
     * Response:
     * {
     *   "code": 200,
     *   "msg": "获取统计信息成功",
     *   "data": {
     *     "website_id": 1,
     *     "total_count": 1000,
     *     "events": {
     *       "click": 500,
     *       "login": 200,
     *       "register": 100
     *     },
     *     "event_list": ["click", "login", "register"]
     *   }
     * }
     * @example-end
     */
    public function getWebsite(): string
    {
        try {
            // 获取站点ID（优先从请求参数获取，其次从SERVER变量获取）
            $websiteId = 0;
            $paramWebsiteId = $this->request->getParam('websiteId') ?? $this->request->getGet('websiteId');
            if ($paramWebsiteId !== null && $paramWebsiteId !== '') {
                $websiteId = (int)$paramWebsiteId;
            } elseif (isset($_SERVER['WELINE_WEBSITE_ID']) && $_SERVER['WELINE_WEBSITE_ID'] !== '') {
                $websiteId = (int)$_SERVER['WELINE_WEBSITE_ID'];
            }
            
            // 获取时间范围参数（可选）
            $startDate = $this->request->getParam('startDate') ?? $this->request->getGet('startDate');
            $endDate = $this->request->getParam('endDate') ?? $this->request->getGet('endDate');
            
            // 如果提供了时间范围，使用时间范围统计
            if ($startDate !== null || $endDate !== null) {
                $stats = Pixel::getWebsiteStatsByDateRange($websiteId, $startDate, $endDate);
            } else {
                // 否则使用统计摘要
                $stats = Pixel::getWebsiteSummary($websiteId);
            }
            
            return $this->success(__('获取统计信息成功'), $stats);
            
        } catch (\Exception $e) {
            return $this->error(__('获取统计信息失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * 获取事件统计信息
     * 
     * @return string
     * @Document(summary='获取事件统计信息', description='获取指定站点和事件的统计信息', tags=['像素', '统计'], category='像素接口')
     * @example
     * Method: GET
     * Path: /visitor/rest/v1/statistics/event
     * Request Parameters:
     * - websiteId: 1 (可选，从URL参数、GET参数或SERVER变量获取)
     * - event: login (必填，事件名称)
     * Response:
     * {
     *   "code": 200,
     *   "msg": "获取事件统计成功",
     *   "data": {
     *     "website_id": 1,
     *     "event": "login",
     *     "count": 200
     *   }
     * }
     * @example-end
     */
    public function getEvent(): string
    {
        try {
            // 获取站点ID
            $websiteId = 0;
            $paramWebsiteId = $this->request->getParam('websiteId') ?? $this->request->getGet('websiteId');
            if ($paramWebsiteId !== null && $paramWebsiteId !== '') {
                $websiteId = (int)$paramWebsiteId;
            } elseif (isset($_SERVER['WELINE_WEBSITE_ID']) && $_SERVER['WELINE_WEBSITE_ID'] !== '') {
                $websiteId = (int)$_SERVER['WELINE_WEBSITE_ID'];
            }
            
            // 获取事件名
            $event = $this->request->getParam('event') ?? $this->request->getGet('event') ?? '';
            if (empty($event)) {
                return $this->error(__('事件名称不能为空'), '', 400);
            }
            
            // 统计事件数量
            $count = Pixel::countPixelsByWebsiteIdAndEvent($websiteId, $event);
            
            return $this->success(__('获取事件统计成功'), [
                'website_id' => $websiteId,
                'event' => $event,
                'count' => $count
            ]);
            
        } catch (\Exception $e) {
            return $this->error(__('获取事件统计失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * 获取所有站点列表
     * 
     * @return string
     * @Document(summary='获取所有站点列表', description='获取系统中所有有像素记录的站点ID列表', tags=['像素', '统计'], category='像素接口')
     * @example
     * Method: GET
     * Path: /visitor/rest/v1/statistics/websites
     * Response:
     * {
     *   "code": 200,
     *   "msg": "获取站点列表成功",
     *   "data": {
     *     "website_ids": [0, 1, 2, 3]
     *   }
     * }
     * @example-end
     */
    public function getWebsites(): string
    {
        try {
            $websiteIds = Pixel::getAllWebsiteIds();
            
            return $this->success(__('获取站点列表成功'), [
                'website_ids' => $websiteIds,
                'count' => count($websiteIds)
            ]);
            
        } catch (\Exception $e) {
            return $this->error(__('获取站点列表失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
}

