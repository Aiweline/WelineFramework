<?php

declare(strict_types=1);

namespace Weline\Theme\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Theme\Service\Region\GlobalRegionConfigService;

/**
 * 全局区面板接口：列出贡献项、按项/区域/分组开关、写入条目配置。
 *
 * 面板只跟这个控制器打交道，存储细节（`theme/base_contributions`）留在
 * {@see GlobalRegionConfigService}，渲染侧读的是同一份树。
 */
class Region extends BackendController
{
    public function __construct(
        private readonly GlobalRegionConfigService $configService,
    ) {
    }

    /** GET /theme/backend/region/index — 面板页（三区列表 + 开关 + 配置）。 */
    public function index(): string
    {
        $this->assign('items', $this->configService->listContributions());
        $this->assign('page_title', __('全局区'));

        return $this->fetch('Weline_Theme::templates/backend/region/index.phtml');
    }

    /** GET /theme/backend/region/list — 同一份数据的 JSON（供外部工具/后续前端复用）。 */
    public function list(): string
    {
        $items = $this->configService->listContributions();

        return $this->fetchJson([
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                'items' => $items,
                'total' => \count($items),
            ],
        ]);
    }

    /** POST /theme/backend/region/toggle — 单条开关（也支持 region / group 维度）。 */
    public function toggle(): string
    {
        $id = \trim((string)$this->request->getParam('id', ''));
        $region = \trim((string)$this->request->getParam('region', ''));
        $group = \trim((string)$this->request->getParam('group', ''));
        $enabled = $this->truthy($this->request->getParam('enabled', null));
        if ($enabled === null) {
            return $this->fetchJson(['code' => 1, 'msg' => 'enabled is required']);
        }

        if ($region !== '' && $group !== '') {
            $this->configService->setGroupEnabled($region, $group, $enabled);
        } elseif ($region !== '') {
            $this->configService->setRegionEnabled($region, $enabled);
        } elseif ($id !== '') {
            $this->configService->setEnabled($id, $enabled);
        } else {
            return $this->fetchJson(['code' => 1, 'msg' => 'id or region is required']);
        }

        return $this->fetchJson(['code' => 0, 'msg' => 'ok']);
    }

    /** POST /theme/backend/region/config — 写入某条贡献的部件配置（颜色/文案/曲目等）。 */
    public function config(): string
    {
        $id = \trim((string)$this->request->getParam('id', ''));
        if ($id === '') {
            return $this->fetchJson(['code' => 1, 'msg' => 'id is required']);
        }
        $raw = $this->request->getParam('config', '');
        if (\is_string($raw)) {
            $decoded = \json_decode($raw, true);
            $values = \is_array($decoded) ? $decoded : [];
        } else {
            $values = \is_array($raw) ? $raw : [];
        }
        $this->configService->setConfig($id, $values);

        return $this->fetchJson(['code' => 0, 'msg' => 'ok']);
    }

    private function truthy(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (\is_bool($value)) {
            return $value;
        }

        return \in_array(\strtolower((string)$value), ['1', 'true', 'on', 'yes'], true);
    }
}
