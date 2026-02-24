---
name: create-hook
description: Creates Hook extension points in Weline Framework. Use when user needs to create hooks, add hook support to modules, implement view template extension points, or allow other modules to extend current module functionality. Covers hook.php convention files.
globs:
  - "**/hook.php"
  - "**/view/hooks/**/*.phtml"
alwaysApply: false
---

# Hook创建技能
## 概述

鏈妧鑳芥寚瀵煎浣曞湪 Weline Framework 涓寜鐓ф鏋惰瀹氬垱寤?Hook锛岀‘淇?Hook 鍖呭惈瀹屾暣鐨勮绾﹀拰鏂囨。锛岀鍚堟鏋舵爣鍑嗐€?
## 浣曟椂浣跨敤

褰撶敤鎴烽渶瑕侊細
- 鍒涘缓鏂扮殑 Hook 鎵╁睍鐐?- 涓烘ā鍧楁坊鍔?Hook 鏀寔
- 瀹炵幇瑙嗗浘妯℃澘鐨勬墿灞曠偣
- 鍏佽鍏朵粬妯″潡鎵╁睍褰撳墠妯″潡鐨勫姛鑳?
## Hook鍒涘缓瑕佹眰

鏍规嵁妗嗘灦瑙勫畾锛屽垱寤?Hook 蹇呴』鍖呭惈浠ヤ笅鍐呭锛?
### 1. Hook瑙勭害鏂囦欢 (hook.php)

**浣嶇疆**锛氭ā鍧楁牴鐩綍涓嬬殑 `hook.php` 鏂囦欢

**瑕佹眰**锛?- 蹇呴』瀛樺湪锛屽惁鍒欑郴缁熶細鍙戝嚭璀﹀憡鎴栭敊璇?- 瀹氫箟 Hook 鍚嶇О銆佹弿杩般€佹枃妗ｈ矾寰勭瓑淇℃伅
- Hook 鍚嶅繀椤荤鍚堝懡鍚嶈鑼?- 姣忎釜 Hook 瑙勭害鍙兘琚竴涓ā鍧楀畾涔?
### 2. Hook鏂囨。鏂囦欢

**浣嶇疆**锛歚doc/hook/` 鐩綍涓嬶紝鎸?Hook 璺緞缁撴瀯缁勭粐

**瑕佹眰**锛?- 蹇呴』瀛樺湪锛屽惁鍒欑郴缁熶細鎶涘嚭寮傚父闃绘淇濆瓨娉ㄥ唽琛?- 鏂囨。璺緞鍦?`hook.php` 涓€氳繃 `doc` 瀛楁鎸囧畾
- 鏂囨。璺緞鐩稿浜?`doc/hook/` 鐩綍

### 3. Hook瀹炵幇鏂囦欢 (鍙€?

**浣嶇疆**锛歚view/hooks/` 鐩綍涓嬶紝鎸?Hook 鍚嶇О杞崲涓虹洰褰曠粨鏋?
**瑕佹眰**锛?- Hook 鍚嶇О涓殑 `::` 杞崲涓虹洰褰曞垎闅旂 `/`
- 鏂囦欢鎵╁睍鍚嶄负 `.phtml`
- 鍙互鍦ㄦ枃浠舵敞閲婁腑瀹氫箟鍏冩暟鎹紙浼樺厛绾с€佹帓搴忛『搴忋€乻olo妯″紡锛?
### 4. Hook鎺ュ彛甯搁噺 (鍙€変絾鎺ㄨ崘)

