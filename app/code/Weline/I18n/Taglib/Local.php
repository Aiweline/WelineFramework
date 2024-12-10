<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/7/1 13:12:38
 */

namespace Weline\I18n\Taglib;

use TheSeer\Tokenizer\Exception;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Taglib;

class Local implements \Weline\Taglib\TaglibInterface
{
    private $ids = [];

    /**
     * @inheritDoc
     */
    static public function name(): string
    {
        return 'local';
    }

    /**
     * @inheritDoc
     */
    static function tag(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    static function attr(): array
    {
        return ['model' => true, 'id' => true, 'field' => true];
    }

    /**
     * @inheritDoc
     */
    static function tag_start(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    static function tag_end(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    static function callback(): callable
    {
        $ids = [];
        return function ($tag_key, $config, $tag_data, $attributes) use ($ids) {
            # 这里可以做任何处理，然后返回对应处理后的内容
            $model = $attributes['model'];
            $field = $attributes['field'];
            /**@var Taglib $Taglib */
            $Taglib    = ObjectManager::getInstance(Taglib::class);
            $origin_id = $attributes['id'];
            $parserId = '<?=(' . $Taglib->varParser($origin_id) . '?:\'' . str_replace('.', '-', $origin_id) . '\')?>';
            $id        = 'local-off-canvas-'.$parserId;
            if (in_array($id, $ids)) {
                throw new Exception('local标签ID不允许重复！');
            }
            $ids[] = $id;

            $name = trim($tag_data[2] ?? '');
            /**@var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            if ($request->isBackend()) {
                $action = $request->getUrlBuilder()->getBackendUrl('i18n/backend/taglib/local', ['model' => $model, 'field' => $field]);
            } else {
                $action = $request->getUrlBuilder()->getUrl('i18n/taglib/local', ['model' => $model, 'field' => $field]);
            }
            $closeText  = __('关闭');
            $titileText = __('翻译窗口');
            return match ($tag_key) {
                'tag' => <<<TAG
                    <a class='d-flex align-items-center link-info gap-1' style='cursor: pointer'
                        data-bs-toggle='offcanvas'
                        data-bs-target='#{$id}' 
                        aria-controls='{$id}'
                        <span>{$name}</span>
                        <i class='ri-translate'></i>
                    </a>
                    <!-- {$id} -->
                    <div class='offcanvas  offcanvas-end w-75 h-100' tabindex='-1' id='{$id}' 
                         aria-labelledby='{$id}Label'>
                        <div class='offcanvas-header'>
                            <h5 id='{$id}Label'>
                                <lang>{$titileText}</lang>
                            </h5>
                            <button type='button' class='btn-close text-reset' data-bs-dismiss='offcanvas'
                                        aria-label='{$closeText}'></button>
                        </div>
                        <div class='offcanvas-body'>
                            <div class='position-relative w-100 h-100 '>
                                <iframe id='{$id}Iframe' class='w-100 h-100'
                                        data-src="{$action}&value={$name}&id={$parserId}"
                                        frameborder='0'></iframe>
                            </div>
                        </div>
                    </div>
                    <script>
                        //show.bs.offcanvas
                        $('#{$id}').on('show.bs.offcanvas', function (e) {
                            console.log(e.target)
                            let Iframe = $('#{$id}Iframe')
                            Iframe.attr('src', Iframe.attr('data-src'))
                        })
                    </script>
TAG,
            };
        };
    }

    /**
     * @inheritDoc
     */
    static function tag_self_close(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    static function tag_self_close_with_attrs(): bool
    {
        return false;
    }

    static function document(): string
    {
        return '翻译标签，使用Model继承 Weline\I18n\LocalModel.然后使用。示例：' . htmlentities('<local model="Weline\Demo\Model\Demo"></local>') . ' 其中 Weline\Demo\Model\Demo 继承Weline\I18n\LocalModel。';
    }
}