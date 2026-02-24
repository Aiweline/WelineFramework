---
name: create-event
description: Creates events and observers in Weline Framework. MUST use when user mentions event, 事件, observer, 观察者, dispatch, 触发事件, event.php, event.xml, EventsManager, ObserverInterface. Covers event naming convention (Weline_ModuleName::type::event_name), event.php convention files, event.xml observer registration, and inter-module communication patterns. 事件命名规范, 事件规约, 创建事件, 监听事件, 事件通知.
globs:
  - "**/event.php"
  - "**/Observer/**/*.php"
  - "**/etc/event.xml"
alwaysApply: false
---

# 事件创建技能
## 概述

鏈妧鑳芥寚瀵煎浣曞湪 Weline Framework 涓寜鐓ф鏋惰瀹氬垱寤轰簨浠讹紝纭繚浜嬩欢鍖呭惈瀹屾暣鐨勮绾﹀拰鏂囨。锛岀鍚堟鏋舵爣鍑嗐€?
## 浣曟椂浣跨敤

褰撶敤鎴烽渶瑕侊細
- 鍒涘缓鏂扮殑浜嬩欢
- 涓烘ā鍧楁坊鍔犱簨浠舵敮鎸?- 瀹炵幇妯″潡闂寸殑浜嬩欢閫氫俊
- 鎵╁睍绯荤粺鐨勪簨浠跺姛鑳?
## 浜嬩欢鍒涘缓瑕佹眰

鏍规嵁妗嗘灦瑙勫畾锛屽垱寤轰簨浠跺繀椤诲寘鍚互涓嬪唴瀹癸細

### 1. 浜嬩欢瑙勭害鏂囦欢 (event.php)

**浣嶇疆**锛氭ā鍧楁牴鐩綍涓嬬殑 event.php 鏂囦欢

**瑕佹眰**锛?- 蹇呴』瀛樺湪锛屽惁鍒欑郴缁熶細鍙戝嚭璀﹀憡
- 瀹氫箟浜嬩欢鍚嶇О銆佹弿杩般€佺増鏈€佺被鍨嬨€佹暟鎹绾︾瓑淇℃伅
- 浜嬩欢鍚嶅繀椤荤鍚堝懡鍚嶈鑼?
### 2. 浜嬩欢鏂囨。鏂囦欢

**浣嶇疆**锛歚doc/event/ 鐩綍涓嬶紝鎸変簨浠剁被鍨嬪垎绫?
**瑕佹眰**锛?- 蹇呴』瀛樺湪锛屽惁鍒欑郴缁熶細鍙戝嚭璀﹀憡
- 鏂囨。璺緞鍦?event.php 涓€氳繃 doc 瀛楁鎸囧畾
- 寤鸿鎸変簨浠剁被鍨嬪垎绫伙細domain/銆乣integration/銆乣application/

### 3. 浜嬩欢瑙傚療鑰呴厤缃?(鍙€?

**浣嶇疆**锛歚etc/event.xml 鏂囦欢

**瑕佹眰**锛?- 濡傛灉浜嬩欢闇€瑕佽瀵熻€咃紝闇€瑕佸湪 event.xml 涓敞鍐?- 瑙傚療鑰呯被蹇呴』瀹炵幇 ObserverInterface 鎺ュ彛

## 浜嬩欢鍛藉悕瑙勮寖

### 鏍囧噯鏍煎紡

\\\
妯″潡鍚?:浜嬩欢绫诲瀷::浜嬩欢鍚嶇О
\\\

**绀轰緥**锛?- Weline_Seo::domain::subject_created - 棰嗗煙浜嬩欢
- Weline_Seo::integration::task_enqueued - 闆嗘垚浜嬩欢
- Weline_Seo::application::trend_sync_completed - 搴旂敤浜嬩欢

### 绠€鍖栨牸寮忥紙鍏煎鏃х増鏈級

\\\
妯″潡鍚?:浜嬩欢鍚嶇О
\\\

**绀轰緥**锛?- Weline_Admin::msg - 绯荤粺娑堟伅閫氱煡

### 鍛藉悕瑙勫垯

