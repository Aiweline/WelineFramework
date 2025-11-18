<?php

namespace Weline\CKEditorEditorManager\EditorManager;

use Weline\EditorManager\EditorManager;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;

class CKEditor extends EditorManager
{
    public static function name(): string
    {
        return 'ckeditor';
    }

    /**
     * @inheritDoc
     */
    public function render(): string
    {
        # 分配Block
        $this->setData('class', \Weline\CKEditorEditorManager\Block\CKEditor::class);
        $this->setData('cache', 0);
        $lang = Cookie::getLang();
        $ckLangArr = explode('_', $lang);
        $ckLang = array_shift($ckLangArr);
        $this->setData('langJs', Template::getInstance()->fetchTagSource('statics', 'Weline_CKEditorEditorManager::build/translations/' . $ckLang . '.js'));
        $this->setData('ck_lang', $ckLang);
        return '<?php echo framework_view_process_block(' . w_var_export($this->getData(), true) . ');?>';
    }
}