**浣嶇疆**锛歚Weline\Framework\Hook\HookInterface` 鎺ュ彛涓?
**瑕佹眰**锛?- 鍦?HookInterface 涓畾涔変负甯搁噺
- 甯搁噺鍚嶉伒寰懡鍚嶈鑼?- 甯搁噺鍊间负瀹屾暣鐨?Hook 鍚嶇О

## Hook鍛藉悕瑙勮寖

### 鏍囧噯鏍煎紡

```
妯″潡鍚?:area::type::component::position
```

**绀轰緥**锛?- `Weline_Theme::frontend::layouts::base::head-before` - 涓婚鍓嶇甯冨眬鍩虹澶撮儴涔嬪墠
- `Weline_Order::backend::order::view::before` - 璁㈠崟鍚庣璇︽儏椤典箣鍓?- `Weline_Frontend::frontend::partials::head::before` - 鍓嶇澶撮儴涔嬪墠

### 鍛藉悕缁勬垚閮ㄥ垎

1. **妯″潡鍚?*锛氬畾涔夎 Hook 鐨勬ā鍧楀悕绉帮紙濡?`Weline_Theme`锛?2. **area**锛氬尯鍩燂紙濡?`frontend`銆乣backend`锛?3. **type**锛氱被鍨嬶紙濡?`layouts`銆乣partials`銆乣order`锛?4. **component**锛氱粍浠讹紙濡?`base`銆乣head`銆乣view`锛?5. **position**锛氫綅缃紙濡?`before`銆乣after`銆乣content-before`锛?
### 鍛藉悕瑙勫垯

1. **蹇呴』鍖呭惈 `::` 鍒嗛殧绗?*锛欻ook 鍚嶅繀椤诲寘鍚?`::` 鍒嗛殧绗︼紝鍚﹀垯绯荤粺浼氳嚧鍛介敊璇€€鍑?2. **鍓嶇紑蹇呴』鍖归厤妯″潡鍚?*锛欻ook 鍚嶅墠缂€锛堢涓€涓?`::` 涔嬪墠鐨勯儴鍒嗭級蹇呴』浠ユā鍧楀悕寮€澶?3. **鏀寔鍚戝悗鍏煎**锛氱畝鍗曟牸寮忕殑 Hook锛堜笉鍖呭惈 `::`锛夌敤浜庡悜鍚庡吋瀹癸紝璺宠繃涓ユ牸楠岃瘉

## 鍒涘缓姝ラ

### 姝ラ 1锛氬垱寤?Hook 瑙勭害鏂囦欢 (hook.php)

鍦ㄦā鍧楁牴鐩綍鍒涘缓鎴栫紪杈?`hook.php` 鏂囦欢锛?
```php
<?php
/**
 * 妯″潡鍚?妯″潡 Hook 瑙勭害鏂囦欢
 * 
 * 鏈枃浠跺畾涔変簡 妯″潡鍚?妯″潡鎻愪緵鐨勬墍鏈?Hook 鎵╁睍鐐? * Hook 鍛藉悕鏍煎紡锛歿ModuleName}::{area}::{type}::{component}::{position}
 * 
 * 鎵€鏈?Hook 寤鸿鍦?Weline\Framework\Hook\HookInterface 涓畾涔変负甯搁噺
 */
return [
    // ==================== 鍒嗙被璇存槑 ====================
    '妯″潡鍚?:area::type::component::position' => [
        'name' => __('Hook鏄剧ず鍚嶇О'),
        'description' => __('Hook璇︾粏鎻忚堪锛岃鏄庝綍鏃惰Е鍙戜互鍙婄敤閫斻€?),
        'doc' => 'area/type/component/position.md',  // 鏂囨。璺緞锛岀浉瀵逛簬 doc/hook/ 鐩綍
    ],
    
    // 鏇村 Hook 瀹氫箟...
];
```

### 姝ラ 2锛氬垱寤?Hook 鏂囨。鏂囦欢

鍦?`doc/hook/` 鐩綍涓嬪垱寤烘枃妗ｆ枃浠讹紝鎸?Hook 璺緞缁撴瀯缁勭粐锛?
**鏂囦欢璺緞**锛歚doc/hook/area/type/component/position.md`锛堟牴鎹?Hook 缁撴瀯缁勭粐锛?
**鏂囨。妯℃澘**锛?
```markdown
# 妯″潡鍚?:area::type::component::position - Hook鏄剧ず鍚嶇О

