<?php

declare(strict_types=1);

namespace Weline\Visitor\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\PixelChannel;
use Weline\Visitor\Service\PixelChannelCreateService;
use Weline\Visitor\Service\PixelChannelEcommerceFunnelService;
use Weline\Visitor\Service\PixelChannelFunnelMode;
use Weline\Visitor\Service\PixelChannelFunnelService;
use Weline\Visitor\Service\PixelChannelHotTotalsService;
use Weline\Visitor\Service\PixelChannelLandingUrlService;
use Weline\Visitor\Service\PixelChannelTimelineService;
use Weline\Visitor\Service\PixelChannelUpdateService;
use Weline\Visitor\Service\PixelStatisticsService;

/**
 * 流量渠道后台：列表（B03）+ 新建（B04）+ 编辑/停用（B05）+ 详情总计（B10）。
 * ACL：Weline_Visitor::traffic_channel*
 */
#[Acl('Weline_Visitor::traffic_channel', '流量渠道', 'circle', '像素流量渠道管理', 'Weline_Backend::data_tools_group')]
class TrafficChannel extends BackendController
{
    #[Acl('Weline_Visitor::traffic_channel_index', '查看流量渠道列表', 'list', '查看流量渠道列表')]
    public function index(): string
    {
        $kindFilter = \trim((string)($this->request->getGet('kind') ?? PixelChannel::KIND_CAMPAIGN));
        if ($kindFilter !== 'all' && !\in_array($kindFilter, PixelChannel::KINDS, true)) {
            $kindFilter = PixelChannel::KIND_CAMPAIGN;
        }

        $channels = [];
        $loadError = '';

        try {
            /** @var PixelChannel $model */
            $model = ObjectManager::getInstance(PixelChannel::class);
            $model->reset();
            if ($kindFilter !== 'all') {
                $model->where(PixelChannel::schema_fields_KIND, $kindFilter);
            }
            $model->order(PixelChannel::schema_fields_WEBSITE_ID, 'ASC')
                ->order(PixelChannel::schema_fields_CODE, 'ASC')
                ->limit(200)
                ->select()
                ->fetch();
            $channels = $model->getItems() ?: [];
        } catch (\Throwable $e) {
            $loadError = $e->getMessage();
            MessageManager::warning((string)__('加载流量渠道列表失败：%{1}', [$loadError]));
            $channels = [];
        }

        /** @var PixelChannelLandingUrlService $landing */
        $landing = ObjectManager::getInstance(PixelChannelLandingUrlService::class);
        $landingUrls = [];
        foreach ($channels as $row) {
            $data = \is_object($row) && \method_exists($row, 'getData') ? $row->getData() : (array)$row;
            $id = (int)($data['pixel_channel_id'] ?? $data['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $preview = $landing->buildPreview($data, '/');
            if ($preview['showable'] && $preview['url'] !== '') {
                $landingUrls[$id] = $preview['url'];
            }
        }

        $this->assign('channels', $channels);
        $this->assign('landing_urls', $landingUrls);
        $this->assign('kind_filter', $kindFilter);
        $this->assign('load_error', $loadError);
        $this->assign('page_title', (string)__('流量渠道'));

        return $this->fetch();
    }

    #[Acl('Weline_Visitor::traffic_channel_add', '新建流量渠道', 'plus', '新建投放渠道')]
    public function getAdd(): string
    {
        /** @var PixelChannelCreateService $create */
        $create = ObjectManager::getInstance(PixelChannelCreateService::class);
        $this->assignFormDefaults($create, [], false, 0);
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('visitor/backend/traffic-channel/postAdd'));
        $this->assign('form_title', (string)__('新建投放渠道'));

        return $this->fetch('form');
    }

    #[Acl('Weline_Visitor::traffic_channel_add_post', '提交新建流量渠道', 'plus', '提交新建投放渠道')]
    public function postAdd(): string
    {
        /** @var PixelChannelCreateService $create */
        $create = ObjectManager::getInstance(PixelChannelCreateService::class);
        $input = $this->collectFormInput();
        $result = $create->createCampaign($input);
        if ($result['ok']) {
            MessageManager::success((string)__('渠道创建成功：%{1}（%{2}）', [
                (string)($result['row']['name'] ?? ''),
                (string)($result['row']['code'] ?? ''),
            ]));

            return $this->redirect('*/traffic-channel/index');
        }

        foreach ($result['errors'] as $error) {
            MessageManager::error($error);
        }
        $this->assignFormDefaults($create, $input, false, 0);
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('visitor/backend/traffic-channel/postAdd'));
        $this->assign('form_title', (string)__('新建投放渠道'));

        return $this->fetch('form');
    }

