<?php

declare(strict_types=1);

namespace Weline\Inquiry\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Inquiry\Model\Form;

/**
 * 种子并发布内置询盘表单（安装/升级共用）。
 */
final class InquiryFormBootstrap
{
    public const CODE_SUPPLIER_APPLICATION = 'supplier-application';

    public function ensureSupplierApplication(): void
    {
        $form = ObjectManager::getInstance(Form::class);
        $service = ObjectManager::getInstance(FormVersionService::class);
        $existing = $form->reset()
            ->where(Form::schema_fields_CODE, self::CODE_SUPPLIER_APPLICATION)
            ->select()
            ->fetchArray();
        $formId = (int)($existing[0][Form::schema_fields_ID] ?? 0);

        if ($formId > 0 && ($existing[0][Form::schema_fields_STATUS] ?? '') === Form::STATUS_PUBLISHED) {
            $state = $service->draft($formId);
            $hasGlobalCountry = false;
            $hasDistrictCascade = false;
            $companionKeys = [];
            foreach ((array)($state['schema']['fields'] ?? []) as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $key = (string)($field['key'] ?? '');
                if ($key === 'country' && ($field['type'] ?? '') === 'country') {
                    $catalog = strtolower(trim((string)(($field['validation'] ?? [])['catalog'] ?? '')));
                    $levels = strtolower(trim((string)(($field['validation'] ?? [])['levels'] ?? '')));
                    $hasGlobalCountry = ($catalog === 'global');
                    $hasDistrictCascade = str_contains($levels, 'district');
                }
                if (in_array($key, ['province', 'city', 'district'], true)) {
                    $companionKeys[$key] = true;
                }
            }
            if ($hasGlobalCountry && $hasDistrictCascade && count($companionKeys) === 3) {
                return;
            }
        }

        $draft = $service->saveDraft([
            'form_id' => $formId,
            'code' => self::CODE_SUPPLIER_APPLICATION,
            'name' => 'Supplier application',
            'default_locale' => 'zh_Hans_CN',
            'schema' => [
                'fields' => [
                    ['key' => 'company', 'type' => 'text', 'required' => true],
                    ['key' => 'contact_name', 'type' => 'text', 'required' => true],
                    ['key' => 'email', 'type' => 'email', 'required' => true],
                    ['key' => 'phone', 'type' => 'text', 'required' => true],
                    [
                        'key' => 'country',
                        'type' => 'country',
                        'required' => true,
                        'validation' => [
                            'catalog' => 'global',
                            'levels' => 'country|province|city|district',
                            'selection' => 'single',
                        ],
                    ],
                    ['key' => 'province', 'type' => 'text', 'required' => false],
                    ['key' => 'city', 'type' => 'text', 'required' => false],
                    ['key' => 'district', 'type' => 'text', 'required' => false],
                    [
                        'key' => 'supply_type',
                        'type' => 'select',
                        'required' => true,
                        'options' => [
                            ['value' => 'manufacturer'],
                            ['value' => 'brand'],
                            ['value' => 'distributor'],
                            ['value' => 'other'],
                        ],
                    ],
                    ['key' => 'categories', 'type' => 'textarea', 'required' => true],
                    ['key' => 'message', 'type' => 'textarea', 'required' => false],
                ],
            ],
            'translations' => [
                'zh_Hans_CN' => [
                    'title' => '供应商申请',
                    'description' => '填写企业与品类信息，我们会评估合作意向并尽快回复。',
                    'submit_label' => '提交申请',
                    'success_message' => '感谢您的申请，采购与招商团队将尽快联系您。',
                    'fields' => [
                        'company' => ['label' => '公司名称'],
                        'contact_name' => ['label' => '联系人'],
                        'email' => ['label' => '商务邮箱'],
                        'phone' => ['label' => '联系电话'],
                        'country' => [
                            'label' => '国家 / 地区',
                            'levels' => [
                                'province' => '省份',
                                'city' => '城市',
                                'district' => '区县',
                            ],
                        ],
                        'province' => ['label' => '省份'],
                        'city' => ['label' => '城市'],
                        'district' => ['label' => '区县'],
                        'supply_type' => [
                            'label' => '供应类型',
                            'options' => [
                                'manufacturer' => '制造商',
                                'brand' => '品牌方',
                                'distributor' => '经销商 / 代理',
                                'other' => '其他',
                            ],
                        ],
                        'categories' => ['label' => '主营品类'],
                        'message' => ['label' => '补充说明'],
                    ],
                ],
                'en_US' => [
                    'title' => 'Supplier application',
                    'description' => 'Tell us about your company and catalog. Our sourcing team will follow up.',
                    'submit_label' => 'Submit application',
                    'success_message' => 'Thank you. Our sourcing team will contact you shortly.',
                    'fields' => [
                        'company' => ['label' => 'Company'],
                        'contact_name' => ['label' => 'Contact name'],
                        'email' => ['label' => 'Business email'],
                        'phone' => ['label' => 'Phone'],
                        'country' => [
                            'label' => 'Country / region',
                            'levels' => [
                                'province' => 'Province',
                                'city' => 'City',
                                'district' => 'District',
                            ],
                        ],
                        'province' => ['label' => 'Province'],
                        'city' => ['label' => 'City'],
                        'district' => ['label' => 'District'],
                        'supply_type' => [
                            'label' => 'Supply type',
                            'options' => [
                                'manufacturer' => 'Manufacturer',
                                'brand' => 'Brand',
                                'distributor' => 'Distributor / agent',
                                'other' => 'Other',
                            ],
                        ],
                        'categories' => ['label' => 'Product categories'],
                        'message' => ['label' => 'Additional notes'],
                    ],
                ],
            ],
        ]);
        $service->publish((int)$draft['form']['form_id']);
    }
}