## 姒傝堪

鏈枃妗ｈ缁嗚鏄庝簡 妯″潡鍚?妯″潡鎻愪緵鐨?Hook 鍙婂叾浣跨敤鏂规硶銆傝 Hook 鍏佽鍏朵粬妯″潡鍦ㄦ寚瀹氫綅缃敞鍏ュ唴瀹广€?
## Hook 淇℃伅

### 鍩烘湰淇℃伅

- **Hook 鍚嶇О**锛歚妯″潡鍚?:area::type::component::position`
- **鏄剧ず鍚嶇О**锛欻ook鏄剧ず鍚嶇О
- **Hook 绫诲瀷**锛氭爣鍑嗘牸寮?Hook
- **瀹氫箟妯″潡**锛氭ā鍧楀悕
- **鍖哄煙锛圓rea锛?*锛歛rea
- **绫诲瀷锛圱ype锛?*锛歵ype
- **缁勪欢锛圕omponent锛?*锛歝omponent
- **浣嶇疆锛圥osition锛?*锛歱osition

### 鍔熻兘璇存槑

Hook鐨勮缁嗗姛鑳借鏄庯紝鍖呮嫭浣曟椂瑙﹀彂銆佺敤閫旂瓑銆?
### 瑙﹀彂鏃舵満

璇?Hook 鍦ㄤ互涓嬫椂鏈鸿Е鍙戯細
- **瑙﹀彂浣嶇疆**锛氬叿浣撲綅缃鏄?- **瑙﹀彂鏉′欢**锛氳Е鍙戞潯浠惰鏄?- **浣跨敤浣嶇疆**锛氬湪鍝釜妯℃澘鏂囦欢涓娇鐢?
## 浣跨敤鏂规硶

### 鍩烘湰鐢ㄦ硶

鍦ㄦā鍧楃殑 `view/hooks/` 鐩綍涓嬪垱寤哄搴旂殑妯℃澘鏂囦欢锛?
瀵逛簬鏍囧噯鏍煎紡鐨?Hook锛孒ook 鍚嶇О涓殑 `::` 杞崲涓虹洰褰曞垎闅旂 `/`锛?
渚嬪锛屽浜?Hook `妯″潡鍚?:area::type::component::position`锛屾枃浠惰矾寰勫簲涓猴細
```
view/hooks/妯″潡鍚?area/type/component/position.phtml
```

### 妯℃澘鏂囦欢绀轰緥

```phtml
<?php
/**
 * 妯″潡鍚嶇О - Hook鎻忚堪
 * Hook鍚嶇О锛氭ā鍧楀悕::area::type::component::position
 * 
 * @hook-priority 200      Hook浼樺厛绾э細200锛堟暟瀛楄秺澶ц秺浼樺厛锛? * @hook-sort-order 1      Hook鎺掑簭椤哄簭锛?锛堟暟瀛楄秺灏忚秺浼樺厛锛? * @hook-solo false        Hook鐙韩锛歠alse锛堜笉鐙崰锛屼笌鍏朵粬Hook涓€璧锋墽琛岋級
 */
?>
<!-- Hook 鍐呭 -->
<div class="custom-hook-content">
    <!-- 鑷畾涔夊唴瀹?-->