    #[Acl('Weline_Visitor::traffic_channel_edit', '编辑流量渠道', 'edit', '编辑投放渠道')]
    public function getEdit(): string
    {
        $id = (int)($this->request->getGet('id') ?? $this->request->getParam('id') ?? 0);
        /** @var PixelChannelUpdateService $update */
        $update = ObjectManager::getInstance(PixelChannelUpdateService::class);
        $row = $update->loadRow($id);
        if ($row === null) {
            MessageManager::error((string)__('渠道不存在'));

            return $this->redirect('*/traffic-channel/index');
        }

        /** @var PixelChannelCreateService $create */
        $create = ObjectManager::getInstance(PixelChannelCreateService::class);
        $this->assignFormDefaults($create, $row, true, $id);
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl(
            'visitor/backend/traffic-channel/postEdit',
            ['id' => $id]
        ));
        $this->assign('form_title', (string)__('编辑投放渠道'));

        return $this->fetch('form');
    }

    #[Acl('Weline_Visitor::traffic_channel_edit_post', '提交编辑流量渠道', 'edit', '提交编辑投放渠道')]
    public function postEdit(): string
    {
        $id = (int)($this->request->getPost('id')
            ?? $this->request->getGet('id')
            ?? $this->request->getParam('id')
            ?? 0);
        /** @var PixelChannelUpdateService $update */
        $update = ObjectManager::getInstance(PixelChannelUpdateService::class);
        /** @var PixelChannelCreateService $create */
        $create = ObjectManager::getInstance(PixelChannelCreateService::class);

        $input = $this->collectFormInput();
        $result = $update->updateCampaign($id, $input);
        if ($result['ok']) {
            MessageManager::success((string)__('渠道已保存：%{1}（%{2}）', [
                (string)($result['row']['name'] ?? ''),
                (string)($result['row']['code'] ?? ''),
            ]));

            return $this->redirect('*/traffic-channel/index');
        }

        foreach ($result['errors'] as $error) {
            MessageManager::error($error);
        }
        $original = $update->loadRow($id) ?? [];
        $formInput = \array_merge($original, $input, [
            'code' => (string)($original['code'] ?? $result['row']['code'] ?? ''),
        ]);
        $this->assignFormDefaults($create, $formInput, true, $id);
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl(
            'visitor/backend/traffic-channel/postEdit',
            ['id' => $id]
        ));
        $this->assign('form_title', (string)__('编辑投放渠道'));

        return $this->fetch('form');
    }

    /**
     * B10–B12：渠道详情总计 + 事件轨迹 + 热表漏斗（F05b 起可切换营销简漏斗 / 电商四步）。
     */
    #[Acl('Weline_Visitor::traffic_channel_detail', '查看流量渠道详情总计', 'chart', '查看投放渠道热表总计')]
    public function getDetail(): string
    {
        $id = (int)($this->request->getGet('id') ?? $this->request->getParam('id') ?? 0);
        /** @var PixelChannelUpdateService $update */
        $update = ObjectManager::getInstance(PixelChannelUpdateService::class);
        $row = $update->loadRow($id);
        if ($row === null) {
            MessageManager::error((string)__('渠道不存在'));

            return $this->redirect('*/traffic-channel/index');
        }

        $timelineDays = (int)($this->request->getGet('timeline_days')
            ?? $this->request->getParam('timeline_days')
            ?? PixelChannelTimelineService::DEFAULT_DAYS);

        /** @var PixelChannelHotTotalsService $totals */
        $totals = ObjectManager::getInstance(PixelChannelHotTotalsService::class);
        $hotTotals = $totals->buildForChannel($row);
        if ($hotTotals['error'] !== '') {
            MessageManager::warning((string)__('热表总计暂不可用：%{1}', [$hotTotals['error']]));
        }

        /** @var PixelChannelTimelineService $timelineService */
        $timelineService = ObjectManager::getInstance(PixelChannelTimelineService::class);
        $timeline = $timelineService->buildForChannel($row, $timelineDays);
        if ($timeline['error'] !== '') {
            MessageManager::warning((string)__('事件轨迹暂不可用：%{1}', [$timeline['error']]));
        }

        // F05b：漏斗模式（默认营销简漏斗，保持既有链接语义）
        $funnelMode = PixelChannelFunnelMode::normalize(
            $this->request->getGet('funnel_mode') ?? $this->request->getParam('funnel_mode')
        );
        if (PixelChannelFunnelMode::isEcommerce($funnelMode)) {
            /** @var PixelChannelEcommerceFunnelService $ecommerceFunnelService */
            $ecommerceFunnelService = ObjectManager::getInstance(PixelChannelEcommerceFunnelService::class);
            $funnel = $ecommerceFunnelService->buildForChannel($row, $timelineDays);
        } else {
            /** @var PixelChannelFunnelService $funnelService */
            $funnelService = ObjectManager::getInstance(PixelChannelFunnelService::class);
            $funnel = $funnelService->buildForChannel($row, $timelineDays);
        }
        if ($funnel['error'] !== '') {
            MessageManager::warning((string)__('渠道漏斗暂不可用：%{1}', [$funnel['error']]));
        }

        /** @var PixelChannelLandingUrlService $landing */
        $landing = ObjectManager::getInstance(PixelChannelLandingUrlService::class);
        $preview = $landing->buildPreview($row, '/');

        $this->assign('channel', $row);
        $this->assign('hot_totals', $hotTotals);
        $this->assign('timeline', $timeline);
        $this->assign('funnel', $funnel);
        $this->assign('funnel_mode', $funnelMode);
        $this->assign('landing_preview', $preview);
        $this->assign('page_title', (string)__('渠道详情'));

        return $this->fetch('detail');
    }

    /**
     * 列表快捷停用/启用。
     */
    #[Acl('Weline_Visitor::traffic_channel_toggle', '停用或启用流量渠道', 'switch', '停用或启用投放渠道')]
    public function postToggleEnabled(): string
    {
        $id = (int)($this->request->getPost('id') ?? $this->request->getGet('id') ?? 0);
        $enabled = (int)($this->request->getPost('enabled') ?? $this->request->getGet('enabled') ?? 0) === 1;
        /** @var PixelChannelUpdateService $update */
        $update = ObjectManager::getInstance(PixelChannelUpdateService::class);
        $result = $update->setEnabled($id, $enabled);
        if ($result['ok']) {
            MessageManager::success($enabled
                ? (string)__('渠道已启用')
                : (string)__('渠道已停用'));
        } else {
            foreach ($result['errors'] as $error) {
                MessageManager::error($error);
            }
        }

        return $this->redirect('*/traffic-channel/index');
    }

    /** @return array<string,mixed> */
    private function collectFormInput(): array
    {
        $post = $this->request->getPost() ?: [];

        return [
            'code' => (string)($post['code'] ?? ''),
            'name' => (string)($post['name'] ?? ''),
            'traffic_type' => (string)($post['traffic_type'] ?? PixelChannel::TRAFFIC_CUSTOM),
            'website_id' => (int)($post['website_id'] ?? 0),
            'description' => (string)($post['description'] ?? ''),
            'enabled' => \array_key_exists('enabled_present', $post)
                ? (\array_key_exists('enabled', $post) ? 1 : 0)
                : 1,
            'utm_source' => (string)($post['utm_source'] ?? ''),
            'utm_medium' => (string)($post['utm_medium'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $input */
    private function assignFormDefaults(
        PixelChannelCreateService $create,
        array $input,
        bool $codeReadonly,
        int $id
    ): void {
        $code = \trim((string)($input['code'] ?? ''));
        $trafficType = \trim((string)($input['traffic_type'] ?? PixelChannel::TRAFFIC_PAID)) ?: PixelChannel::TRAFFIC_PAID;
        $utm = $create->buildUtmPackage(
            $code !== '' ? $code : 'your_code',
            $trafficType,
            \array_key_exists('utm_source', $input) ? (string)$input['utm_source'] : null,
            \array_key_exists('utm_medium', $input) ? (string)$input['utm_medium'] : null,
        );

        $landingPath = (string)($input['landing_path'] ?? '/');
        $formRow = [
            'id' => $id,
            'code' => $code,
            'name' => \trim((string)($input['name'] ?? '')),
            'traffic_type' => $trafficType,
            'website_id' => (int)($input['website_id'] ?? 0),
            'description' => \trim((string)($input['description'] ?? '')),
            'enabled' => !isset($input['enabled']) || (int)$input['enabled'] === 1,
            'utm_source' => (string)($input['utm_source'] ?? $utm['utm_source']),
            'utm_medium' => (string)($input['utm_medium'] ?? $utm['utm_medium']),
            'utm_campaign_preview' => $utm['utm_campaign'],
            'wch_preview' => $utm['wch'],
            'landing_path' => $landingPath,
        ];
        /** @var PixelChannelLandingUrlService $landing */
        $landing = ObjectManager::getInstance(PixelChannelLandingUrlService::class);
        $preview = $landing->buildPreview([
            'code' => $code !== '' ? $code : 'your_code',
            'traffic_type' => $trafficType,
            'website_id' => $formRow['website_id'],
            'enabled' => $formRow['enabled'] ? 1 : 0,
            'utm_source' => $formRow['utm_source'],
            'utm_medium' => $formRow['utm_medium'],
        ], $landingPath);
        $formRow['landing_url'] = $preview['url'];
        $formRow['landing_base_url'] = $preview['base_url'];
        $formRow['landing_showable'] = $preview['showable'] && $preview['url'] !== '';

        $this->assign('form', $formRow);
        $this->assign('code_readonly', $codeReadonly);
        $this->assign('is_edit', $codeReadonly);
        $this->assign('traffic_types', PixelChannel::TRAFFIC_TYPES);
        $this->assign('medium_by_type', PixelChannelCreateService::MEDIUM_BY_TRAFFIC_TYPE);
        $this->assign('default_utm_source', PixelChannelCreateService::DEFAULT_UTM_SOURCE);
        $this->assign('landing_preview', $preview);
        $websiteSelectOptions = PixelStatisticsService::buildWebsiteSelectOptions();
        $this->assign('website_select_options', $websiteSelectOptions);
        $this->assign(
            'website_select_options_json',
            \json_encode($websiteSelectOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]'
        );
    }
}
