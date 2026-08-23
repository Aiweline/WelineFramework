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

class Local implements \Weline\Framework\Taglib\TaglibInterface
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
                $action = $request->getUrlBuilder()->getBackendUrl('i18n/backend/taglib/local', ['model' => $model, 'field' => $field, 'isIframe' => '1']);
            } else {
                $action = $request->getUrlBuilder()->getUrl('i18n/frontend/taglib/local', ['model' => $model, 'field' => $field]);
            }

            $closeText = __('关闭');
            $titileText = __('翻译窗口');
            $refreshText = __('刷新');
            $submitText = __('提交');
            return match ($tag_key) {
                'tag' => <<<TAG
                    <button type="button" class="w-button" aria-controls='{$idName}' data-w-target='#{$idName}' data-w-action="drawer.open" data-tone="quiet" data-size="sm">
                        <span>{$name}</span>
                        <w-icon name="language" size="sm"></w-icon>
                    </button>
                    <div class="w-drawer" tabindex='-1' id='{$idName}' aria-labelledby='{$idName}Label'
                         data-w-component="drawer local-translation" data-w-source="{$action}&value={$name}&id={$parserId}"
                         data-size="lg" data-state="closed" hidden aria-hidden="true">
                        <div class="w-drawer__header">
                            <h5 id='{$idName}Label'>
                                <lang>{$titileText}</lang>
                            </h5>
                            <div class="w-cluster" style="--w-gap: var(--weline-space-2);">
                                <button type='button' class="w-button" data-w-local-submit data-tone="primary" data-size="sm">
                                    <span class="w-spinner" data-w-local-spinner role="status" data-size="sm" hidden></span>
                                    <w-icon name="save" size="sm"></w-icon><span>{$submitText}</span>
                                </button>
                                <button type="button" class="w-button" data-w-local-refresh aria-label='{$refreshText}' data-tone="info" data-size="sm">
                                    <w-icon name="refresh" size="sm"></w-icon>{$refreshText}
                                </button>
                                <button type='button' class="w-button" aria-label='{$closeText}' data-w-action="drawer.close" data-w-close data-tone="quiet" data-size="sm"><w:icon name="close" size="sm"></w:icon></button>
                            </div>
                        </div>
                        <div class="w-drawer__body">
                            <iframe class="w-local-translation__frame" data-w-local-frame src="about:blank" title="{$titileText}"></iframe>
                        </div>
                    </div>
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
    public const fields_NAME = Store::schema_fields_NAME;
    public const fields_DESCRIPTION = Store::schema_fields_DESCRIPTION;
}
</pre>示例中，我们设置店铺的name字段可以翻译。还可以添加多个字段，比如店铺详情等，使用时指定字段即可。';
    }
}