</div>
```

### 浣跨敤鍦烘櫙

璇?Hook 閫傜敤浜庝互涓嬪満鏅細
- 鍦烘櫙1璇存槑
- 鍦烘櫙2璇存槑
- 鍦烘櫙3璇存槑

## Hook 鍏冩暟鎹?
### 浼樺厛绾?(Priority)

- **榛樿浼樺厛绾?*锛氭牴鎹ā鍧椾綅缃嚜鍔ㄨ绠?  - app: 200
  - composer: 150
  - framework: 100
  - system: 50
- **鑷畾涔変紭鍏堢骇**锛氶€氳繃 `@hook-priority` 鏍囩瀹氫箟锛屾暟瀛楄秺澶ц秺浼樺厛

### 鎺掑簭椤哄簭 (Sort Order)

- **榛樿鎺掑簭椤哄簭**锛氫娇鐢ㄦā鍧楁壂鎻忛『搴?- **鑷畾涔夋帓搴忛『搴?*锛氶€氳繃 `@hook-sort-order` 鏍囩瀹氫箟锛屾暟瀛楄秺灏忚秺浼樺厛

### Solo锛堢嫭浜級妯″紡

- **榛樿鍊?*锛歠alse锛堜笉鐙崰锛?- **璁剧疆涓?true**锛氱嫭鍗犳暣涓?Hook锛屽叾浠?Hook 瀹炵幇灏嗚蹇界暐
- **娉ㄦ剰**锛氫竴涓?Hook 鍙兘鏈変竴涓ā鍧楄缃负 `solo = true`

## 娉ㄦ剰浜嬮」

- 娉ㄦ剰浜嬮」1
- 娉ㄦ剰浜嬮」2
- 娉ㄦ剰浜嬮」3

## 鐩稿叧鏂囦欢

- **Hook 瑙勭害鏂囦欢**锛歚app/code/妯″潡鍚?hook.php`
- **Hook 鎺ュ彛瀹氫箟**锛歚app/code/Weline/Framework/Hook/HookInterface.php`
- **浣跨敤浣嶇疆**锛歚app/code/妯″潡鍚?view/templates/...`

## 鐩稿叧璧勬簮

- [Weline Framework Hook 绯荤粺鏂囨。](../../../../Framework/doc/hook/README.md)
```

### 姝ラ 3锛氬垱寤?Hook 瀹炵幇鏂囦欢锛堝彲閫夛級

濡傛灉闇€瑕佸疄鐜?Hook锛屽湪 `view/hooks/` 鐩綍涓嬪垱寤烘ā鏉挎枃浠讹細

**鏂囦欢璺緞瑙勫垯**锛?- Hook 鍚嶇О锛歚妯″潡鍚?:area::type::component::position`
- 鏂囦欢璺緞锛歚view/hooks/妯″潡鍚?area/type/component/position.phtml`
- 杞崲瑙勫垯锛歚::` 鈫?`/`

**瀹炵幇鏂囦欢妯℃澘**锛?
```phtml
<?php
/**
 * 妯″潡鍚嶇О - Hook鎻忚堪
 * Hook鍚嶇О锛氭ā鍧楀悕::area::type::component::position
 * 
 * @hook-priority 200      Hook浼樺厛绾э細200锛堟暟瀛楄秺澶ц秺浼樺厛锛? * @hook-sort-order 1      Hook鎺掑簭椤哄簭锛?锛堟暟瀛楄秺灏忚秺浼樺厛锛? * @hook-solo false        Hook鐙韩锛歠alse锛堜笉鐙崰锛屼笌鍏朵粬Hook涓€璧锋墽琛岋級
 */
?>
<!-- Hook 鍐呭 -->
<div class="custom-hook-content">
    <!-- 鑷畾涔夊唴瀹?-->
</div>
```

### 姝ラ 4锛氬湪 HookInterface 涓畾涔夊父閲忥紙鎺ㄨ崘锛?
鍦?`Weline\Framework\Hook\HookInterface` 鎺ュ彛涓坊鍔犲父閲忓畾涔夛細

```php
// ==================== 妯″潡鍚?Hook ====================
const MODULE_AREA_TYPE_COMPONENT_POSITION = '妯″潡鍚?:area::type::component::position';
```

## Hook 鍏冩暟鎹?
### 浼樺厛绾?(Priority)

