<?php

namespace Weline\CKEditorEditorManager\EditorManager;

use Weline\EditorManager\Api\Editor\EditorManager;
use Weline\Framework\Http\Cookie;
use Weline\Framework\View\Template;

class CKEditor extends EditorManager
{
    private const LANGUAGE_FILES = [
        'ar' => 'ar',
        'de' => 'de',
        'en' => 'en',
        'es' => 'es',
        'fr' => 'fr',
        'ja' => 'ja',
        'ko' => 'ko',
        'pt' => 'pt-br',
        'ru' => 'ru',
        'th' => 'th',
        'vi' => 'vi',
        'zh' => 'zh',
    ];

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
        $language = strtolower(str_replace('_', '-', Cookie::getLang()));
        $languagePrefix = explode('-', $language, 2)[0];
        $ckLanguage = self::LANGUAGE_FILES[$languagePrefix] ?? 'en';
        $template = Template::getInstance();
        $this->setData('language_js', $template->fetchTagSource(
            'statics',
            'Weline_CKEditorEditorManager::build/translations/' . $ckLanguage . '.js'
        ));
        $this->setData('engine_js', $template->fetchTagSource(
            'statics',
            'Weline_CKEditorEditorManager::build/ckeditor.js'
        ));
        $this->setData('ck_language', $ckLanguage);
        return '<?php echo framework_view_process_block(' . w_var_export($this->getData(), true) . ');?>';
    }
}