1. **蹇呴』鍖呭惈 :: 鍒嗛殧绗?*锛氫簨浠跺悕蹇呴』鍖呭惈 :: 鍒嗛殧绗︼紝鍚﹀垯绯荤粺浼氳嚧鍛介敊璇€€鍑?2. **鍓嶇紑蹇呴』鍖归厤妯″潡鍚?*锛氫簨浠跺悕鍓嶇紑锛坄:: 涔嬪墠鐨勯儴鍒嗭級蹇呴』浠ユā鍧楀悕寮€澶?3. **鏀寔瀛愭ā鍧?*锛氬彲浠ヤ娇鐢?妯″潡鍚峗瀛愭ā鍧?:浜嬩欢鍚峘 鏍煎紡
4. **鐗规畩澶勭悊**锛?   - Framework_ 寮€澶寸殑浜嬩欢瑙嗕负 Weline_Framework 妯″潡鐨勪簨浠?   - App:: 寮€澶寸殑浜嬩欢瑙嗕负 Weline_Framework 妯″潡鐨勪簨浠?   - 鍔ㄦ€佷簨浠讹紙鍖呭惈 {}锛夎烦杩囬獙璇?
## 浜嬩欢绫诲瀷

### Domain Events (棰嗗煙浜嬩欢)

- **瀹氫箟**锛氫笟鍔￠鍩熷唴鐨勪簨浠讹紝琛ㄧず涓氬姟鐘舵€佺殑鍙樺寲
- **鐗圭偣**锛氫笌涓氬姟閫昏緫绱у瘑鐩稿叧锛岄€氬父鍦ㄩ鍩熸ā鍨嬪唴閮ㄨЕ鍙?- **绀轰緥**锛歚subject_created銆乣keywords_extracted銆乣suggestion_generated

### Integration Events (闆嗘垚浜嬩欢)

- **瀹氫箟**锛氳法妯″潡/绯荤粺鐨勪簨浠讹紝鐢ㄤ簬妯″潡闂撮€氫俊
- **鐗圭偣**锛氱敤浜庤В鑰︽ā鍧椾緷璧栵紝瀹炵幇妯″潡闂村崗浣?- **绀轰緥**锛歚feed_collect銆乣task_enqueued銆乣task_completed

### Application Events (搴旂敤浜嬩欢)

- **瀹氫箟**锛氬簲鐢ㄥ眰浜嬩欢锛岄€氬父涓庣郴缁熸搷浣滅浉鍏?- **鐗圭偣**锛氱敤浜庡簲鐢ㄥ眰闈㈢殑閫氱煡鍜屽崗璋?- **绀轰緥**锛歚trend_sync_completed銆乣url_push_completed

## 鍒涘缓姝ラ

### 姝ラ 1锛氬垱寤轰簨浠惰绾︽枃浠?(event.php)

鍦ㄦā鍧楁牴鐩綍鍒涘缓鎴栫紪杈?event.php 鏂囦欢锛?
\\\php
<?php

/*
 * 鏈枃浠剁敱 绉嬫灚闆侀 缂栧啓锛屾墍鏈夎В閲婃潈褰扐iweline鎵€鏈夈€? * 閭锛歛iweline@qq.com
 * 缃戝潃锛歛iweline.com
 * 璁哄潧锛歨ttps://bbs.aiweline.com
 */

/**
 * 妯″潡鍚?妯″潡浜嬩欢瑙勭害
 * 
 * 鎸夌収鍥介檯鏍囧噯璁捐鐨勪簨浠跺绾︼紝浣跨敤浜嬩欢瑙ｈ€︽ā鍧楅棿渚濊禆
 * 
 * 浜嬩欢鍛藉悕瑙勮寖锛? * - 鏍煎紡锛氭ā鍧楀悕::浜嬩欢绫诲瀷::浜嬩欢鍚嶇О
 * - 绀轰緥锛氭ā鍧楀悕::domain::subject_created
 * 
 * 浜嬩欢绫诲瀷锛? * - domain: 棰嗗煙浜嬩欢锛圖omain Events锛? 涓氬姟棰嗗煙鍐呯殑浜嬩欢
 * - integration: 闆嗘垚浜嬩欢锛圛ntegration Events锛? 璺ㄦā鍧?绯荤粺鐨勪簨浠? * - application: 搴旂敤浜嬩欢锛圓pplication Events锛? 搴旂敤灞備簨浠? */

