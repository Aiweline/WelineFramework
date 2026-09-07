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
use Weline\Framework\Manager\Message;
use Weline\Shipping\Service\ShippingConfigurationAdminService;

#[Acl('Weline_Shipping::region', '地区管理', 'pin', '地区管理', 'Weline_Backend::shipping_group')]
class Region extends BackendController
{
    use ShippingBackendEmbedTrait;

    public function __construct(
        private readonly ShippingConfigurationAdminService $adminService,
    ) {
    }

    #[Acl('Weline_Shipping::region_index', '查看地区', 'list', '查看地区列表')]
    public function index()
    {
        // 地址目录与地区表一律不 SSR：仅透传 URL 选中国家码，列表由 JS 调 region/list 异步填充。
        $selectedCountryCodes = $this->resolveSelectedCountryCodesFromRequest();
        $this->assign('current_country_code', $selectedCountryCodes[0] ?? '');
        $this->assign('current_country_codes', $selectedCountryCodes);
        $this->assignShippingEmbedLayout();
        return $this->fetch();
    }

    /**
     * @return list<string>
     */
    private function resolveSelectedCountryCodesFromRequest(): array
    {
        $rawCodes = trim((string)$this->request->getParam('country_codes', ''));
        $codes = [];
        if ($rawCodes !== '') {
            foreach (preg_split('/[,\s]+/', $rawCodes) ?: [] as $part) {
                $code = strtoupper(trim((string)$part));
                if (preg_match('/^[A-Z]{2}$/D', $code) === 1) {
                    $codes[$code] = $code;
                }
            }
        }
        if ($codes === []) {
            $single = strtoupper(trim((string)$this->request->getParam('country_code', '')));
            if (preg_match('/^[A-Z]{2}$/D', $single) === 1) {
                $codes[$single] = $single;
            }
        }
        // 无 URL 参数时默认中国，避免 global 目录字母序落到 AR（阿根廷）等
        if ($codes === []) {
            $codes['CN'] = 'CN';
        }

        return array_values($codes);
    }

    #[Acl('Weline_Shipping::region_edit', '编辑地区', 'edit', '编辑地区')]
    public function edit()
    {
        Message::warning(__('地区编辑功能暂未开放，请通过数据库或后续版本管理。'));
        $this->redirect('*/index');
    }

    #[Acl('Weline_Shipping::region_save', '保存地区', 'save', '创建地区')]
    public function save()
    {
        $redirectCountry = strtoupper(trim((string)$this->request->getPost('country_code', '')));
        $embed = $this->request->getPost('embed') === '1' || $this->request->getPost('embed') === 1;
        try {
            if (!$this->request->isPost()) {
                throw new \InvalidArgumentException((string)__('仅允许 POST 请求。'));
            }
            $post = (array)$this->request->getPost();
            $mode = strtolower(trim((string)($post['create_mode'] ?? '')));
            if ($mode === 'manual_path'
                || isset($post['add_province_name'])
                || isset($post['add_district_name'])
                || isset($post['province_name'])
                || isset($post['district_name'])) {
                $result = $this->adminService->createManualProvinceDistrict($post);
                $redirectCountry = (string)($result['primary_country'] ?: $redirectCountry);
                $created = (int)$result['created'];
                $this->getMessageManager()->addSuccess(__('已新增 %{1} 个地区。', [$created]));
            } else {
                $region = $this->adminService->createRegion($post);
                $redirectCountry = strtoupper(trim((string)$region->getData(\Weline\Shipping\Model\Region::schema_fields_COUNTRY_CODE)));
                $this->getMessageManager()->addSuccess(__('地区创建成功。'));
            }
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }

        $params = [];
        if (preg_match('/^[A-Z]{2}$/', $redirectCountry) === 1) {
            $params['country_code'] = $redirectCountry;
        }
        if ($embed) {
            $params['embed'] = 1;
        }

        return $this->redirect('shipping/backend/region/index', $params);
    }
}
