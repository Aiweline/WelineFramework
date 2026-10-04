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
    public const CODE_CONTACT = 'contact';

    /**
     * 站点「联系我们」通用表单：姓名 / 邮箱 / 主题 / 订单号 / 留言 / 是否附图。
     * 前台主题表单以 code=contact 提交，提交记录在后台「询盘提交记录」可查。
     */
    public function ensureContact(): void
    {
        $form = ObjectManager::getInstance(Form::class);
        $existing = $form->reset()
            ->where(Form::schema_fields_CODE, self::CODE_CONTACT)
            ->select()
            ->fetchArray();
        if (($existing[0][Form::schema_fields_STATUS] ?? '') === Form::STATUS_PUBLISHED) {
            return;
        }

        $service = ObjectManager::getInstance(FormVersionService::class);
        $draft = $service->saveDraft([
            'form_id' => (int)($existing[0][Form::schema_fields_ID] ?? 0),
            'code' => self::CODE_CONTACT,
            'name' => 'Contact us',
            'default_locale' => 'en_US',
            'schema' => [
                'fields' => [
                    ['key' => 'name', 'type' => 'text', 'required' => true],
                    ['key' => 'email', 'type' => 'email', 'required' => true],
                    [
                        'key' => 'topic',
                        'type' => 'select',
                        'required' => true,
                        'options' => [
                            ['value' => 'order_delivery'],
                            ['value' => 'returns_refunds'],
                            ['value' => 'product_question'],
                            ['value' => 'wholesale_business_press'],
                            ['value' => 'other'],
                        ],
                    ],
                    ['key' => 'order_ref', 'type' => 'text', 'required' => false],
                    ['key' => 'message', 'type' => 'textarea', 'required' => true],
                    ['key' => 'attach_photos', 'type' => 'checkbox', 'required' => false],
                ],
            ],
            'translations' => [
                'en_US' => [
                    'title' => 'Send us a message',
                    'description' => 'Share as much detail as you can so we can better assist you.',
                    'submit_label' => 'Send message',
                    'success_message' => 'Thank you. Our team will get back to you shortly.',
                    'fields' => [
                        'name' => ['label' => 'Your name'],
                        'email' => ['label' => 'Your email'],
                        'topic' => [
                            'label' => 'Topic',
                            'options' => [
                                'order_delivery' => 'Order & delivery',
                                'returns_refunds' => 'Returns & refunds',
                                'product_question' => 'Product question',
                                'wholesale_business_press' => 'Wholesale, business & press',
                                'other' => 'Something else',
                            ],
                        ],
                        'order_ref' => ['label' => 'Order number (optional)'],
                        'message' => ['label' => 'Message'],
                        'attach_photos' => ['label' => 'Attach photos (optional)'],
                    ],
                ],
                'zh_Hans_CN' => [
                    'title' => '发送消息',
                    'description' => '请尽量写清背景，方便我们一次处理到位。',
                    'submit_label' => '发送消息',
                    'success_message' => '感谢您的留言，我们会尽快回复。',
                    'fields' => [
                        'name' => ['label' => '姓名'],
                        'email' => ['label' => '邮箱'],
                        'topic' => [
                            'label' => '主题',
                            'options' => [
                                'order_delivery' => '订单与配送',
                                'returns_refunds' => '退换与退款',
                                'product_question' => '商品咨询',
                                'wholesale_business_press' => '批发、商务与媒体',
                                'other' => '其它',
                            ],
                        ],
                        'order_ref' => ['label' => '订单号（选填）'],
                        'message' => ['label' => '留言'],
                        'attach_photos' => ['label' => '附上照片（选填）'],
                    ],
                ],
            ],
        ]);
        $service->publish((int)$draft['form']['form_id']);
    }

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
