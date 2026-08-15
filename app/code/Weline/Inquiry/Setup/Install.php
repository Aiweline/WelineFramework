<?php

declare(strict_types=1);

namespace Weline\Inquiry\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;
use Weline\Inquiry\Model\Form;
use Weline\Inquiry\Service\FormVersionService;

final class Install implements InstallInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        $form = ObjectManager::getInstance(Form::class);
        $existing = $form->reset()->where(Form::schema_fields_CODE, 'motorcycle-dealer-quote')->select()->fetchArray();
        if (($existing[0][Form::schema_fields_STATUS] ?? '') === Form::STATUS_PUBLISHED) { return; }
        $service = ObjectManager::getInstance(FormVersionService::class);
        $draft = $service->saveDraft([
            'form_id' => (int)($existing[0][Form::schema_fields_ID] ?? 0), 'code' => 'motorcycle-dealer-quote', 'name' => 'Motorcycle dealer quote', 'default_locale' => 'en_US',
            'schema' => ['fields' => [
                ['key' => 'full_name', 'type' => 'text', 'required' => true], ['key' => 'email', 'type' => 'email', 'required' => true], ['key' => 'company', 'type' => 'text', 'required' => true], ['key' => 'country', 'type' => 'text', 'required' => true],
                ['key' => 'business_type', 'type' => 'select', 'required' => true, 'options' => [['value' => 'dealer'], ['value' => 'distributor'], ['value' => 'oem_odm'], ['value' => 'other']]],
                ['key' => 'models', 'type' => 'textarea', 'required' => true], ['key' => 'annual_volume', 'type' => 'number', 'required' => false], ['key' => 'message', 'type' => 'textarea', 'required' => false],
            ]],
            'translations' => [
                'en_US' => ['title' => 'Request a dealer quotation', 'description' => 'Tell us the motorcycle models and market you serve.', 'submit_label' => 'Request quotation', 'success_message' => 'Thank you. Our export team will contact you shortly.', 'fields' => ['full_name' => ['label' => 'Full name'], 'email' => ['label' => 'Business email'], 'company' => ['label' => 'Company'], 'country' => ['label' => 'Country / region'], 'business_type' => ['label' => 'Business type', 'options' => ['dealer' => 'Dealer', 'distributor' => 'Distributor', 'oem_odm' => 'OEM / ODM', 'other' => 'Other']], 'models' => ['label' => 'Interested models'], 'annual_volume' => ['label' => 'Expected annual volume'], 'message' => ['label' => 'Additional requirements']]],
                'zh_Hans_CN' => ['title' => '获取经销商报价', 'description' => '告诉我们您关注的车型与目标市场。', 'submit_label' => '提交报价需求', 'success_message' => '感谢您的咨询，出口团队将尽快联系您。', 'fields' => ['full_name' => ['label' => '姓名'], 'email' => ['label' => '商务邮箱'], 'company' => ['label' => '公司名称'], 'country' => ['label' => '国家 / 地区'], 'business_type' => ['label' => '业务类型', 'options' => ['dealer' => '经销商', 'distributor' => '分销商', 'oem_odm' => 'OEM / ODM', 'other' => '其他']], 'models' => ['label' => '意向车型'], 'annual_volume' => ['label' => '预计年采购量'], 'message' => ['label' => '其他需求']]],
            ],
        ]);
        $service->publish((int)$draft['form']['form_id']);
    }
}
