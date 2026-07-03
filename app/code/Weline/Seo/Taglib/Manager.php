<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Taglib;

use Weline\Taglib\TaglibInterface;
use Weline\Framework\Http\Url;

/**
 * SEO 管理嵌入式 Taglib
 * 
 * 用于在其他模块中嵌入 SEO 管理界面
 * 
 * 使用示例:
 * <w:seo:manager module="WeShop_Catalog" scope="catalog" height="600" />
 * 
 * @package Weline_Seo
 */
class Manager implements TaglibInterface
{
    public static function name(): string
    {
        return 'seo:manager';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [
            'module' => true,   // 必须：模块标识
            'scope' => false,   // 可选：业务 scope
            'id' => false,      // 可选：iframe ID
            'height' => false,  // 可选：iframe 高度，默认 100%
            'class' => false,   // 可选：容器 CSS 类
            'style' => false,   // 可选：容器行内样式
            'title' => false,   // 可选：标题文字
        ];
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $module = $attributes['module'] ?? '';
            $scope = $attributes['scope'] ?? '';
            $id = $attributes['id'] ?? 'seo_manager_' . uniqid();
            $height = $attributes['height'] ?? '100%';
            $class = $attributes['class'] ?? '';
            $style = $attributes['style'] ?? '';
            $title = $attributes['title'] ?? '';

            if (empty($module)) {
                return '<div class="alert alert-danger">' . __('SEO管理标签缺少 module 属性') . '</div>';
            }

            /** @var Url $url */
            $url = w_obj(Url::class);

            // 构建嵌入式 URL
            $params = ['module' => $module];
            if (!empty($scope)) {
                $params['scope'] = $scope;
            }

            // 解析属性值
            $code = \Weline\Taglib\Taglib::attributes($attributes);

            $html = [];
            $html[] = '<?php ' . $code . ' ?>';

            // 生成 iframe URL
            $embedUrl = $url->getBackendUrl('seo/backend/embed', $params);

            // 默认标题
            $defaultTitle = __('SEO管理');
            $titleText = !empty($title) ? $title : $defaultTitle;

            // 容器样式
            $containerStyle = 'border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden; background: #fff;';
            if (!empty($style)) {
                $containerStyle .= ' ' . htmlspecialchars($style);
            }

            // iframe 高度处理
            $iframeHeight = is_numeric($height) ? $height . 'px' : $height;

            $html[] = '<div class="seo-manager-container ' . htmlspecialchars($class) . '" style="' . $containerStyle . '">';
            
            // 可选标题栏
            if (!empty($title)) {
                $html[] = '  <div class="seo-manager-header" style="padding: 12px 16px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; display: flex; justify-content: space-between; align-items: center;">';
                $html[] = '    <h6 style="margin: 0; font-weight: 600;"><i class="mdi mdi-chart-line me-2"></i><?= htmlspecialchars($Taglib__title ?? \'' . addslashes($titleText) . '\') ?></h6>';
                $html[] = '    <a href="' . htmlspecialchars($embedUrl) . '" target="_blank" class="text-white" title="' . __('在新窗口打开') . '" style="opacity: 0.8;"><i class="mdi mdi-open-in-new"></i></a>';
                $html[] = '  </div>';
            }
            
            $html[] = '  <iframe';
            $html[] = '    id="<?= htmlspecialchars($Taglib__id ?? \'' . htmlspecialchars($id) . '\') ?>"';
            $html[] = '    src="' . htmlspecialchars($embedUrl) . '"';
            $html[] = '    style="width: 100%; height: ' . htmlspecialchars($iframeHeight) . '; border: none; display: block;"';
            $html[] = '    loading="lazy"';
            $html[] = '    title="<?= htmlspecialchars($Taglib__title ?? \'' . addslashes($titleText) . '\') ?>"';
            $html[] = '  ></iframe>';
            $html[] = '</div>';

            // 添加自适应高度脚本（可选）
            $html[] = '<script>';
            $html[] = '(function() {';
            $html[] = '  const iframe = document.getElementById("<?= htmlspecialchars($Taglib__id ?? \'' . htmlspecialchars($id) . '\') ?>");';
            $html[] = '  if (iframe && iframe.contentWindow) {';
            $html[] = '    window.addEventListener("message", function(e) {';
            $html[] = '      if (e.data && e.data.type === "seo_manager_height" && e.data.height) {';
            $html[] = '        iframe.style.height = e.data.height + "px";';
            $html[] = '      }';
            $html[] = '    });';
            $html[] = '  }';
            $html[] = '})();';
            $html[] = '</script>';

            return implode("\n", $html);
        };
    }

    public static function tag_self_close(): bool
    {
        return true;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    public static function parent(): ?string
    {
        return null;
    }

    public static function document(): string
    {
        $doc = <<<DOC
<h3><code>&lt;w:seo:manager&gt;</code> 使用文档</h3>
<p><strong>作用</strong>：在其他模块中嵌入 SEO 管理界面，实现多态 SEO 管理。</p>

<h4>属性说明</h4>
<table class="table table-bordered">
  <thead>
    <tr><th>属性</th><th>必填</th><th>说明</th></tr>
  </thead>
  <tbody>
    <tr><td><code>module</code></td><td>是</td><td>模块标识</td></tr>
    <tr><td><code>scope</code></td><td>否</td><td>业务 scope 标识</td></tr>
    <tr><td><code>id</code></td><td>否</td><td>iframe 元素 ID，默认自动生成</td></tr>
    <tr><td><code>height</code></td><td>否</td><td>iframe 高度，默认 100%，可传数字(px)或CSS值</td></tr>
    <tr><td><code>class</code></td><td>否</td><td>容器 CSS 类名</td></tr>
    <tr><td><code>style</code></td><td>否</td><td>容器行内样式</td></tr>
    <tr><td><code>title</code></td><td>否</td><td>标题文字，设置后会显示标题栏</td></tr>
  </tbody>
</table>

<h4>使用示例</h4>
<pre>
&lt;!-- 基础用法 --&gt;

&lt;!-- 带 scope 过滤 --&gt;

&lt;!-- 带标题和固定高度 --&gt;
&lt;w:seo:manager 
    module="WeShop_Catalog" 
    scope="catalog"
    title="商品目录 SEO 管理"
    height="600"
/&gt;

&lt;!-- 自定义样式 --&gt;
&lt;w:seo:manager 
    module="WeShop_Product" 
    class="my-seo-manager"
    style="box-shadow: 0 4px 12px rgba(0,0,0,0.1);"
/&gt;
</pre>

<h4>集成步骤</h4>
<ol>
  <li>在模块的 <code>etc/backend/menu.xml</code> 中添加 SEO 菜单项</li>
  <li>创建一个简单的控制器和视图，使用 <code>&lt;w:seo:manager&gt;</code> 标签</li>
  <li>SEO 数据会自动按 module/scope 过滤</li>
</ol>

<h4>注意事项</h4>
<ul>
  <li>嵌入式界面不含后台框架的 header/sidebar，适合 iframe 嵌入</li>
  <li>SEO 主体数据需要设置正确的 module 和 scope 才能被过滤显示</li>
  <li>支持 postMessage 自动调整 iframe 高度</li>
</ul>
DOC;

        return htmlspecialchars($doc, ENT_NOQUOTES);
    }
}