return [
    // ========== Domain Events (棰嗗煙浜嬩欢) ==========
    
    /**
     * 浜嬩欢鍚嶇О锛堢ず渚嬶級
     * 浜嬩欢鎻忚堪
     */
    '妯″潡鍚?:domain::浜嬩欢鍚嶇О' => [
        'name' => __('浜嬩欢鏄剧ず鍚嶇О'),
        'description' => __('浜嬩欢璇︾粏鎻忚堪锛岃鏄庝綍鏃惰Е鍙戜互鍙婄敤閫斻€?),
        'doc' => 'domain/浜嬩欢鍚嶇О.md',  // 鏂囨。璺緞锛岀浉瀵逛簬 doc/event/ 鐩綍
        'version' => '1.0.0',           // 璇箟鍖栫増鏈彿
        'type' => 'domain',             // 浜嬩欢绫诲瀷锛歞omain, integration, application
        'data_contract' => [            // 鏁版嵁濂戠害瀹氫箟
            'field_name' => [
                'type' => 'integer|string|array|object|mixed',
                'required' => true|false,
                'description' => '瀛楁璇存槑',
                'default' => '榛樿鍊硷紙鍙€夛級',
            ],
            // ... 鏇村瀛楁
        ],
    ],

    // ========== Integration Events (闆嗘垚浜嬩欢) ==========
    
    '妯″潡鍚?:integration::浜嬩欢鍚嶇О' => [
        'name' => __('浜嬩欢鏄剧ず鍚嶇О'),
        'description' => __('浜嬩欢璇︾粏鎻忚堪'),
        'doc' => 'integration/浜嬩欢鍚嶇О.md',
        'version' => '1.0.0',
        'type' => 'integration',
        'data_contract' => [
            // 鏁版嵁濂戠害
        ],
    ],

    // ========== Application Events (搴旂敤浜嬩欢) ==========
    
    '妯″潡鍚?:application::浜嬩欢鍚嶇О' => [
        'name' => __('浜嬩欢鏄剧ず鍚嶇О'),
        'description' => __('浜嬩欢璇︾粏鎻忚堪'),
        'doc' => 'application/浜嬩欢鍚嶇О.md',
        'version' => '1.0.0',
        'type' => 'application',
        'data_contract' => [
            // 鏁版嵁濂戠害
        ],
    ],
];
\\\

### 姝ラ 2锛氬垱寤轰簨浠舵枃妗ｆ枃浠?
鍦?doc/event/ 鐩綍涓嬪垱寤烘枃妗ｆ枃浠讹紝鎸変簨浠剁被鍨嬪垎绫伙細

**鏂囦欢璺緞**锛歚doc/event/domain/浜嬩欢鍚嶇О.md锛堟牴鎹簨浠剁被鍨嬮€夋嫨鐩綍锛?
**鏂囨。妯℃澘**锛?
\\\markdown
# 妯″潡鍚?:domain::浜嬩欢鍚嶇О - 浜嬩欢鏄剧ず鍚嶇О

## 浜嬩欢璇存槑

浜嬩欢鐨勮缁嗚鏄庯紝鍖呮嫭浣曟椂瑙﹀彂銆佺敤閫旂瓑銆?
## 浜嬩欢绫诲瀷

**Domain Event锛堥鍩熶簨浠讹級** - 涓氬姟棰嗗煙鍐呯殑浜嬩欢

锛堟垨 Integration Event / Application Event锛?
## 瑙﹀彂鏃舵満

璇存槑浜嬩欢鍦ㄤ粈涔堟儏鍐典笅瑙﹀彂锛岄€氬父鍦ㄥ摢涓柟娉曟垨娴佺▼涓€?
## 鏁版嵁鏍煎紡

\\\php
[
    'field_name' => type,  // 蹇呴渶/鍙€夛細瀛楁璇存槑
    // ... 鏇村瀛楁
]
\\\

## 鍙敤鏁版嵁

### 蹇呴渶瀛楁

- \ield_name\ (type) - 瀛楁璇存槑

### 鍙€夊瓧娈?
- \ield_name\ (type) - 瀛楁璇存槑

## 浣跨敤鍦烘櫙

- 鍦烘櫙1璇存槑
- 鍦烘櫙2璇存槑
- 鍦烘櫙3璇存槑

## 浣跨敤鏂规硶

### 鍦?event.xml 涓敞鍐岃瀵熻€?
\\\xml
<event name="妯″潡鍚?:domain::浜嬩欢鍚嶇О">
    <observer name="妯″潡鍚?:瑙傚療鑰呭悕绉? 
              instance="鍛藉悕绌洪棿\Observer\瑙傚療鑰呯被" 
              disabled="false" 
              shared="true" 
              sort="10"/>
</event>
\\\

### 鍒涘缓瑙傚療鑰呯被

\\\php
namespace 鍛藉悕绌洪棿\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class 瑙傚療鑰呯被 implements ObserverInterface
{
    public function execute(Event &): void
    {
         = ->getData();
        // 澶勭悊閫昏緫
    }
}
\\\

## 浣跨敤绀轰緥

### 绀轰緥锛氬叿浣撲娇鐢ㄥ満鏅?
\\\php
// 绀轰緥浠ｇ爜
\\\

## 娉ㄦ剰浜嬮」

- 娉ㄦ剰浜嬮」1
- 娉ㄦ剰浜嬮」2
- 娉ㄦ剰浜嬮」3

## 鐩稿叧浜嬩欢

- \妯″潡鍚?:domain::鐩稿叧浜嬩欢\ - 鐩稿叧浜嬩欢璇存槑
\\\

### 姝ラ 3锛氬湪浠ｇ爜涓Е鍙戜簨浠讹紙鍙€夛級

濡傛灉闇€瑕佽Е鍙戜簨浠讹紝鍦ㄤ唬鐮佷腑浣跨敤 EventsManager锛?
\\\php
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

// 鑾峰彇浜嬩欢绠＄悊鍣?/** @var EventsManager  */
 = ObjectManager::getInstance(EventsManager::class);

// 瑙﹀彂浜嬩欢
 = [
    'field_name' => ,
    // ... 鏇村鏁版嵁
];
->dispatch('妯″潡鍚?:domain::浜嬩欢鍚嶇О', );
\\\

### 姝ラ 4锛氭敞鍐岃瀵熻€咃紙鍙€夛級

濡傛灉闇€瑕佺洃鍚簨浠讹紝鍦?etc/event.xml 涓敞鍐岃瀵熻€咃細

\\\xml
<?xml version="1.0" encoding="UTF-8"?>
<config xmlns:xs="http://www.w3.org/2001/XMLSchema-instance"
        xs:noNamespaceSchemaLocation="urn:Weline_Framework::Event/etc/xsd/event.xsd"
        xmlns="urn:Weline_Framework::Event/etc/xsd/event.xsd">
    <event name="妯″潡鍚?:domain::浜嬩欢鍚嶇О">
        <observer name="妯″潡鍚?:瑙傚療鑰呭悕绉? 
                  instance="鍛藉悕绌洪棿\Observer\瑙傚療鑰呯被" 
                  disabled="false" 
                  shared="true" 
                  sort="10"/>
    </event>
</config>
\\\

## 鏁版嵁濂戠害瑙勮寖

### 瀛楁绫诲瀷

- integer - 鏁存暟
- string - 瀛楃涓?- rray - 鏁扮粍
- object - 瀵硅薄
- mixed - 娣峰悎绫诲瀷
- ool - 甯冨皵鍊?- loat - 娴偣鏁?
### 瀛楁瀹氫箟鏍煎紡

\\\php
'field_name' => [
    'type' => 'integer',           // 瀛楁绫诲瀷
    'required' => true,            // 鏄惁蹇呴渶
    'description' => '瀛楁璇存槑',   // 瀛楁鎻忚堪
    'default' => '榛樿鍊?,         // 榛樿鍊硷紙鍙€夛級
]
\\\

## 楠岃瘉妫€鏌?
绯荤粺浼氬湪鏋勫缓浜嬩欢娉ㄥ唽琛ㄦ椂鑷姩妫€鏌ワ細

1. **瑙勭害鏂囦欢妫€鏌?*锛氭鏌ユ槸鍚﹀瓨鍦?event.php 鏂囦欢
2. **鏂囨。鏂囦欢妫€鏌?*锛氭鏌?doc/event/ 鐩綍涓嬫槸鍚﹀瓨鍦ㄥ搴旂殑鏂囨。鏂囦欢
3. **鍛藉悕瑙勮寖妫€鏌?*锛氶獙璇佷簨浠跺悕鏄惁绗﹀悎鍛藉悕瑙勮寖
4. **鍐茬獊妫€鏌?*锛氭鏌ヤ簨浠跺悕鏄惁涓庡叾浠栨ā鍧楀啿绐?
濡傛灉缂哄皯瑙勭害鎴栨枃妗ｏ紝绯荤粺浼氳褰曡鍛婁俊鎭紝浣嗕笉浼氶樆姝㈡敞鍐岃〃鐨勬瀯寤恒€?
## 鏈€浣冲疄璺?
### 1. 浜嬩欢鍛藉悕

- 浣跨敤娓呮櫚銆佹弿杩版€х殑浜嬩欢鍚嶇О
- 閬靛惊 妯″潡鍚?:浜嬩欢绫诲瀷::浜嬩欢鍚嶇О 鏍煎紡
- 浣跨敤灏忓啓瀛楁瘝鍜屼笅鍒掔嚎缁勫悎浜嬩欢鍚嶇О閮ㄥ垎

### 2. 浜嬩欢绫诲瀷閫夋嫨

- **Domain Events**锛氱敤浜庝笟鍔￠鍩熷唴鐨勭姸鎬佸彉鍖?- **Integration Events**锛氱敤浜庤法妯″潡閫氫俊
- **Application Events**锛氱敤浜庡簲鐢ㄥ眰鎿嶄綔閫氱煡

### 3. 鏁版嵁濂戠害

- 鏄庣‘瀹氫箟鎵€鏈夊瓧娈电殑绫诲瀷鍜屾槸鍚﹀繀闇€
- 鎻愪緵娓呮櫚鐨勫瓧娈垫弿杩?- 涓哄彲閫夊瓧娈垫彁渚涢粯璁ゅ€?
### 4. 鏂囨。缂栧啓

- 璇︾粏璇存槑浜嬩欢鐨勮Е鍙戞椂鏈?- 鎻愪緵瀹屾暣鐨勪娇鐢ㄧず渚?- 鍒楀嚭鎵€鏈変娇鐢ㄥ満鏅?- 璇存槑娉ㄦ剰浜嬮」鍜岀浉鍏充簨浠?
### 5. 鐗堟湰绠＄悊

- 浣跨敤璇箟鍖栫増鏈彿锛圫emantic Versioning锛?- 閲嶅ぇ鍙樻洿鏃跺崌绾т富鐗堟湰鍙?- 鍚戝悗鍏煎鐨勫彉鏇村崌绾ф鐗堟湰鍙?- 淇bug鏃跺崌绾т慨璁㈢増鏈彿

## 甯歌閿欒

### 閿欒 1锛氫簨浠跺悕缂哄皯 :: 鍒嗛殧绗?
**閿欒绀轰緥**锛?\\\php
'妯″潡鍚峗event_name' => [...]
\\\

**姝ｇ‘鏍煎紡**锛?\\\php
'妯″潡鍚?:domain::event_name' => [...]
\\\

### 閿欒 2锛氫簨浠跺悕鍓嶇紑涓嶅尮閰嶆ā鍧楀悕

**閿欒绀轰緥**锛?\\\php
// 鍦?Weline_Seo 妯″潡涓?'OtherModule::domain::event_name' => [...]
\\\

**姝ｇ‘鏍煎紡**锛?\\\php
// 鍦?Weline_Seo 妯″潡涓?'Weline_Seo::domain::event_name' => [...]
\\\

### 閿欒 3锛氱己灏戣绾︽枃浠?
**闂**锛氭病鏈夊垱寤?event.php 鏂囦欢

**瑙ｅ喅**锛氬湪妯″潡鏍圭洰褰曞垱寤?event.php 鏂囦欢骞跺畾涔変簨浠?
### 閿欒 4锛氱己灏戞枃妗ｆ枃浠?
**闂**锛氬湪 event.php 涓寚瀹氫簡 doc 瀛楁锛屼絾鏂囨。鏂囦欢涓嶅瓨鍦?
**瑙ｅ喅**锛氬湪 doc/event/ 鐩綍涓嬪垱寤哄搴旂殑鏂囨。鏂囦欢

### 閿欒 5锛氭枃妗ｈ矾寰勪笉姝ｇ‘

**闂**锛歚doc 瀛楁鎸囧畾鐨勮矾寰勪笌瀹為檯鏂囦欢璺緞涓嶅尮閰?
**瑙ｅ喅**锛氱‘淇?doc 瀛楁鐨勮矾寰勭浉瀵逛簬 doc/event/ 鐩綍锛屼緥濡?domain/event_name.md

## 绀轰緥锛氬畬鏁寸殑浜嬩欢鍒涘缓

### 绀轰緥 1锛氬垱寤洪鍩熶簨浠?
**1. 鍒涘缓 event.php**锛?
\\\php
<?php

return [
    'Weline_Product::domain::product_created' => [
        'name' => __('浜у搧鍒涘缓'),
        'description' => __('褰撲骇鍝佽鍒涘缓鏃惰Е鍙戯紝鍏佽鍏朵粬妯″潡鐩戝惉骞跺鐞嗕骇鍝佸垱寤洪€昏緫銆?),
        'doc' => 'domain/product_created.md',
        'version' => '1.0.0',
        'type' => 'domain',
        'data_contract' => [
            'product_id' => ['type' => 'integer', 'required' => true, 'description' => '浜у搧ID'],
            'sku' => ['type' => 'string', 'required' => true, 'description' => '浜у搧SKU'],
            'name' => ['type' => 'string', 'required' => true, 'description' => '浜у搧鍚嶇О'],
            'price' => ['type' => 'float', 'required' => false, 'description' => '浜у搧浠锋牸'],
        ],
    ],
];
\\\

**2. 鍒涘缓鏂囨。鏂囦欢** doc/event/domain/product_created.md锛?
\\\markdown
# Weline_Product::domain::product_created - 浜у搧鍒涘缓

## 浜嬩欢璇存槑

褰撲骇鍝佽鍒涘缓鏃惰Е鍙戯紝鍏佽鍏朵粬妯″潡鐩戝惉骞跺鐞嗕骇鍝佸垱寤洪€昏緫銆?
## 浜嬩欢绫诲瀷

**Domain Event锛堥鍩熶簨浠讹級** - 涓氬姟棰嗗煙鍐呯殑浜嬩欢

## 瑙﹀彂鏃舵満

鍦ㄤ骇鍝佷繚瀛樺埌鏁版嵁搴撳悗瑙﹀彂锛岄€氬父鍦?Product::save() 鏂规硶涓€?
## 鏁版嵁鏍煎紡

\\\php
[
    'product_id' => int,    // 蹇呴渶锛氫骇鍝両D
    'sku' => string,        // 蹇呴渶锛氫骇鍝丼KU
    'name' => string,       // 蹇呴渶锛氫骇鍝佸悕绉?    'price' => float,       // 鍙€夛細浜у搧浠锋牸
]
\\\

## 浣跨敤鍦烘櫙

- 浜у搧鍒涘缓鍚庡彂閫侀€氱煡
- 鍚屾浜у搧淇℃伅鍒板閮ㄧ郴缁?- 瑙﹀彂搴撳瓨鍒濆鍖?- 璁板綍浜у搧鍒涘缓鏃ュ織

## 浣跨敤鏂规硶

### 鍦?event.xml 涓敞鍐岃瀵熻€?
\\\xml
<event name="Weline_Product::domain::product_created">
    <observer name="Weline_Notification::product_created" 
              instance="Weline\Notification\Observer\ProductCreatedObserver" 
              disabled="false" 
              shared="true" 
              sort="10"/>
</event>
\\\

## 娉ㄦ剰浜嬮」

- 浜у搧宸蹭繚瀛樺埌鏁版嵁搴?- 浜嬩欢鏁版嵁鍖呭惈瀹屾暣鐨勪骇鍝佷俊鎭?- 寤鸿鍦ㄨ瀵熻€呬腑杩涜寮傛鎿嶄綔锛岄伩鍏嶉樆濉炰富娴佺▼
\\\

**3. 鍦ㄤ唬鐮佷腑瑙﹀彂浜嬩欢**锛?
\\\php
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

// 鍦ㄤ骇鍝佷繚瀛樺悗
 = ObjectManager::getInstance(EventsManager::class);
->dispatch('Weline_Product::domain::product_created', [
    'product_id' => ->getId(),
    'sku' => ->getSku(),
    'name' => ->getName(),
    'price' => ->getPrice(),
]);
\\\

## 妫€鏌ユ竻鍗?
鍒涘缓浜嬩欢鏃讹紝纭繚瀹屾垚浠ヤ笅妫€鏌ワ細

- [ ] 鍒涘缓浜?event.php 鏂囦欢
- [ ] 浜嬩欢鍚嶇鍚堝懡鍚嶈鑼冿紙鍖呭惈 :: 鍒嗛殧绗︼級
- [ ] 浜嬩欢鍚嶅墠缂€鍖归厤妯″潡鍚?- [ ] 瀹氫箟浜嗗畬鏁寸殑鏁版嵁濂戠害
- [ ] 鍒涘缓浜嗘枃妗ｆ枃浠讹紙doc/event/ 鐩綍涓嬶級
- [ ] 鏂囨。璺緞鍦?event.php 涓纭寚瀹?- [ ] 鏂囨。鍐呭瀹屾暣锛堝寘鎷鏄庛€佹暟鎹牸寮忋€佷娇鐢ㄧず渚嬬瓑锛?- [ ] 濡傛灉闇€瑕佸湪浠ｇ爜涓Е鍙戯紝宸插疄鐜拌Е鍙戦€昏緫
- [ ] 濡傛灉闇€瑕佽瀵熻€咃紝宸插湪 event.xml 涓敞鍐?- [ ] 杩愯浜?module:upgrade 鍛戒护鏇存柊娉ㄥ唽琛?
## CRITICAL：触发事件必须用变量传值

`EventsManager::dispatch(string $eventName, mixed &$data = [])` 的第二个参数是**引用**（`&$data`）。PHP 8+ 规定：引用参数不能接收数组字面量，只能接收变量。

- **错误**：`$eventsManager->dispatch('Event::name', ['key' => $value]);` → 报错 "Argument #2 ($data) could not be passed by reference"
- **正确**：`$eventData = ['key' => $value]; $eventsManager->dispatch('Event::name', $eventData);`

生成或审查触发事件的代码时，必须确保第二个参数是变量，不能是 `[...]` 字面量。

## 事件系统错误预防（必读）

### 1. dispatch 必须用变量传参（见上）

### 2. 事件数据必须包装在 `'data'` 键下

Observer 通过 `$event->getData('data')` 获取数据，因此传递的数据需放在 `'data'` 键下。

```php
$eventData = ['data' => ['category_id' => $id, 'product_ids' => $productIds]];
$eventsManager->dispatch('Module::event_name', $eventData);
// Observer: $data = $event->getData('data');
```

### 3. 事件必须在依赖它的代码之前触发

若 Hook 等依赖某事件的执行结果，须在渲染/执行依赖方**之前** dispatch（例如在控制器中先 dispatch，再 fetch 模板）。

### 4. 定义了监听必须确保事件被触发

在 `etc/event.xml` 中注册了 observer 后，须在代码中某处调用 `$eventsManager->dispatch('Module::action_after', $eventData)`，否则监听永不生效。

## 鐩稿叧璧勬簮

- [妗嗘灦浜嬩欢鏂囨。](../../app/code/Weline/Framework/doc/2-蹇€熷紑濮?08-浜嬩欢.md)
- [浜嬩欢绯荤粺璁捐鏂囨。](../../app/code/Weline/Seo/doc/浜嬩欢绯荤粺璁捐鏂囨。.md)
- [EventRegistry 婧愮爜](../../app/code/Weline/Framework/Event/EventRegistry.php)
- [EventScanner 婧愮爜](../../app/code/Weline/Framework/Event/EventScanner.php)