**瀹氫箟鏂瑰紡**锛?
```php
/**
 * @hook-priority 200      Hook浼樺厛绾э細200锛堟暟瀛楄秺澶ц秺浼樺厛锛? */
```

**瑙勫垯**锛?- 鏁板瓧瓒婂ぇ瓒婁紭鍏堬紙闄嶅簭锛?- 濡傛灉鏈畾涔夛紝鏍规嵁妯″潡浣嶇疆鑷姩璁＄畻锛?  - app: 200
  - composer: 150
  - framework: 100
  - system: 50

### 鎺掑簭椤哄簭 (Sort Order)

**瀹氫箟鏂瑰紡**锛?
```php
/**
 * @hook-sort-order 1      Hook鎺掑簭椤哄簭锛?锛堟暟瀛楄秺灏忚秺浼樺厛锛? */
```

**瑙勫垯**锛?- 鏁板瓧瓒婂皬瓒婁紭鍏堬紙鍗囧簭锛?- 褰撲紭鍏堢骇鐩稿悓鏃讹紝鎸夋帓搴忛『搴忔帓搴?- 濡傛灉鏈畾涔夛紝浣跨敤妯″潡鎵弿椤哄簭

### Solo锛堢嫭浜級妯″紡

**瀹氫箟鏂瑰紡**锛?
```php
/**
 * @hook-solo true        Hook鐙韩锛歵rue锛堢嫭鍗狅紝鍏朵粬Hook瀹炵幇灏嗚蹇界暐锛? */
```

**瑙勫垯**锛?- 璁剧疆涓?`true` 鏃讹紝鐙崰鏁翠釜 Hook锛屽叾浠?Hook 瀹炵幇灏嗚蹇界暐
- 涓€涓?Hook 鍙兘鏈変竴涓ā鍧楄缃负 `solo = true`
- 澶氫釜妯″潡璁剧疆浼氭姤閿?
## Hook 鎵ц椤哄簭

Hook 鎵ц椤哄簭鐢变互涓嬭鍒欏喅瀹氾紙鎸変紭鍏堢骇浠庨珮鍒颁綆锛夛細

1. **Solo 妫€鏌?*锛氬鏋滃瓨鍦?`solo = true` 鐨?Hook锛屽彧鎵ц璇?Hook
2. **浼樺厛绾э紙priority锛?*锛氭暟瀛楄秺澶ц秺浼樺厛锛堥檷搴忥級
3. **鎺掑簭椤哄簭锛坰ort_order锛?*锛氭暟瀛楄秺灏忚秺浼樺厛锛堝崌搴忥級
4. **妯″潡浣嶇疆浼樺厛绾?*锛歛pp > composer > framework > system
5. **妯″潡渚濊禆椤哄簭**锛氭寜妯″潡鍔犺浇椤哄簭
6. **妯″潡鍚嶆帓搴?*锛氫綔涓烘渶鍚庣殑鎺掑簭渚濇嵁

## 楠岃瘉妫€鏌?
绯荤粺浼氬湪鏋勫缓 Hook 娉ㄥ唽琛ㄦ椂鑷姩妫€鏌ワ細

1. **瑙勭害鏂囦欢妫€鏌?*锛氭鏌ユ槸鍚﹀瓨鍦?`hook.php` 鏂囦欢
2. **鏂囨。鏂囦欢妫€鏌?*锛氭鏌?`doc/hook/` 鐩綍涓嬫槸鍚﹀瓨鍦ㄥ搴旂殑鏂囨。鏂囦欢
3. **鍛藉悕瑙勮寖妫€鏌?*锛氶獙璇?Hook 鍚嶆槸鍚︾鍚堝懡鍚嶈鑼?4. **鍐茬獊妫€鏌?*锛氭鏌?Hook 瑙勭害鏄惁涓庡叾浠栨ā鍧楀啿绐侊紙瑙勭害鍙兘琚竴涓ā鍧楀畾涔夛級
5. **瀹炵幇鏂囦欢妫€鏌?*锛氬湪寮€鍙戠幆澧冧笅锛屾鏌?Hook 瀹炵幇鏂囦欢鏄惁鏈夊搴旂殑瑙勭害

