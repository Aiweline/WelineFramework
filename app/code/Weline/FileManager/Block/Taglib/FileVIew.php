<?php

namespace Weline\FileManager\Block\Taglib;

use Weline\FileManager\Helper\Image;
use Weline\Framework\Security\Token;

class FileVIew extends \Weline\Framework\View\Block
{
    protected string $_template = 'Weline_FileManager::image-preview/template-only-view.phtml';

    function __init()
    {
        parent::__init();
        $value = $this->getParseVarsParams('value');
        $this->assign('id', 'file-'.Token::random_string(32));
        $this->assign('value_items', Image::processImagesValuePreviewData($value, $this->getData('width'), $this->getData('height')));
    }
}