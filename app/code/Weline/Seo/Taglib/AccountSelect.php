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
 * SEO 账户选择标签
 *
 * 使用示例：
 * <w:seo:account:select
 *     id="seo_account"
 *     name="seo_account_id"
 *     value="accountId|''"
 *     display="accountName|'请选择SEO账户'"
 *     class="w-100"
 * />
 */
class AccountSelect implements TaglibInterface
{
    public static function name(): string
    {
        // 模板中使用 <w:seo:account:select .../>
        return 'seo:account:select';
    }

    public static function tag(): bool
    {
        return false; // 自闭合
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
            'id' => true,
            'name' => true,
            'value' => true,
            'display' => true,
            'class' => false,
            'style' => false,
            'limit' => false,
            'url' => false,
        ];
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            if (empty($attributes['id'])) {
                throw new \Exception(__('id属性不能为空'));
            }
            
            $placeholder = $attributes['placeholder'] ?? __('搜索SEO账户...');
            $class = $attributes['class'] ?? '';
            $style = $attributes['style'] ?? '';
            $limit = (int)($attributes['limit'] ?? 50);
            $urlPath = $attributes['url'] ?? 'seo/backend/api/seo/accounts';
            
            /** @var Url $url */
            $url = w_obj(Url::class);
            $epUrl = $url->getBackendUrl($urlPath);
            // 使用后台 URL 路径（getBackendUrlPath），相对当前页 origin 请求，避免 getBaseHost() 未带端口时请求到错误端口
            $epPath = $url->getBackendUrlPath($urlPath);

            $attributes['url'] = $epUrl;
            $attributes['limit'] = $limit;
            
            // 提取 data 属性
            $dataAttrs = [];
            foreach ($attributes as $key => $val) {
                if (strpos($key, 'data-') === 0) {
                    $dataAttrs[$key] = $val;
                }
            }
            
            // 解析所有属性
            $code = \Weline\Taglib\Taglib::attributes($attributes);
            $html = [];
            $html[] = '<?php ' . $code . ' ?>';

            // 生成 data 属性字符串
            $dataAttrsStr = '';
            if (!empty($dataAttrs)) {
                $dataParts = [];
                foreach ($dataAttrs as $key => $val) {
                    $cleanKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $key) ?? $key;
                    $dataKey = str_replace('data-', '', $key);
                    $dataParts[] = 'data-' . htmlspecialchars($dataKey) . '="<?= htmlspecialchars($Taglib__' . $cleanKey . ' ?? \'\') ?>"';
                }
                $dataAttrsStr = ' ' . implode(' ', $dataParts);
            }
            
            // 输出 HTML
            $html[] = '<div class="position-relative seo-account-select ' . htmlspecialchars($class) . '" style="' . htmlspecialchars($style) . '"' . $dataAttrsStr . '>';
            $html[] = '  <button type="button" class="btn btn-outline-secondary w-100 text-start" id="<?= htmlspecialchars($Taglib__id) ?>_trigger" style="height: 38px;">';
            $html[] = '    <i class="mdi mdi-account-search me-1"></i>';
            $html[] = '    <span id="<?= htmlspecialchars($Taglib__id) ?>_display"><?php if($Taglib__display!==' . "''" . '): echo htmlspecialchars($Taglib__display); else: ?>' . htmlspecialchars(__('请选择SEO账户')) . '<?php endif; ?></span>';
            $html[] = '  </button>';
            $html[] = '  <div id="<?= htmlspecialchars($Taglib__id) ?>_container" class="bg-white border rounded shadow-sm" style="display:none; position:absolute; left:0; right:0; top:0; z-index:1060; padding: 0.75rem;"' . $dataAttrsStr . '>';
            $html[] = '    <input type="text" class="form-control mb-2" id="<?= htmlspecialchars($Taglib__id) ?>_search" placeholder="' . htmlspecialchars($placeholder) . '" autocomplete="off">';
            $html[] = '    <input type="hidden" id="<?= htmlspecialchars($Taglib__id) ?>_value" name="<?= htmlspecialchars($Taglib__name) ?>" value="<?= htmlspecialchars($Taglib__value) ?>"' . $dataAttrsStr . '>';
            $html[] = '    <div class="border rounded shadow bg-white" style="max-height:300px; overflow-y:auto; position:relative;">';
            $html[] = '      <div id="<?= htmlspecialchars($Taglib__id) ?>_loading" style="padding:1rem; text-align:center; display:none;">' . __('加载中...') . '</div>';
            $html[] = '      <div id="<?= htmlspecialchars($Taglib__id) ?>_list" style="padding:0.25rem;"></div>';
            $html[] = '    </div>';
            $html[] = '  </div>';
            $html[] = '  <small class="text-muted d-block mt-1">' . __('提示：点击选择SEO账户，绑定后将自动提交sitemap') . '</small>';
            $html[] = '</div>';

            // JavaScript 脚本
            $t_no_match = __('未找到匹配的账户');
            $t_load_fail = __('加载失败');
            $t_default = addslashes(__('请选择SEO账户'));
            $t_no_account = __('暂无SEO账户，请先创建');
            
            $html[] = '<script>(function(){';
            $html[] = 'const ep = ' . json_encode($epPath) . ';';
            $html[] = 'const id = <?= json_encode($Taglib__id) ?>;';
            $html[] = 'const limit = \'<?=$Taglib__limit?>\';';
            $html[] = 'const trigger = document.getElementById(id+"_trigger");';
            $html[] = 'const box = document.getElementById(id+"_container");';
            $html[] = 'const list = document.getElementById(id+"_list");';
            $html[] = 'const loading = document.getElementById(id+"_loading");';
            $html[] = 'const search = document.getElementById(id+"_search");';
            $html[] = 'const hidden = document.getElementById(id+"_value");';
            $html[] = 'const display = document.getElementById(id+"_display");';
            
            $html[] = <<<JS
