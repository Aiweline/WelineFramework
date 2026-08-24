<?php

declare(strict_types=1);

return [
    'inquiry_form' => [
        'name' => 'Inquiry form',
        'description' => 'Embed any published Weline_Inquiry form without PageBuilder.',
        'type' => 'content',
        'code' => 'inquiry_form',
        'area' => 'frontend',
        'template' => 'Weline_Inquiry::templates/widgets/inquiry-form.phtml',
        'params' => [
            'inquiry_code' => ['label' => 'Inquiry form', 'type' => 'query_select', 'query_provider' => 'inquiry', 'query_operation' => 'searchPublished', 'value_key' => 'code', 'label_key' => 'name', 'required' => true, 'group' => 'basic'],
            'mode' => ['label' => 'Display mode', 'type' => 'select', 'options' => [['value' => 'inline', 'label' => 'Inline'], ['value' => 'modal', 'label' => 'Modal'], ['value' => 'trigger', 'label' => 'Trigger']], 'default' => 'inline', 'group' => 'basic'],
            'trigger_selector' => ['label' => 'External trigger ID / selector', 'type' => 'string', 'group' => 'advanced'],
            'instance_id' => ['label' => 'Instance ID', 'type' => 'string', 'group' => 'advanced'],
            'custom_css' => ['label' => 'Instance CSS', 'type' => 'textarea', 'group' => 'style'],
            'custom_js' => ['label' => 'Trusted instance JS', 'type' => 'textarea', 'group' => 'advanced', 'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Inquiry::trusted_js']],
        ],
    ],
];
