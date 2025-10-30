<?php
declare(strict_types=1);

namespace Weline\Ai\Block;

use Weline\Framework\View\Block as ViewBlock;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Http\Url;

class ModelSelect extends ViewBlock
{
    protected string $_template = 'Weline_Ai::Block/model-select.phtml';

    public function __init(): void
    {
        parent::__init();
        // 从 vars 中解析传参
        $params = $this->getParseVarsParams('var-params', []);
        if (!is_array($params)) {
            $params = [];
        }

        $name = (string)($params['name'] ?? $this->getData('name') ?? 'model_code');
        $value = (string)($params['value'] ?? $this->getData('value') ?? '');
        $display = (string)($params['display'] ?? $this->getData('display') ?? '');
        $serviceType = (string)($params['service_type'] ?? $this->getData('service_type') ?? 'default');
        $placeholder = (string)($this->getData('placeholder') ?? __('搜索AI模型...'));
        $limit = (int)($this->getData('limit') ?? 50);
        $classNames = (string)($this->getData('class') ?? 'w-100');
        $style = (string)($this->getData('style') ?? '');

        /** @var Url $url */
        $url = ObjectManager::getInstance(Url::class);
        $endpoint = (string)($this->getData('endpoint') ?? '*/backend/api/models');
        $api = $url->getBackendUrl($endpoint);

        $id = 'ms_' . md5($name . '_' . $serviceType . '_' . __CLASS__);

        $this->assign([
            'id' => $id,
            'name' => $name,
            'value' => $value,
            'display' => $display ?: $value,
            'service_type' => $serviceType,
            'placeholder' => $placeholder,
            'limit' => $limit,
            'class' => $classNames,
            'style' => $style,
            'api' => $api,
        ]);
    }
}