濡傛灉缂哄皯瑙勭害鎴栨枃妗ｏ紝绯荤粺浼氭姏鍑哄紓甯搁樆姝繚瀛樻敞鍐岃〃銆?
## 鏈€浣冲疄璺?
### 1. Hook 鍛藉悕

- 浣跨敤娓呮櫚銆佹弿杩版€х殑 Hook 鍚嶇О
- 閬靛惊 `妯″潡鍚?:area::type::component::position` 鏍煎紡
- 浣跨敤灏忓啓瀛楁瘝鍜岃繛瀛楃缁勫悎浣嶇疆閮ㄥ垎

### 2. Hook 浣嶇疆閫夋嫨

- **before**锛氬湪鍐呭涔嬪墠
- **after**锛氬湪鍐呭涔嬪悗
- **content-before**锛氬湪鍐呭鍖哄煙涔嬪墠
- **content-after**锛氬湪鍐呭鍖哄煙涔嬪悗

### 3. 鏂囨。缂栧啓

- 璇︾粏璇存槑 Hook 鐨勮Е鍙戞椂鏈?- 鎻愪緵瀹屾暣鐨勪娇鐢ㄧず渚?- 鍒楀嚭鎵€鏈変娇鐢ㄥ満鏅?- 璇存槑娉ㄦ剰浜嬮」鍜岀浉鍏?Hook

### 4. 鍏冩暟鎹娇鐢?
- 鍚堢悊璁剧疆浼樺厛绾э紝閬垮厤杩囧害渚濊禆
- 浣跨敤鎺掑簭椤哄簭杩涜绮剧粏鎺у埗
- 璋ㄦ厧浣跨敤 solo 妯″紡锛屽彧鍦ㄥ繀瑕佹椂浣跨敤

### 5. 瀹炵幇鏂囦欢缁勭粐

- 鎸?Hook 璺緞缁撴瀯缁勭粐鏂囦欢
- 鍦ㄦ枃浠舵敞閲婁腑瀹氫箟鍏冩暟鎹?- 纭繚鏂囦欢鍐呭绗﹀悎 HTML/PHP 瑙勮寖

## 甯歌閿欒

### 閿欒 1锛欻ook 鍚嶇己灏?`::` 鍒嗛殧绗?
**閿欒绀轰緥**锛?```php
'妯″潡鍚峗area_type_component_position' => [...]
```

**姝ｇ‘鏍煎紡**锛?```php
'妯″潡鍚?:area::type::component::position' => [...]
```

### 閿欒 2锛欻ook 鍚嶅墠缂€涓嶅尮閰嶆ā鍧楀悕

**閿欒绀轰緥**锛?```php
// 鍦?Weline_Theme 妯″潡涓?'OtherModule::area::type::component::position' => [...]
```

**姝ｇ‘鏍煎紡**锛?```php
// 鍦?Weline_Theme 妯″潡涓?'Weline_Theme::area::type::component::position' => [...]
```

### 閿欒 3锛氱己灏戣绾︽枃浠?
**闂**锛氭病鏈夊垱寤?`hook.php` 鏂囦欢

**瑙ｅ喅**锛氬湪妯″潡鏍圭洰褰曞垱寤?`hook.php` 鏂囦欢骞跺畾涔?Hook

### 閿欒 4锛氱己灏戞枃妗ｆ枃浠?
**闂**锛氬湪 `hook.php` 涓寚瀹氫簡 `doc` 瀛楁锛屼絾鏂囨。鏂囦欢涓嶅瓨鍦?
**瑙ｅ喅**锛氬湪 `doc/hook/` 鐩綍涓嬪垱寤哄搴旂殑鏂囨。鏂囦欢

