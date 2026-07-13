<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Shipping\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\Message;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\I18n\Api\Localization\CountryRepositoryInterface;
use Weline\Shipping\Service\RegionService;

#[Acl('Weline_Shipping::region', '地区管理', 'mdi-map-marker', '地区管理', 'Weline_Backend::shipping_group')]
class Region extends BackendController
{
    public function __construct(
        private readonly RegionService $regionService,
        private readonly RuntimeProviderResolver $runtimeProviders,
    ) {
    }

    /**
     * 地区列表（树形结构）
     */
    #[Acl('Weline_Shipping::region_index', '查看地区', 'mdi-format-list-bulleted', '查看地区列表')]
    public function index()
    {
        // 获取所有已安装国家列表
        $countries = $this->countryRows();

        $countryCode = (string)$this->request->getParam('country_code', '');

        if (!$countryCode && !empty($countries)) {
            $countryCode = (string)($countries[0]['code'] ?? '');
        }

        $regionTree = [];
        if ($countryCode) {
            $regionTree = $this->regionService->getTreeByCountryCode($countryCode);
        }

        $this->assign('countries', $countries);
        $this->assign('current_country_code', $countryCode);
        $this->assign('region_tree', $regionTree);
        $this->assign('embed', ($this->request->getGet('embed') === '1' || $this->request->getGet('embed') === true));

        return $this->fetch();
    }

    /**
     * 地区编辑页（暂时简单保留占位，后续按需扩展）
     */
    #[Acl('Weline_Shipping::region_edit', '编辑地区', 'mdi-pencil', '编辑地区')]
    public function edit()
    {
        Message::warning(__('地区编辑功能暂未开放，请通过数据库或后续版本管理。'));
        $this->redirect('*/index');
    }

    /**
     * 从 i18n 同步国家数据
     */
    #[Acl('Weline_Shipping::region_sync', '同步国家数据', 'mdi-earth', '从i18n同步国家数据')]
    public function syncFromI18n()
    {
        try {
            // 获取所有已安装国家
            $countries = [];
            foreach ($this->countryRows() as $country) {
                $countries[] = [
                    'code' => $country['code'],
                    'name' => $country['code'],
                ];
            }

            $count = $this->regionService->syncFromI18n($countries);
            Message::success(__('同步完成，新增国家数量：%{1}', [$count]));
        } catch (\Throwable $e) {
            Message::error(__('同步失败：%{1}', [$e->getMessage()]));
        }

        $this->redirect('*/index');
    }

    /** @return list<array{code:string,name:string,flag:string,is_active:int}> */
    private function countryRows(): array
    {
        $repository = $this->runtimeProviders->resolve(CountryRepositoryInterface::class);
        if (!$repository instanceof CountryRepositoryInterface) {
            throw new \RuntimeException('Weline_I18n country repository provider is unavailable.');
        }

        $rows = [];
        foreach ($repository->installed(Cookie::getLangLocal()) as $country) {
            $rows[] = [
                'code' => $country->code,
                'name' => $country->displayName,
                'flag' => $country->flag,
                'is_active' => $country->active ? 1 : 0,
            ];
        }

        return $rows;
    }
}

