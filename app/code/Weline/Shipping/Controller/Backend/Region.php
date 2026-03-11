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
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\Message;
use Weline\I18n\Model\Countries;
use Weline\Shipping\Service\RegionService;

#[Acl('Weline_Shipping::region', '地区管理', 'mdi-map-marker', '地区管理', 'Weline_Backend::shipping_group')]
class Region extends BackendController
{
    private RegionService $regionService;
    private Countries $countries;

    public function __construct(ObjectManager $objectManager)
    {
        $this->regionService = $objectManager->getInstance(RegionService::class);
        $this->countries = $objectManager->getInstance(Countries::class);
    }

    /**
     * 地区列表（树形结构）
     */
    #[Acl('Weline_Shipping::region_index', '查看地区', 'mdi-format-list-bulleted', '查看地区列表')]
    public function index()
    {
        // 获取所有已安装国家列表
        $countries = $this->countries->reset()
            ->where(Countries::schema_fields_IS_INSTALL, 1)
            ->select()
            ->fetch()
            ->getItems();

        $countryCode = (string)$this->request->getParam('country_code', '');

        if (!$countryCode && !empty($countries)) {
            /** @var Countries $firstCountry */
            $firstCountry = $countries[0];
            $countryCode = $firstCountry->getData(Countries::schema_fields_CODE);
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
            $installedCountries = $this->countries->reset()
                ->where(Countries::schema_fields_IS_INSTALL, 1)
                ->select('code')
                ->fetch()
                ->getItems();

            $countries = [];
            foreach ($installedCountries as $country) {
                $countries[] = [
                    'code' => $country->getData(Countries::schema_fields_CODE),
                    'name' => $country->getData(Countries::schema_fields_CODE),
                ];
            }

            $count = $this->regionService->syncFromI18n($countries);
            Message::success(__('同步完成，新增国家数量：%{1}', [$count]));
        } catch (\Throwable $e) {
            Message::error(__('同步失败：%{1}', [$e->getMessage()]));
        }

        $this->redirect('*/index');
    }
}


