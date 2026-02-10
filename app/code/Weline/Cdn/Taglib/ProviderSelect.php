<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Taglib;

use Weline\Taglib\TaglibInterface;
use Weline\Framework\Http\Url;

/**
 * CDN 供应商选择标签
 *
 * 使用示例：
 * <w:select:provider id="cdn_provider" name="provider" />
 */
class ProviderSelect implements TaglibInterface
{
    public static function name(): string
    {
        return 'select:provider';
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

            $placeholder = $attributes['placeholder'] ?? __('搜索CDN供应商...');
            $class = $attributes['class'] ?? '';
            $style = $attributes['style'] ?? '';
            $limit = (int)($attributes['limit'] ?? 50);
            $urlPath = $attributes['url'] ?? 'cdn/backend/api/cdn/providers';

            /** @var Url $url */
            $url = w_obj(Url::class);
            $epUrl = $url->getBackendUrl($urlPath);
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

            $code = \Weline\Taglib\Taglib::attributes($attributes);
            $html = [];
            $html[] = '<?php ' . $code . ' ?>';

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

            $html[] = '<div class="position-relative cdn-provider-select ' . htmlspecialchars($class) . '" style="' . htmlspecialchars($style) . '"' . $dataAttrsStr . '>';
            $html[] = '  <button type="button" class="btn btn-outline-secondary w-100 text-start" id="<?= htmlspecialchars($Taglib__id) ?>_trigger" style="height: 38px;">';
            $html[] = '    <i class="mdi mdi-cloud-outline me-1"></i>';
            $html[] = '    <span id="<?= htmlspecialchars($Taglib__id) ?>_display"><?php if($Taglib__display!==' . "''" . '): echo htmlspecialchars($Taglib__display); else: ?>' . htmlspecialchars(__('请选择CDN供应商')) . '<?php endif; ?></span>';
            $html[] = '  </button>';
            $html[] = '  <div id="<?= htmlspecialchars($Taglib__id) ?>_container" class="bg-white border rounded shadow-sm" style="display:none; position:absolute; left:0; right:0; top:0; z-index:1060; padding: 0.75rem;"' . $dataAttrsStr . '>';
            $html[] = '    <input type="text" class="form-control mb-2" id="<?= htmlspecialchars($Taglib__id) ?>_search" placeholder="' . htmlspecialchars($placeholder) . '" autocomplete="off">';
            $html[] = '    <input type="hidden" id="<?= htmlspecialchars($Taglib__id) ?>_code" name="<?= htmlspecialchars($Taglib__name) ?>" value="<?= htmlspecialchars($Taglib__value) ?>"' . $dataAttrsStr . '>';
            $html[] = '    <div class="border rounded shadow bg-white" style="max-height:300px; overflow-y:auto; position:relative;">';
            $html[] = '      <div id="<?= htmlspecialchars($Taglib__id) ?>_loading" style="padding:1rem; text-align:center; display:none;">' . __('加载中...') . '</div>';
            $html[] = '      <div id="<?= htmlspecialchars($Taglib__id) ?>_list" style="padding:0.25rem;"></div>';
            $html[] = '    </div>';
            $html[] = '  </div>';
            $html[] = '</div>';

            $t_no_match = __('未找到匹配的供应商');
            $t_load_fail = __('加载失败');
            $t_default = addslashes(__('请选择CDN供应商'));

            $html[] = '<script>(function(){';
            $html[] = 'const ep = ' . json_encode($epPath) . ';';
            $html[] = 'const id = <?= json_encode($Taglib__id) ?>;';
            $html[] = 'const limit = \'<?=$Taglib__limit?>\';';
            $html[] = 'const trigger = document.getElementById(id+"_trigger");';
            $html[] = 'const box = document.getElementById(id+"_container");';
            $html[] = 'const list = document.getElementById(id+"_list");';
            $html[] = 'const loading = document.getElementById(id+"_loading");';
            $html[] = 'const search = document.getElementById(id+"_search");';
            $html[] = 'const hidden = document.getElementById(id+"_code");';
            $html[] = 'const display = document.getElementById(id+"_display");';
            $html[] = <<<JS
let cache = null;
function render(items){
  if(!items||!items.length){
    list.innerHTML = "__NO_MATCH__";
    return;
  }
  list.innerHTML = items.slice(0,50).map(function(p){
    var txt = p.name + " (" + p.code + ")";
    var desc = p.description ? ("<div class=\\"text-muted small\\">" + p.description + "</div>") : "";
    return '<div class="p-2 border-bottom provider-item" style="cursor:pointer" data-code="'+ p.code +'" data-text="'+ txt +'">'
      + '<i class="mdi mdi-cloud me-1"></i>' + txt + desc
      + '</div>';
  }).join('');
  list.querySelectorAll("[data-code]").forEach(function(el){
    el.addEventListener("click", function(){
      hidden.value = this.dataset.code;
      try{ hidden.setAttribute('value', this.dataset.code); }catch(e){}
      try{ hidden.dispatchEvent(new Event('change')); }catch(e){}
      try{ window.CdnProviderSelectSelected = window.CdnProviderSelectSelected||{}; window.CdnProviderSelectSelected[id]=this.dataset.code; }catch(e){}
      display.textContent = this.dataset.text;
      box.style.display = "none";
      trigger.style.display = "block";
      list.querySelectorAll(".provider-item").forEach(function(li){ li.classList.remove("active","bg-light"); });
      this.classList.add("active","bg-light");
    });
  });
  if(hidden.value){
    const act = list.querySelector('.provider-item[data-code="' + hidden.value + '"]');
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
      render(cache);
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
            $html[] = 'const doFilter = debounce(function(kw){ kw=(kw||"").toLowerCase().trim(); if(!kw){ render(cache||[]); return;} fetch(ep+"?search="+encodeURIComponent(kw)).then(function(r){return r.json();}).then(function(res){ render((res&&res.success)?(res.data||[]):[]); }).catch(function(){ var data=(cache||[]).filter(function(p){ var t=((p.name||"")+" "+(p.code||"")+" "+(p.description||"")); return t.toLowerCase().indexOf(kw)!==-1; }); render(data); }); },600);';
            $html[] = 'trigger.addEventListener("click", function(e){ e.stopPropagation(); box.style.top=(trigger.offsetHeight+6)+"px"; box.style.width="100%"; box.style.display="block"; firstLoad(); setTimeout(()=>search.focus(),50); });';
            $html[] = 'search.addEventListener("input", function(){ doFilter(this.value); });';
            $html[] = 'function closeOutside(ev){ if(!(box.contains(ev.target)||trigger.contains(ev.target))){ box.style.display="none"; trigger.style.display="block"; document.removeEventListener("click", closeOutside); document.removeEventListener("keydown", esc); }}';
            $html[] = 'function esc(e){ if(e.key==="Escape"){ box.style.display="none"; trigger.style.display="block"; document.removeEventListener("click", closeOutside); document.removeEventListener("keydown", esc); }}';
            $html[] = 'trigger.addEventListener("click", function(){ setTimeout(function(){ document.addEventListener("click", closeOutside); document.addEventListener("keydown", esc); }, 0); });';
            $html[] = '})();</script>';

            $html = str_replace('__NO_MATCH__', $t_no_match, implode("\n", $html));
            $html = str_replace('__LOAD_FAIL__', $t_load_fail, $html);

            return $html;
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
        return '<p><code>&lt;w:select:provider /&gt;</code> CDN 供应商选择标签</p>';
    }
}
