<?php

namespace Weline\FileManager\Api\Block;

use Weline\FileManager\Api\Image;
use Weline\Framework\View\Block;

class FileManager extends Block
{
    public function render(): string
    {
        $value = $this->getParseVarsParams('value');
        $this->assign('value', $value ?: $this->getData('value'));
        // preview 默认为 '1'，只要不是显式设置为 false/'0'/0 就渲染预览容器
        $preview = $this->getData('preview');
        $showPreview = ($preview !== false && $preview !== '0' && $preview !== 0) ? '1' : '';
        $this->assign('preview', $showPreview);
        $size_alias = Image::getSize($this->getData('size'));
        $value = $this->getData('value') ?: '';
        $this->assign('value_items', Image::processImagesValuePreviewData($value, $this->getData('width'), $this->getData('height')));
        $this->assign('size_alias', $size_alias);
        $this->assign('params', $this->getParams());
        return parent::render();
    }

    public function getParams()
    {
        return [
            'isIframe' => true,
            'target' => $this->getData('target'),
            'preview' => $this->getData('preview'),
            'setAttr' => $this->getData('setAttr'),
            'close' => $this->getData('close'),
            'startPath' => $this->getData('path'),
            'lockPath' => $this->getData('lockPath'),
            'multi' => $this->getData('multi'),
            'ext' => $this->getData('ext'),
            'size' => $this->getData('size'),
        ];
    }

    public function doc()
    {
        return \Weline\FileManager\Taglib\FileManager::document();
    }
}