let cache = null;

function render(items){
  if(!items||!items.length){
    list.innerHTML = "__NO_MATCH__";
    return;
  }
  list.innerHTML = items.slice(0,50).map(function(a){
    var provider = a.provider || 'unknown';
    var status = a.is_active ? '<span class="badge bg-success ms-1">启用</span>' : '<span class="badge bg-secondary ms-1">禁用</span>';
    var txt = a.name + " (" + provider + ")";
    return '<div class="p-2 border-bottom account-item" style="cursor:pointer" data-id="'+ a.account_id +'" data-text="'+ txt +'">'
      + '<i class="mdi mdi-account-cog me-1"></i>' + a.name + ' <small class="text-muted">(' + provider + ')</small>' + status
      + '</div>';
  }).join('');
  
  list.querySelectorAll("[data-id]").forEach(function(el){
    el.addEventListener("click", function(){
      hidden.value = this.dataset.id;
      try{ hidden.setAttribute('value', this.dataset.id); }catch(e){}
      try{ hidden.dispatchEvent(new Event('change')); }catch(e){}
      try{ window.SeoAccountSelectSelected = window.SeoAccountSelectSelected||{}; window.SeoAccountSelectSelected[id]=this.dataset.id; }catch(e){}
      display.textContent = this.dataset.text;
      box.style.display = "none";
      trigger.style.display = "block";
      list.querySelectorAll(".account-item").forEach(function(li){ li.classList.remove("active","bg-light"); });
      this.classList.add("active","bg-light");
    });
  });
  
  if(hidden.value){
    const act = list.querySelector('.account-item[data-id="' + hidden.value + '"]');
    if(act) {
      act.classList.add("active","bg-light");
      const expectedText = act.dataset.text;
      if(!display.textContent || display.textContent.trim() === '' || display.textContent === '{$t_default}' || display.textContent !== expectedText){
        display.textContent = expectedText;
      }
    }
  }
}
JS;

            $html[] = <<<JS
function firstLoad(){
  loading.style.display = "block";
  fetch(ep+"?limit="+limit)
    .then(r=>r.json())
    .then(res=>{
      loading.style.display = "none";
      cache = (res&&res.success) ? (res.data||[]) : [];
      if(cache.length === 0){
        list.innerHTML = "__NO_ACCOUNT__";
        return;
      }
      render(cache);
      if(hidden.value && cache && cache.length > 0){
        const matched = cache.find(function(a){ return String(a.account_id) === String(hidden.value); });
        if(matched && (!display.textContent || display.textContent.trim() === '' || display.textContent === '{$t_default}')){
          const txt = matched.name + " (" + (matched.provider||'unknown') + ")";
          display.textContent = txt;
        }
      }
    })
    .catch(()=>{
      loading.style.display = "none";
      list.innerHTML = "__LOAD_FAIL__";
    });
}
JS;

            $html[] = <<<'JS'
