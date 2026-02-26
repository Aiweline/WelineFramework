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
use Weline\Framework\Runtime\StateManager;
use Weline\Framework\View\Taglib;

class Local implements \Weline\Taglib\TaglibInterface
{
    private static array $ids = [];
    private static bool $stateRegistered = false;
    
    private static function ensureStateRegistered(): void
    {
        if (!self::$stateRegistered) {
            StateManager::registerStaticResets(self::class, [
                'ids' => [],
            ]);
            self::$stateRegistered = true;
        }
    }

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
        self::ensureStateRegistered();
        $ids = &self::$ids;
        return function ($tag_key, $config, $tag_data, $attributes) use (&$ids) {
            # 这里可以做任何处理，然后返回对应处理后的内容
            $model = $attributes['model'];
            $field = $attributes['field'];
            /**@var Taglib $Taglib */
            $Taglib = ObjectManager::getInstance(Taglib::class);
            $origin_id = $attributes['id'];
            $parserId = '<?=(' . $Taglib->varParser($origin_id) . '?:\'' . str_replace('.', '-', $origin_id) . '\')?>';
            $idName = 'local-off-canvas-' . $parserId . '-' . $field;
            if (in_array($idName, $ids)) {
                throw new Exception('local标签ID不允许重复！');
            }
            $ids[] = $idName;
            $name = trim($tag_data[2] ?? '');
            /**@var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            if ($request->isBackend()) {
                $action = $request->getUrlBuilder()->getBackendUrl('i18n/backend/taglib/local', ['model' => $model, 'field' => $field]);
            } else {
                $action = $request->getUrlBuilder()->getUrl('i18n/frontend/taglib/local', ['model' => $model, 'field' => $field]);
            }

            $closeText = __('关闭');
            $titileText = __('翻译窗口');
            $refreshText = __('刷新');
            $submitText = __('提交');
            return match ($tag_key) {
                'tag' => <<<TAG
                    <a class='d-flex align-items-center link-info gap-1 local-translation-link' style='cursor: pointer'
                        data-bs-toggle='offcanvas'
                        data-bs-target='#{$idName}' 
                        aria-controls='{$idName}'
                        data-href='{$action}&value={$name}&id={$parserId}'
                        data-i18n-local-translation-link>
                        <span>{$name}</span>
                        <i class='ri-translate'></i>
                    </a>
                    <!-- {$idName} -->
                    <div class='offcanvas  offcanvas-end w-75 h-100' tabindex='-1' id='{$idName}' 
                         aria-labelledby='{$idName}Label'>
                        <div class='offcanvas-header'>
                            <h5 id='{$idName}Label'>
                                <lang>{$titileText}</lang>
                            </h5>
                            <div class="d-flex gap-2 ms-auto">
                                <button id="{$idName}SubmitBtn" type='submit' class='btn btn-primary btn-sm'>
                                    <i class="ri-save-line me-1"></i>{$submitText}
                                </button>
                                <a id="{$idName}IframeRefreshBtn" class='btn btn-info btn-sm' 
                                   aria-label='{$refreshText}'>
                                    <i class="ri-refresh-line me-1"></i>{$refreshText}
                                </a>
                                <button type='button' class='btn-close btn-sm' data-bs-dismiss='offcanvas'
                                        aria-label='{$closeText}'></button>
                            </div>
                        </div>
                        <div class='offcanvas-body'>
                            <div class='position-relative w-100 h-100 '>
                                <iframe id='{$idName}Iframe' class='w-100 h-100'
                                        data-src="{$action}&value={$name}&id={$parserId}"
                                        frameborder='0'></iframe>
                            </div>
                        </div>
                    </div>
                    <script>
                        //show.bs.offcanvas
                        $('#{$idName}').on('show.bs.offcanvas', function (e) {
                            let Iframe = $('#{$idName}Iframe')
                            Iframe.attr('src', Iframe.attr('data-src'))
                        })
                        $('#{$idName}IframeRefreshBtn').on('click', function (e) {
                            let Iframe = $('#{$idName}Iframe')
                            Iframe.attr('src', Iframe.attr('data-src'))
                        })
                        // 提交按钮点击事件
                        $('#{$idName}SubmitBtn').on('click', function (e) {
                            const btn = $(this);
                            const Iframe = $('#{$idName}Iframe');
                            const iframeDoc = Iframe[0].contentWindow?.document;

                            if (!iframeDoc) {
                                console.error('Iframe document not accessible');
                                return;
                            }
                            // 获取id为localTranslationForm的表单元素
                            const form = iframeDoc.getElementById('localTranslationForm');
                            if (!form) {
                                console.error('Form not found in iframe');
                                return;
                            }
                            
                            // 防止重复提交
                            btn.prop('disabled', true);
                            btn.html(`<span class="spinner-border spinner-border-sm me-1" role="status"></span>\${btn.text()}`);

                            try {
                                form.submit();

                                // 监听iframe加载完成事件
                                Iframe.on('load', function() {
                                    btn.prop('disabled', false);
                                    btn.html(`<i class="ri-save-line me-1"></i>{$submitText}`);
                                });
                            } catch (error) {
                                console.error('Form submit error:', error);
                                btn.prop('disabled', false);
                                btn.html(`<i class="ri-save-line me-1"></i>{$submitText}`);
                            }
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

    /**
     * 指定父标签，用于依赖管理
     * @return string|null 父标签名称
     */
    static function parent(): ?string
    {
        return null; // Local标签没有依赖
    }

    static function document(): string
    {
        return '翻译标签，使用Model继承 Weline\I18n\LocalModel.然后使用。示例：' . htmlentities('<local model="Weline\Store\Model\StoreDescription" field="name" id="store.store_id" name="store-name"></local>') . ' 其中 Weline\Store\Model\Store 继承 Weline\I18n\LocalModel。
<pre>
class StoreDescription extends \Weline\I18n\LocalModel
{
    public const indexer = \'store_local_description\';
    public const fields_ID = \'store_id\';
    public const fields_NAME = Store::fields_NAME;
    public const fields_DESCRIPTION = Store::fields_DESCRIPTION;
}
</pre>示例中，我们设置店铺的name字段可以翻译。<span style="color:red;">除了那么，还可以添加多个字段，比如店铺详情等，使用时指定字段即可。</span>';
    }
}