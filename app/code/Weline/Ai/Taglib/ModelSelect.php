<?php
declare(strict_types=1);

namespace Weline\Ai\Taglib;

use Weline\Taglib\TaglibInterface;
use Weline\Framework\Http\Url;

class ModelSelect implements TaglibInterface
{
    public static function name(): string
    {
        // 模板中使用 <w:ai:model:select .../>
        return 'ai:model:select';
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
        // 返回需要“强制校验”的属性列表；此处全部可选，因此不强制校验
        return ['id'=>true,'name'=>true,'value'=>true,'display'=>true,'class'=>false,'style'=>false,'limit'=>false,'url'=>false];
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            if(empty($attributes['id'])) {
                throw new \Exception(__('id属性不能为空'));
            }
            $placeholder = $attributes['placeholder'] ?? __('搜索AI模型...');
            $class = $attributes['class'] ?? '';
            $style = $attributes['style'] ?? '';
            $limit = (int)($attributes['limit'] ?? 50);
            $urlPath = $attributes['url'] ?? '*/backend/api/models';
            /** @var Url $url */
            $url = w_obj(Url::class);
            $epUrl = $url->getBackendUrl($urlPath);

            $attributes['url'] = $epUrl;
            $attributes['limit'] = $limit;
            // 解析所有属性（包括 id），解析后的值会存储在 $Taglib__{属性名} 变量中
            $code = \Weline\Taglib\Taglib::attributes($attributes);
            // 使用解析后的 id（Taglib::attributes 会自动解析变量，如果变量不存在会返回原始字符串）
            $html = [];
            $html[] = '<?php ' . $code . ' ?>';

            // 输出 HTML（使用解析后的 id 值）
            $html[] = '<div class="position-relative ' . htmlspecialchars($class) . '" style="' . htmlspecialchars($style) . '">';
            $html[] = '  <button type="button" class="btn btn-outline-secondary w-100 text-start" id="<?= htmlspecialchars($Taglib__id) ?>_trigger" style="height: 38px;">';
            $html[] = '    <span id="<?= htmlspecialchars($Taglib__id) ?>_display"><?php if($Taglib__display!==' . "''" . '): echo htmlspecialchars($Taglib__display); else: ?>' . htmlspecialchars(__('使用默认模型')) . '<?php endif; ?></span>';
            $html[] = '  </button>';
            $html[] = '  <div id="<?= htmlspecialchars($Taglib__id) ?>_container" style="display:none; position:absolute; left:0; right:0; top:0; z-index:1060;">';
            $html[] = '    <input type="text" class="form-control mb-2" id="<?= htmlspecialchars($Taglib__id) ?>_search" placeholder="' . htmlspecialchars($placeholder) . '" autocomplete="off">';
            $html[] = '    <input type="hidden" id="<?= htmlspecialchars($Taglib__id) ?>_code" name="<?= htmlspecialchars($Taglib__name) ?>" value="<?= htmlspecialchars($Taglib__value) ?>">';
            $html[] = '    <div class="border rounded shadow bg-white" style="max-height:300px; overflow-y:auto; position:relative;">';
            $html[] = '      <div id="<?= htmlspecialchars($Taglib__id) ?>_loading" style="padding:1rem; text-align:center; display:none;"></div>';
            $html[] = '      <div id="<?= htmlspecialchars($Taglib__id) ?>_list" style="padding:0.25rem;"></div>';
            $html[] = '    </div>';
            $html[] = '  </div>';
            $html[] = '  <small class="text-muted d-block mt-1">' . __('提示：点击选择AI模型，输入关键词搜索（800ms防抖）') . '</small>';
            $html[] = '</div>';

            // 脚本：数据加载 + 搜索 + 选中回填
            $html[] = '<script>(function(){';
            $html[] = 'const ep = ' . json_encode($epUrl) . ';';
            $html[] = 'const id = <?= json_encode($Taglib__id) ?>;';
            $html[] = 'const json = <?=$Taglib__json?>;';
            $html[] = 'const limit = \'<?=$Taglib__limit?>\';';
            $html[] = 'const trigger = document.getElementById(id+"_trigger");';
            $html[] = 'const box = document.getElementById(id+"_container");';
            $html[] = 'const list = document.getElementById(id+"_list");';
            $html[] = 'const loading = document.getElementById(id+"_loading");';
            $html[] = 'const search = document.getElementById(id+"_search");';
            $html[] = 'const hidden = document.getElementById(id+"_code");';
            $html[] = 'const display = document.getElementById(id+"_display");';
            // 初始化时已用服务端解析值，不再解析 JSON
            $t_no_match = __('未找到匹配的模型');
            $t_load_fail = __('加载失败');
            $t_default_model = addslashes(__('使用默认模型'));
            $html[] = <<<JS
 let cache = null;
 function render(items){
   if(!items||!items.length){
     list.innerHTML = "__NO_MATCH__";
     return;
   }
   list.innerHTML = items.slice(0,50).map(function(m){
     var txt = m.name + " (" + m.supplier + ") - " + m.version;
     return '<div class="p-2 border-bottom model-item" style="cursor:pointer" data-code="'+ m.code +'" data-text="'+ txt +'">'+ txt +'</div>';
   }).join('');
   list.querySelectorAll("[data-code]").forEach(function(el){
     el.addEventListener("click", function(){
       hidden.value = this.dataset.code;
       try{ hidden.setAttribute('value', this.dataset.code); }catch(e){}
       try{ hidden.dispatchEvent(new Event('change')); }catch(e){}
       try{ window.AiModelSelectSelected = window.AiModelSelectSelected||{}; window.AiModelSelectSelected[id]=this.dataset.code; }catch(e){}
       display.textContent = this.dataset.text;
       box.style.display = "none";
       trigger.style.display = "block";
       list.querySelectorAll(".model-item").forEach(function(li){ li.classList.remove("active"); });
       this.classList.add("active");
     });
   });
   if(hidden.value){
     const act = list.querySelector('.model-item[data-code="' + hidden.value + '"]');
     if(act) {
       act.classList.add("active");
       // 如果显示文本为空或为默认值，则根据选中的模型设置显示文本
       if(!display.textContent || display.textContent.trim() === '' || display.textContent === '{$t_default_model}'){
         display.textContent = act.dataset.text;
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
      render(cache);
      // 渲染完成后，如果有初始值，尝试设置显示文本
      if(hidden.value && cache && cache.length > 0){
        const matched = cache.find(function(m){ return m.code === hidden.value; });
        if(matched && (!display.textContent || display.textContent.trim() === '' || display.textContent === '{$t_default_model}')){
          const txt = matched.name + " (" + matched.supplier + ") - " + matched.version;
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
            $html[] = 'const doFilter = debounce(function(kw){ kw=(kw||"").toLowerCase().trim(); if(!kw){ render(cache||[]); return;} fetch(ep+"?search="+encodeURIComponent(kw)).then(function(r){return r.json();}).then(function(res){ render((res&&res.success)?(res.data||[]):[]); }).catch(function(){ var data=(cache||[]).filter(function(m){ var t=((m.name||"")+" "+(m.supplier||"")+" "+(m.code||"")+" "+(m.version||"")); return t.toLowerCase().indexOf(kw)!==-1; }); render(data); }); },800);';
            $html[] = 'trigger.addEventListener("click", function(e){ e.stopPropagation(); box.style.top=(trigger.offsetHeight+6)+"px"; box.style.width="100%"; box.style.display="block"; firstLoad(); setTimeout(()=>search.focus(),50); });';
            $html[] = 'search.addEventListener("input", function(){ doFilter(this.value); });';
            $html[] = 'function closeOutside(ev){ if(!(box.contains(ev.target)||trigger.contains(ev.target))){ box.style.display="none"; trigger.style.display="block"; document.removeEventListener("click", closeOutside); document.removeEventListener("keydown", esc); }}';
            $html[] = 'function esc(e){ if(e.key==="Escape"){ box.style.display="none"; trigger.style.display="block"; document.removeEventListener("click", closeOutside); document.removeEventListener("keydown", esc); }}';
            $html[] = 'trigger.addEventListener("click", function(){ setTimeout(function(){ document.addEventListener("click", closeOutside); document.addEventListener("keydown", esc); }, 0); });';
            // 页面加载时，如果有初始值但显示文本为空，则自动加载并设置
            $html[] = 'if(hidden.value && (!display.textContent || display.textContent.trim() === "" || display.textContent === "' . $t_default_model . '")){';
            $html[] = '  fetch(ep+"?limit="+limit).then(r=>r.json()).then(res=>{';
            $html[] = '    const models = (res&&res.success) ? (res.data||[]) : [];';
            $html[] = '    const matched = models.find(function(m){ return m.code === hidden.value; });';
            $html[] = '    if(matched){';
            $html[] = '      const txt = matched.name + " (" + matched.supplier + ") - " + matched.version;';
            $html[] = '      display.textContent = txt;';
            $html[] = '    }';
            $html[] = '  }).catch(function(){});';
            $html[] = '}';
            $html[] = '})();</script>';

            // 将翻译插入到前面占位符，避免转义问题
            $search = [
                '"__NO_MATCH__"',
                '"__LOAD_FAIL__"',
            ];
            $replace = [
                '"<div class=\\"p-2 text-muted\\">' . addslashes($t_no_match) . '</div>"',
                '"<div class=\\"p-2 text-danger\\">' . addslashes($t_load_fail) . '</div>"',
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
<h3><code>&lt;w:ai:model:select&gt;</code> 使用文档</h3>
<p><strong>作用</strong>：渲染“可搜索 + 点选”的 AI 模型选择器；或仅输出解析后的 JSON。</p>
<h4>属性解析规则（通用于 name/value/display 等）</h4>
<ul>
  <li><strong>变量名</strong>：写变量名则按模板作用域取值，如 <code>name="msFieldName"</code> → 取 <code>\$msFieldName</code></li>
  <li><strong>点路径</strong>：<code>a.b.c</code> → 依次取 <code>\$a['b']['c']</code>；对象优先 <code>getB()</code>/公开属性</li>
  <li><strong>管道默认</strong>：<code>var|默认值</code>；当取不到或值为空串时回退默认（<code>''</code>/<code>""</code> 识别为空串）</li>
  <li><strong>落地变量</strong>：解析后在模板可用 <code>\$Taglib__{属性名}</code>（如 <code>\$Taglib__value</code>）</li>
</ul>
<h4>常用属性</h4>
<ul>
  <li><code>id</code>：组件唯一 ID（可不传，自动生成）</li>取个名字，看你使用场景，不要重复
  <li><code>name</code>：隐藏域表单名（支持变量/路径/默认）</li>
  <li><code>value</code>：隐藏域值（支持变量/路径/默认）</li>
  <li><code>display</code>：按钮显示文本（支持变量/路径/默认；为空显示“使用默认模型”）</li>
  <li><code>class</code>/<code>style</code>：外层样式</li>
  <li><code>json</code>：为 <code>1/true/yes</code> 时，仅输出解析后的 JSON 字符串；否则渲染 HTML</li>
  <li><code>showJson</code>：为 <code>1/true/yes</code> 时，直接 echo JSON；否则写入 <code>\$Taglib__json</code></li>
</ul>
<h4>示例</h4>
<pre>
&lt;!-- 示例一：使用已解析变量，输出 JSON（用于自定义渲染） --&gt;
&lt;w:ai:model:select id="type"
                   name="model_code"
                   value="msValue|''"
                   display="msDisplay|''"
                   class="w-100"
                   json="1" /&gt;

&lt;!-- 示例二：使用点路径 + 默认文案（同 index.phtml 117 行） --&gt;
&lt;w:ai:model:select id="type"
                   name="model_code"
                   value="currentDefault.model_code|''"
                   display="currentDefault.model_name|请选择模型"
                   class="w-100"
                   json="1" /&gt;
</pre>
<h4>JSON 结构（当 json=1）</h4>
<pre>{
  "id": "ms_xxx",
  "name": "...",
  "value": "...",
  "display": "...",
  "class": "w-100",
  "limit": 50,
  "url": "*/backend/api/models"
}
</pre>
<p>说明：键值均为解析后的最终值；<code>id/class/limit/url</code> 等来自标签属性。</p>
DOC;

        return htmlspecialchars($doc, ENT_NOQUOTES);
    }
}