### 閿欒 5锛氭枃妗ｈ矾寰勪笉姝ｇ‘

**闂**锛歚doc` 瀛楁鎸囧畾鐨勮矾寰勪笌瀹為檯鏂囦欢璺緞涓嶅尮閰?
**瑙ｅ喅**锛氱‘淇?`doc` 瀛楁鐨勮矾寰勭浉瀵逛簬 `doc/hook/` 鐩綍锛屼緥濡?`area/type/component/position.md`

### 閿欒 6锛欻ook 瑙勭害鍐茬獊

**闂**锛氬涓ā鍧楀畾涔変簡鐩稿悓鐨?Hook 瑙勭害

**瑙ｅ喅**锛欻ook 瑙勭害鍙兘琚竴涓ā鍧楀畾涔夛紝鍏朵粬妯″潡鍙兘鎻愪緵瀹炵幇鏂囦欢

### 閿欒 7锛氬疄鐜版枃浠惰矾寰勪笉姝ｇ‘

**闂**锛欻ook 瀹炵幇鏂囦欢鐨勮矾寰勪笌 Hook 鍚嶇О涓嶅尮閰?
**瑙ｅ喅**锛氱‘淇濇枃浠惰矾寰勯伒寰?`view/hooks/妯″潡鍚?area/type/component/position.phtml` 鏍煎紡

## 绀轰緥锛氬畬鏁寸殑 Hook 鍒涘缓

### 绀轰緥 1锛氬垱寤哄墠绔竷灞€ Hook

**1. 鍒涘缓 hook.php**锛?
```php
<?php
/**
 * Weline_Theme 妯″潡 Hook 瑙勭害鏂囦欢
 */
