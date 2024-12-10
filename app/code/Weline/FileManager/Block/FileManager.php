<?php

namespace Weline\FileManager\Block;

use Weline\FileManager\FileManagerInterface;
use Weline\FileManager\Helper\Image;
use Weline\Framework\View\Block;

class FileManager extends Block
{
    public function render(): string
    {
        $value = $this->getParseVarsParams('value');
        $this->assign('value', $value?:$this->getData('value'));
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
            'setAttr' => $this->getData('setAttr'),
            'close' => $this->getData('close'),
            'startPath' => $this->getData('path'),
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