const debounce=(fn,t)=>{let id=null;return (...a)=>{clearTimeout(id);id=setTimeout(()=>fn.apply(null,a),t);}};
JS;

            $html[] = 'const doFilter = debounce(function(kw){ kw=(kw||"").toLowerCase().trim(); if(!kw){ render(cache||[]); return;} var data=(cache||[]).filter(function(a){ var t=((a.name||"")+" "+(a.provider||"")); return t.toLowerCase().indexOf(kw)!==-1; }); render(data); },300);';
            $html[] = 'trigger.addEventListener("click", function(e){ e.stopPropagation(); box.style.top=(trigger.offsetHeight+6)+"px"; box.style.width="100%"; box.style.display="block"; firstLoad(); setTimeout(()=>search.focus(),50); });';
            $html[] = 'search.addEventListener("input", function(){ doFilter(this.value); });';
            $html[] = 'function closeOutside(ev){ if(!(box.contains(ev.target)||trigger.contains(ev.target))){ box.style.display="none"; trigger.style.display="block"; document.removeEventListener("click", closeOutside); document.removeEventListener("keydown", esc); }}';
            $html[] = 'function esc(e){ if(e.key==="Escape"){ box.style.display="none"; trigger.style.display="block"; document.removeEventListener("click", closeOutside); document.removeEventListener("keydown", esc); }}';
            $html[] = 'trigger.addEventListener("click", function(){ setTimeout(function(){ document.addEventListener("click", closeOutside); document.addEventListener("keydown", esc); }, 0); });';
            
            // 页面加载时，如果有初始值，自动加载并设置显示文本
            $html[] = 'if(hidden.value){';
            $html[] = '  fetch(ep+"?limit="+limit).then(r=>r.json()).then(res=>{';
            $html[] = '    const accounts = (res&&res.success) ? (res.data||[]) : [];';
            $html[] = '    const matched = accounts.find(function(a){ return String(a.account_id) === String(hidden.value); });';
            $html[] = '    if(matched){';
            $html[] = '      const txt = matched.name + " (" + (matched.provider||"unknown") + ")";';
            $html[] = '      if(!display.textContent || display.textContent.trim() === "" || display.textContent === "' . $t_default . '" || display.textContent !== txt){';
            $html[] = '        display.textContent = txt;';
            $html[] = '      }';
            $html[] = '    }';
            $html[] = '  }).catch(function(){});';
            $html[] = '}';
            $html[] = '})();</script>';

            // 替换翻译占位符
            $search = [
                '"__NO_MATCH__"',
                '"__LOAD_FAIL__"',
                '"__NO_ACCOUNT__"',
            ];
            $replace = [
                '"<div class=\\"p-2 text-muted\\">' . addslashes($t_no_match) . '</div>"',
                '"<div class=\\"p-2 text-danger\\">' . addslashes($t_load_fail) . '</div>"',
                '"<div class=\\"p-2 text-warning\\">' . addslashes($t_no_account) . '</div>"',
            ];
            foreach ($html as &$line) {
                $line = str_replace($search, $replace, $line);
            }

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
<h3><code>&lt;w:seo:account:select&gt;</code> 使用文档</h3>
<p><strong>作用</strong>：渲染"可搜索 + 点选"的 SEO 账户选择器，用于站点绑定 SEO 账户。</p>

<h4>属性</h4>
<ul>
  <li><code>id</code>：组件唯一 ID（必需）</li>
  <li><code>name</code>：隐藏域表单名（必需）</li>
  <li><code>value</code>：隐藏域值，账户ID（支持变量/路径/默认）</li>
  <li><code>display</code>：按钮显示文本（支持变量/路径/默认）</li>
  <li><code>class</code>/<code>style</code>：外层样式</li>
  <li><code>limit</code>：加载数量限制（默认50）</li>
  <li><code>url</code>：API地址（默认 */backend/api/seo/accounts）</li>
</ul>

<h4>使用示例</h4>
<pre>
&lt;!-- 基本用法 --&gt;
&lt;w:seo:account:select
    id="seo_account"
    name="seo_account_id"
    value="accountId|''"
    display="accountName|'请选择SEO账户'"
    class="w-100"
/&gt;

&lt;!-- 回填已有值 --&gt;
&lt;w:seo:account:select
    id="seo_account"
    name="seo_account_id"
    value="website->getData('seo_account_id')|''"
    display="website->getData('account_name')|'未绑定'"
    class="w-100"
/&gt;
</pre>

<h4>API 响应格式</h4>
<pre>{
  "success": true,
  "data": [
    {
      "account_id": 1,
      "name": "Google Search Console",
      "provider": "google_indexing_api",
      "is_active": 1
    }
  ]
}
</pre>
DOC;

        return htmlspecialchars($doc, ENT_NOQUOTES);
    }
}