return [
    'Weline_Theme::frontend::layouts::base::head-before' => [
        'name' => __('鍓嶇甯冨眬澶撮儴涔嬪墠'),
        'description' => __('鍦ㄦ墍鏈夊墠绔竷灞€鐨?<head> 鏍囩涔嬪墠娉ㄥ叆鍐呭锛屽厑璁稿叾浠栨ā鍧楀湪椤甸潰澶撮儴娉ㄥ叆棰濆鐨?CSS銆丣avaScript 鎴栧叾浠栬祫婧愩€?),
        'doc' => 'frontend/layouts/base/head-before.md',
    ],
];
```

**2. 鍒涘缓鏂囨。鏂囦欢** `doc/hook/frontend/layouts/base/head-before.md`锛?
```markdown
# Weline_Theme::frontend::layouts::base::head-before - 鍓嶇甯冨眬澶撮儴涔嬪墠

## 姒傝堪

鏈枃妗ｈ缁嗚鏄庝簡 Weline_Theme 妯″潡鎻愪緵鐨?`Weline_Theme::frontend::layouts::base::head-before` Hook 鍙婂叾浣跨敤鏂规硶銆?
## Hook 淇℃伅

### 鍩烘湰淇℃伅

- **Hook 鍚嶇О**锛歚Weline_Theme::frontend::layouts::base::head-before`
- **鏄剧ず鍚嶇О**锛氬墠绔竷灞€澶撮儴涔嬪墠
- **瀹氫箟妯″潡**锛歐eline_Theme

### 鍔熻兘璇存槑

鍦ㄦ墍鏈夊墠绔竷灞€鐨?<head> 鏍囩涔嬪墠娉ㄥ叆鍐呭锛屽厑璁稿叾浠栨ā鍧楀湪椤甸潰澶撮儴娉ㄥ叆棰濆鐨?CSS銆丣avaScript 鎴栧叾浠栬祫婧愩€?
### 瑙﹀彂鏃舵満

璇?Hook 鍦ㄦ墍鏈夊墠绔竷灞€娓叉煋鏃讹紝鍦?<head> 鏍囩涔嬪墠瑙﹀彂銆?
## 浣跨敤鏂规硶

鍦ㄦā鍧楃殑 `view/hooks/` 鐩綍涓嬪垱寤烘枃浠讹細
```
view/hooks/Weline_Theme/frontend/layouts/base/head-before.phtml
```

## 浣跨敤鍦烘櫙

- 娉ㄥ叆鍏ㄥ眬 CSS 鏍峰紡
- 娣诲姞绗笁鏂?JavaScript 搴?- 娉ㄥ叆 Meta 鏍囩
```

**3. 鍒涘缓瀹炵幇鏂囦欢** `view/hooks/Weline_Theme/frontend/layouts/base/head-before.phtml`锛?
```phtml
<?php
/**
 * 鑷畾涔夋ā鍧?- 鍓嶇澶撮儴Hook瀹炵幇
 * Hook鍚嶇О锛歐eline_Theme::frontend::layouts::base::head-before
 * 
 * @hook-priority 200      Hook浼樺厛绾э細200
 * @hook-sort-order 1      Hook鎺掑簭椤哄簭锛?
 * @hook-solo false        Hook鐙韩锛歠alse
 */
?>
<link rel="stylesheet" href="<?= $this->getViewFileUrl('css/custom.css') ?>" />
```

**4. 鍦?HookInterface 涓畾涔夊父閲?*锛?
```php
// ==================== Theme Frontend Layouts ====================
const THEME_FRONTEND_LAYOUTS_BASE_HEAD_BEFORE = 'Weline_Theme::frontend::layouts::base::head-before';
```

## 妫€鏌ユ竻鍗?
鍒涘缓 Hook 鏃讹紝纭繚瀹屾垚浠ヤ笅妫€鏌ワ細

- [ ] 鍒涘缓浜?`hook.php` 鏂囦欢
- [ ] Hook 鍚嶇鍚堝懡鍚嶈鑼冿紙鍖呭惈 `::` 鍒嗛殧绗︼級
- [ ] Hook 鍚嶅墠缂€鍖归厤妯″潡鍚?- [ ] 鍒涘缓浜嗘枃妗ｆ枃浠讹紙`doc/hook/` 鐩綍涓嬶級
- [ ] 鏂囨。璺緞鍦?`hook.php` 涓纭寚瀹?- [ ] 鏂囨。鍐呭瀹屾暣锛堝寘鎷鏄庛€佷娇鐢ㄧず渚嬬瓑锛?- [ ] 濡傛灉闇€瑕佸疄鐜帮紝鍒涘缓浜嗗疄鐜版枃浠讹紙`view/hooks/` 鐩綍涓嬶級
- [ ] 瀹炵幇鏂囦欢璺緞姝ｇ‘锛圚ook 鍚嶇О杞崲涓虹洰褰曠粨鏋勶級
- [ ] 瀹炵幇鏂囦欢涓畾涔変簡鍏冩暟鎹紙浼樺厛绾с€佹帓搴忛『搴忕瓑锛?- [ ] 鍦?HookInterface 涓畾涔変簡甯搁噺锛堟帹鑽愶級
- [ ] 杩愯浜?`hook:rebuild` 鍛戒护鏇存柊娉ㄥ唽琛?
## 鐩稿叧璧勬簮

- [Hook 椤哄簭鏈哄埗璁捐鏂囨。](../../app/code/Weline/Framework/Hook/doc/Hook椤哄簭鏈哄埗璁捐.md)
- [Hook 浼樺厛绾у拰鎺掑簭椤哄簭浣跨敤鎸囧崡](../../app/code/Weline/Framework/Hook/doc/Hook浼樺厛绾у拰鎺掑簭椤哄簭浣跨敤鎸囧崡.md)
- [HookRegistry 婧愮爜](../../app/code/Weline/Hook/HookRegistry.php)
- [HookScanner 婧愮爜](../../app/code/Weline/Hook/HookScanner.php)