---
name: create-extends
description: Creates Extends extension points in Weline Framework. Use when defining module extension points that allow other modules to extend functionality. This is for DEFINING extension points (extends.php), not for implementing them. Covers plugin architecture and third-party module extensibility.
globs:
  - "**/extends.php"
alwaysApply: false
---

# Extends创建技能
## 概述

鏈妧鑳芥寚瀵煎浣曞湪 Weline Framework 涓寜鐓ф鏋惰瀹氬垱寤?Extends锛堟墿灞曠偣锛夛紝纭繚 Extends 鍖呭惈瀹屾暣鐨勮绾﹀拰鏂囨。锛岀鍚堟鏋舵爣鍑嗐€?
## 浣曟椂浣跨敤

褰撶敤鎴烽渶瑕侊細
- 涓烘ā鍧楀畾涔夋墿灞曠偣锛屽厑璁稿叾浠栨ā鍧楁墿灞曞姛鑳?- 鍒涘缓鍙墿灞曠殑鎺ュ彛鎴栨枃浠剁粨鏋?- 瀹炵幇鎻掍欢鍖栨灦鏋?- 鍏佽绗笁鏂规ā鍧楁墿灞曟牳蹇冨姛鑳?
## Extends鍒涘缓瑕佹眰

鏍规嵁妗嗘灦瑙勫畾锛屽垱寤?Extends 蹇呴』鍖呭惈浠ヤ笅鍐呭锛?
### 1. Extends瑙勭害鏂囦欢 (extends.php)

**浣嶇疆**锛氭ā鍧楁牴鐩綍涓嬬殑 `extends.php` 鏂囦欢

**瑕佹眰**锛?- 蹇呴』瀛樺湪锛岀敤浜庡畾涔夋ā鍧楁彁渚涚殑鎵╁睍鐐?- 瀹氫箟鎵╁睍鐐圭殑璺緞銆佺被鍨嬨€佹弿杩般€佹帴鍙ｇ瓑淇℃伅
- 绯荤粺浼氭壂鎻忔鏂囦欢鏉ヨ瘑鍒墿灞曠偣

### 2. Extends鏂囨。鏂囦欢 (extends.md)

**浣嶇疆**锛氭ā鍧楁牴鐩綍涓嬬殑 `extends.md` 鏂囦欢

**瑕佹眰**锛?- 蹇呴』瀛樺湪锛屽惁鍒欑郴缁熶細鍙戝嚭璀﹀憡
- 璇︾粏璇存槑濡備綍浣跨敤鎵╁睍鐐?- 鍖呭惈蹇€熷紑濮嬨€佽缁嗚鏄庛€佺ず渚嬩唬鐮佺瓑绔犺妭

### 3. 鎵╁睍鏂囨。鐩綍 (鍙€変絾鎺ㄨ崘)

**浣嶇疆**锛歚doc/` 鐩綍涓?
**瑕佹眰**锛?- 寤鸿鍦?`doc/` 鐩綍涓嬪垱寤烘墿灞曠浉鍏崇殑璇︾粏鏂囨。
- 鍙互鍖呭惈澶氫釜鏂囨。鏂囦欢锛屽 `鎵╁睍寮€鍙戞枃妗?md`銆乣鎵╁睍瑙勭害璇存槑.md` 绛?
### 4. 鎵╁睍瀹炵幇鏂囦欢 (鐢卞叾浠栨ā鍧楀垱寤?

**浣嶇疆**锛歚extends/module/{ModuleName}/` 鎴?`extends/theme/{ThemeName}/` 鐩綍涓?
**瑕佹眰**锛?- 鐢变娇鐢ㄦ墿灞曠偣鐨勬ā鍧楀垱寤?- 鎸夌収瑙勭害鏂囦欢涓畾涔夌殑璺緞缁撴瀯缁勭粐
- 濡傛灉瀹氫箟浜嗘帴鍙ｏ紝蹇呴』瀹炵幇璇ユ帴鍙?
## Extends瑙勭害鏂囦欢鏍煎紡

### 鍩烘湰缁撴瀯

```php
<?php

declare(strict_types=1);

/*
 * 鏈枃浠剁敱 绉嬫灚闆侀 缂栧啓锛屾墍鏈夎В閲婃潈褰扐iweline鎵€鏈夈€? * 閭锛歛iweline@qq.com
 * 缃戝潃锛歛iweline.com
 * 璁哄潧锛歨ttps://bbs.aiweline.com
 */

/**
 * 妯″潡鍚?妯″潡鎵╁睍瑙勭害
 * 
 * 鏈枃浠跺畾涔変簡 妯″潡鍚?妯″潡鎻愪緵鐨勬墿灞曠偣锛屽叾浠栨ā鍧楀彲浠ラ€氳繃杩欎簺鎵╁睍鐐规潵鎵╁睍鍔熻兘
 */
return [
    'type' => 'module', // module 鎴?theme
    'documentation' => 'extends.md', // 鏂囨。鏂囦欢璺緞锛堢浉瀵逛簬妯″潡鏍圭洰褰曪級
    'extends' => [
        'ExtensionPointName' => [
            'path' => 'extends/module/妯″潡鍚?ExtensionPointName',
            'type' => ['module'], // 鏀寔鐨勬墿灞曠被鍨嬶細module, theme
            'description' => '鎵╁睍鐐规弿杩?,
            'required' => true, // 鏄惁蹇呴』瀹炵幇鎺ュ彛
            'multiple' => true,  // 鏄惁鍏佽澶氫釜瀹炵幇
            'interface' => '鍛藉悕绌洪棿\Interface\InterfaceName', // 鎺ュ彛绫诲悕锛堝彲閫夛級
            'details' => [      // 璇︾粏淇℃伅锛堝彲閫夛級
                // 鎵╁睍鐐圭壒瀹氱殑璇︾粏淇℃伅
            ]
        ],
        // 鍙互瀹氫箟澶氫釜鎵╁睍鐐?    ]
];
```

### 瀛楁璇存槑

#### 椤跺眰瀛楁

- **type**: 鎵╁睍绫诲瀷锛宍module` 鎴?`theme`
- **documentation**: 鏂囨。鏂囦欢璺緞锛岀浉瀵逛簬妯″潡鏍圭洰褰曪紝濡?`extends.md` 鎴?`doc/鎵╁睍寮€鍙戞枃妗?md`
- **extends**: 鎵╁睍鐐瑰畾涔夋暟缁?
#### 鎵╁睍鐐瑰瓧娈?
- **path**: 鎵╁睍鏂囦欢璺緞妯℃澘锛屾敮鎸佸崰浣嶇濡?`{ModuleName}`, `{ExtensionName}` 绛?- **type**: 鏀寔鐨勬墿灞曠被鍨嬫暟缁勶紝`['module']` 鎴?`['module', 'theme']`
- **description**: 鎵╁睍鐐规弿杩?- **required**: 鏄惁蹇呴』瀹炵幇鎺ュ彛锛宍true` 鎴?`false`
- **multiple**: 鏄惁鍏佽澶氫釜瀹炵幇锛宍true` 鎴?`false`
- **interface**: 鎺ュ彛绫诲悕锛堝鏋滄墿灞曠偣闇€瑕佸疄鐜版帴鍙ｏ級
- **details**: 璇︾粏淇℃伅鏁扮粍锛堝彲閫夛級锛屽彲浠ュ寘鍚枃浠剁粨鏋勩€佷娇鐢ㄧず渚嬬瓑

## 鍒涘缓姝ラ

### 姝ラ 1锛氬垱寤?Extends 瑙勭害鏂囦欢 (extends.php)

鍦ㄦā鍧楁牴鐩綍鍒涘缓 `extends.php` 鏂囦欢锛?
```php
<?php

declare(strict_types=1);

/*
 * 鏈枃浠剁敱 绉嬫灚闆侀 缂栧啓锛屾墍鏈夎В閲婃潈褰扐iweline鎵€鏈夈€? * 閭锛歛iweline@qq.com
 * 缃戝潃锛歛iweline.com
 * 璁哄潧锛歨ttps://bbs.aiweline.com
 */

/**
 * 妯″潡鍚?妯″潡鎵╁睍瑙勭害
 * 
 * 鏈枃浠跺畾涔変簡 妯″潡鍚?妯″潡鎻愪緵鐨勬墿灞曠偣锛屽叾浠栨ā鍧楀彲浠ラ€氳繃杩欎簺鎵╁睍鐐规潵鎵╁睍鍔熻兘
 */
return [
    'type' => 'module', // module 鎴?theme
    'documentation' => 'extends.md', // 鏂囨。鏂囦欢璺緞锛堢浉瀵逛簬妯″潡鏍圭洰褰曪級
    'extends' => [
        'ExtensionPointName' => [
            'path' => 'extends/module/妯″潡鍚?ExtensionPointName',
            'type' => ['module'], // 鏀寔鐨勬墿灞曠被鍨?            'description' => '鎵╁睍鐐规弿杩帮紝璇存槑鐢ㄩ€斿拰浣跨敤鍦烘櫙',
            'required' => true, // 鏄惁蹇呴』瀹炵幇鎺ュ彛
            'multiple' => true,  // 鏄惁鍏佽澶氫釜瀹炵幇
            'interface' => '鍛藉悕绌洪棿\Interface\InterfaceName', // 鎺ュ彛绫诲悕锛堝鏋滈渶瑕佸疄鐜版帴鍙ｏ級
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/妯″潡鍚?ExtensionPointName/{ExtensionName}.php',
                    'description' => '鎵╁睍瀹炵幇绫讳綅缃鏄?,
                    'example' => 'app/code/YourModule/extends/module/妯″潡鍚?ExtensionPointName/YourExtension.php',
                ],
                'interface' => [
                    'interface' => '鍛藉悕绌洪棿\Interface\InterfaceName',
                    'description' => '鎵╁睍蹇呴』瀹炵幇鐨勬帴鍙?,
                    'required_methods' => [
                        'method1' => '鏂规硶1璇存槑',
                        'method2' => '鏂规硶2璇存槑',
                    ],
                ],
            ],
        ],
    ],
];
```

### 姝ラ 2锛氬垱寤?Extends 鏂囨。鏂囦欢 (extends.md)

鍦ㄦā鍧楁牴鐩綍鍒涘缓 `extends.md` 鏂囦欢锛?
```markdown
# 妯″潡鍚?妯″潡鎵╁睍鏂囨。

## 姒傝堪

妯″潡鍚?妯″潡鎻愪緵浜嗗涓墿灞曠偣锛屽厑璁稿叾浠栨ā鍧楁墿灞曞姛鑳姐€傛湰鏂囨。璇︾粏璇存槑濡備綍浣跨敤杩欎簺鎵╁睍鐐广€?
## 蹇€熷紑濮?
### 1. 鍒涘缓鎵╁睍鐩綍

鍦ㄦ偍鐨勬ā鍧椾腑鍒涘缓浠ヤ笅鐩綍缁撴瀯锛?
\`\`\`
app/code/YourModule/
鈹斺攢鈹€ extends/
    鈹斺攢鈹€ module/
        鈹斺攢鈹€ 妯″潡鍚?
            鈹斺攢鈹€ ExtensionPointName/
                鈹斺攢鈹€ YourExtension.php
\`\`\`

### 2. 瀹炵幇鎵╁睍绫?
鍒涘缓鎵╁睍绫伙紝瀹炵幇鎺ュ彛锛堝鏋滃畾涔変簡鎺ュ彛锛夛細

\`\`\`php
<?php
declare(strict_types=1);

namespace YourModule\Extends\妯″潡鍚峔ExtensionPointName;

use 鍛藉悕绌洪棿\Interface\InterfaceName;

class YourExtension implements InterfaceName
{
    // 瀹炵幇鎺ュ彛鏂规硶
}
\`\`\`

## 璇︾粏璇存槑

### ExtensionPointName 鎵╁睍鐐?
**璺緞**: \`extends/module/妯″潡鍚?ExtensionPointName\`

**鎺ュ彛**: \`鍛藉悕绌洪棿\Interface\InterfaceName\`

**鐢ㄩ€?*: 鎵╁睍鐐硅缁嗙敤閫旇鏄?
**瑕佹眰**:
- 蹇呴』瀹炵幇 \`InterfaceName\` 鎺ュ彛
- 蹇呴』瀹炵幇鎵€鏈夋帴鍙ｆ柟娉?- 鍏佽澶氫釜瀹炵幇

#### 鎺ュ彛鏂规硶璇存槑

- **method1()**: 鏂规硶1璇存槑
- **method2()**: 鏂规硶2璇存槑

## 浣跨敤鍦烘櫙

### 鍦烘櫙1锛氬叿浣撲娇鐢ㄥ満鏅鏄?
\`\`\`php
// 绀轰緥浠ｇ爜
\`\`\`

## 鏈€浣冲疄璺?
1. **鍛藉悕瑙勮寖**: 浣跨敤娓呮櫚銆佸敮涓€鐨勭被鍚?2. **閿欒澶勭悊**: 瀹屽杽寮傚父澶勭悊鍜岄敊璇秷鎭?3. **鏂囨。娉ㄩ噴**: 涓烘墍鏈夋柟娉曟坊鍔犺缁嗙殑鏂囨。娉ㄩ噴
4. **鍗曞厓娴嬭瘯**: 缂栧啓鍗曞厓娴嬭瘯纭繚鎵╁睍鐐规甯稿伐浣?
## 甯歌闂

### Q: 濡備綍鐭ラ亾鎴戠殑鎵╁睍鏄惁琚姞杞斤紵

A: 绯荤粺浼氬湪 \`generated/extends.php\` 鏂囦欢涓褰曟墍鏈夋墿灞曚俊鎭紝鎮ㄥ彲浠ユ煡鐪嬭鏂囦欢纭銆?
## 鐩稿叧鏂囨。

璇︾粏寮€鍙戞枃妗ｈ鍙傝€冿細\`doc/鎵╁睍寮€鍙戞枃妗?md\`
```

### 姝ラ 3锛氬垱寤烘墿灞曟枃妗ｇ洰褰曪紙鍙€変絾鎺ㄨ崘锛?
鍦?`doc/` 鐩綍涓嬪垱寤烘墿灞曠浉鍏崇殑璇︾粏鏂囨。锛?
```
app/code/妯″潡鍚?
鈹斺攢鈹€ doc/
    鈹溾攢鈹€ 鎵╁睍寮€鍙戞枃妗?md
    鈹斺攢鈹€ 鎵╁睍瑙勭害璇存槑.md
```

## 鎵╁睍鐐圭被鍨?
### 1. 鎺ュ彛鎵╁睍鐐?
闇€瑕佸疄鐜扮壒瀹氭帴鍙ｇ殑鎵╁睍鐐癸細

```php
'Adapter' => [
    'path' => 'extends/module/妯″潡鍚?Adapter',
    'interface' => '鍛藉悕绌洪棿\Interface\AdapterInterface',
    'description' => '閫傞厤鍣ㄦ墿灞曠偣锛岀敤浜庢墿灞曢€傞厤鍔熻兘',
    'required' => true, // 蹇呴』瀹炵幇鎺ュ彛
    'multiple' => true  // 鍏佽澶氫釜瀹炵幇
]
```

### 2. 鏂囦欢鎵╁睍鐐?
鍩轰簬鏂囦欢缁撴瀯鐨勬墿灞曠偣锛堝閰嶇疆鏂囦欢銆佹ā鏉挎枃浠剁瓑锛夛細

```php
'MetaConvention' => [
    'path' => 'extends/妯″潡鍚?{ModuleName}/@meta.json',
    'type' => ['module'],
    'description' => '鍏冩暟鎹绾︽枃浠舵墿灞曠偣',
    'required' => false, // 鍙€?    'multiple' => true,  // 鍏佽澶氫釜瀹炵幇
    'details' => [
        'file_location' => [
            'path' => 'extends/妯″潡鍚?{ModuleName}/@meta.json',
            'description' => '鍏冩暟鎹绾︽枃浠朵綅缃?,
        ],
    ],
]
```

### 3. 鐩綍鎵╁睍鐐?
鍩轰簬鐩綍缁撴瀯鐨勬墿灞曠偣锛堝閮ㄤ欢銆佷富棰樼瓑锛夛細

```php
'Widget' => [
    'path' => 'extends/Weline_Widget/Weline_Widget/{type}/{name}',
    'type' => ['module', 'theme'],
    'description' => 'Widget 閮ㄤ欢鎵╁睍鐐?,
    'required' => false,
    'multiple' => true,
    'details' => [
        'file_structure' => [
            'widget.php' => '閮ㄤ欢瑙勭害鏂囦欢锛堝繀闇€锛?,
            'template.phtml' => '閮ㄤ欢妯℃澘鏂囦欢锛堝繀闇€锛?,
            'Block.php' => 'Block 绫伙紙鍙€夛級',
        ],
    ],
]
```

## 璺緞鍗犱綅绗?
鎵╁睍鐐硅矾寰勬敮鎸佷互涓嬪崰浣嶇锛?
- **{ModuleName}**: 鎵╁睍妯″潡鐨勫悕绉帮紙濡?`YourModule`锛?- **{ExtensionName}**: 鎵╁睍瀹炵幇鐨勫悕绉帮紙濡?`YourExtension`锛?- **{type}**: 鎵╁睍绫诲瀷锛堝 `header`, `footer`锛?- **{name}**: 鎵╁睍鍚嶇О锛堝 `default`, `minimal`锛?
## 楠岃瘉妫€鏌?
绯荤粺浼氬湪鏋勫缓鎵╁睍娉ㄥ唽琛ㄦ椂鑷姩妫€鏌ワ細

1. **瑙勭害鏂囦欢妫€鏌?*锛氭鏌ユ槸鍚﹀瓨鍦?`extends.php` 鏂囦欢
2. **鏂囨。鏂囦欢妫€鏌?*锛氭鏌ユ槸鍚﹀瓨鍦?`extends.md` 鏂囦欢
3. **鏂囨。鍐呭妫€鏌?*锛氭鏌?`extends.md` 鏄惁鍖呭惈蹇呴渶绔犺妭锛堟杩般€佸揩閫熷紑濮嬨€佽缁嗚鏄庛€佺ず渚嬶級
4. **浠ｇ爜绀轰緥妫€鏌?*锛氭鏌ユ枃妗ｄ腑鏄惁鍖呭惈浠ｇ爜绀轰緥
5. **doc鐩綍妫€鏌?*锛氭鏌ユ槸鍚﹀瓨鍦?`doc/` 鐩綍鍙婃墿灞曠浉鍏虫枃妗?
濡傛灉缂哄皯瑙勭害鎴栨枃妗ｏ紝绯荤粺浼氳褰曡鍛婁俊鎭€?
## 鏈€浣冲疄璺?
### 1. 鎵╁睍鐐瑰懡鍚?
- 浣跨敤娓呮櫚銆佹弿杩版€х殑鎵╁睍鐐瑰悕绉?- 浣跨敤 PascalCase 鍛藉悕锛堝 `PaymentMethod`, `ShippingMethod`锛?- 閬垮厤浣跨敤杩囦簬閫氱敤鐨勫悕绉?
### 2. 璺緞璁捐

- 浣跨敤娓呮櫚鐨勭洰褰曠粨鏋?- 鏀寔鍗犱綅绗︿互鎻愪緵鐏垫椿鎬?- 鑰冭檻妯″潡鍜屼富棰樹袱绉嶆墿灞曠被鍨?
### 3. 鎺ュ彛璁捐

- 瀹氫箟娓呮櫚鐨勬帴鍙ｏ紝鍖呭惈鎵€鏈夊繀闇€鏂规硶
- 鎻愪緵璇︾粏鐨勬帴鍙ｆ枃妗?- 鑰冭檻鍚戝悗鍏煎鎬?
### 4. 鏂囨。缂栧啓

- 鎻愪緵瀹屾暣鐨勫揩閫熷紑濮嬫寚鍗?- 鍖呭惈璇︾粏鐨勬帴鍙ｆ柟娉曡鏄?- 鎻愪緵澶氫釜浣跨敤鍦烘櫙绀轰緥
- 鍒楀嚭甯歌闂鍜屾渶浣冲疄璺?
### 5. 璇︾粏淇℃伅

- 鍦?`details` 瀛楁涓彁渚涙墿灞曠偣鐗瑰畾鐨勮缁嗕俊鎭?- 鍖呭惈鏂囦欢缁撴瀯璇存槑
- 鎻愪緵璺緞绀轰緥

## 甯歌閿欒

### 閿欒 1锛氱己灏?extends.php 鏂囦欢

**闂**锛氭病鏈夊垱寤?`extends.php` 鏂囦欢

**瑙ｅ喅**锛氬湪妯″潡鏍圭洰褰曞垱寤?`extends.php` 鏂囦欢骞跺畾涔夋墿灞曠偣

### 閿欒 2锛氱己灏?extends.md 鏂囨。

**闂**锛氬湪 `extends.php` 涓寚瀹氫簡 `documentation` 瀛楁锛屼絾鏂囨。鏂囦欢涓嶅瓨鍦?
**瑙ｅ喅**锛氬湪妯″潡鏍圭洰褰曞垱寤?`extends.md` 鏂囦欢骞剁紪鍐欏畬鏁存枃妗?
### 閿欒 3锛氭枃妗ｅ唴瀹逛笉瀹屾暣

**闂**锛歚extends.md` 缂哄皯蹇呴渶绔犺妭锛堟杩般€佸揩閫熷紑濮嬨€佽缁嗚鏄庛€佺ず渚嬶級

**瑙ｅ喅**锛氱‘淇濇枃妗ｅ寘鍚墍鏈夊繀闇€绔犺妭鍜屼唬鐮佺ず渚?
### 閿欒 4锛氳矾寰勫崰浣嶇浣跨敤閿欒

**闂**锛氳矾寰勪腑鐨勫崰浣嶇鏍煎紡涓嶆纭?
**瑙ｅ喅**锛氫娇鐢ㄦ纭殑鍗犱綅绗︽牸寮忥紝濡?`{ModuleName}`, `{ExtensionName}`

### 閿欒 5锛氭帴鍙ｅ畾涔変笉瀹屾暣

**闂**锛氬畾涔変簡 `interface` 瀛楁锛屼絾鎺ュ彛绫讳笉瀛樺湪鎴栨柟娉曚笉瀹屾暣

**瑙ｅ喅**锛氱‘淇濇帴鍙ｇ被瀛樺湪涓斿寘鍚墍鏈夊繀闇€鏂规硶

### 閿欒 6锛氭墿灞曠偣璺緞涓庡疄闄呮枃浠朵笉鍖归厤

**闂**锛氭墿灞曠偣璺緞瀹氫箟涓庡疄闄呮墿灞曟枃浠朵綅缃笉鍖归厤

**瑙ｅ喅**锛氱‘淇濊矾寰勫畾涔夋纭紝鎵╁睍鏂囦欢鎸夌収璺緞缁撴瀯缁勭粐

## 绀轰緥锛氬畬鏁寸殑 Extends 鍒涘缓

### 绀轰緥 1锛氬垱寤烘帴鍙ｆ墿灞曠偣

**1. 鍒涘缓 extends.php**锛?
```php
<?php

declare(strict_types=1);

/**
 * Weline_Ai 妯″潡鎵╁睍瑙勭害
 */
return [
    'type' => 'module',
    'documentation' => 'extends.md',
    'extends' => [
        'Adapter' => [
            'path' => 'extends/module/Weline_Ai/Adapter',
            'interface' => 'Weline\Ai\Interface\ScenarioAdapterInterface',
            'description' => '鍦烘櫙閫傞厤鍣ㄦ墿灞曠偣锛岀敤浜庢墿灞?AI 鍦烘櫙閫傞厤鍔熻兘',
            'required' => true,
            'multiple' => true
        ]
    ]
];
```

**2. 鍒涘缓 extends.md**锛?
```markdown
# Weline_Ai 妯″潡鎵╁睍鏂囨。

## 姒傝堪

Weline_Ai 妯″潡鎻愪緵浜嗗涓墿灞曠偣锛屽厑璁稿叾浠栨ā鍧楁墿灞?AI 鍔熻兘銆?
## 蹇€熷紑濮?
### 鍒涘缓鍦烘櫙閫傞厤鍣?
1. 鍦ㄦ偍鐨勬ā鍧椾腑鍒涘缓鎵╁睍鐩綍锛歕`extends/module/Weline_Ai/Adapter/\`
2. 鍒涘缓閫傞厤鍣ㄧ被锛屽疄鐜?\`Weline\Ai\Interface\ScenarioAdapterInterface\` 鎺ュ彛
3. 瀹炵幇鎵€鏈夊繀闇€鐨勬柟娉?
### 绀轰緥浠ｇ爜

\`\`\`php
<?php
namespace Weline\MyModule\Extends\Weline_Ai\Adapter;

use Weline\Ai\Interface\ScenarioAdapterInterface;

class MyCustomAdapter implements ScenarioAdapterInterface
{
    public function getCode(): string
    {
        return 'my_custom_adapter';
    }

    public function getName(): string
    {
        return '鎴戠殑鑷畾涔夐€傞厤鍣?;
    }

    // ... 瀹炵幇鍏朵粬蹇呴渶鏂规硶
}
\`\`\`

## 璇︾粏璇存槑

### Adapter 鎵╁睍鐐?
**璺緞**: \`extends/module/Weline_Ai/Adapter\`

**鎺ュ彛**: \`Weline\Ai\Interface\ScenarioAdapterInterface\`

**鐢ㄩ€?*: 鎵╁睍 AI 鍦烘櫙閫傞厤鍔熻兘

**瑕佹眰**:
- 蹇呴』瀹炵幇 \`ScenarioAdapterInterface\` 鎺ュ彛
- 蹇呴』瀹炵幇鎵€鏈夋帴鍙ｆ柟娉?- 鍏佽澶氫釜瀹炵幇
```

**3. 鍒涘缓鎺ュ彛绫?*锛堝鏋滀笉瀛樺湪锛夛細

```php
<?php
namespace Weline\Ai\Interface;

interface ScenarioAdapterInterface
{
    public function getCode(): string;
    public function getName(): string;
    public function getDescription(): string;
    // ... 鍏朵粬鏂规硶
}
```

### 绀轰緥 2锛氬垱寤烘枃浠舵墿灞曠偣

**1. 鍒涘缓 extends.php**锛?
```php
<?php

declare(strict_types=1);

/**
 * Weline_Meta 妯″潡鎵╁睍瑙勭害
 */
return [
    'type' => 'module',
    'documentation' => 'doc/@meta.json瑙勭害鏂囦欢璇存槑.md',
    'extends' => [
        'MetaConvention' => [
            'path' => 'extends/Weline_Meta/{ModuleName}/@meta.json',
            'type' => ['module'],
            'description' => 'Meta 鍏冩暟鎹绾︽枃浠舵墿灞曠偣',
            'required' => false,
            'multiple' => true,
            'details' => [
                'file_location' => [
                    'path' => 'extends/Weline_Meta/{ModuleName}/@meta.json',
                    'description' => '鍏冩暟鎹绾︽枃浠朵綅缃?,
                ],
            ],
        ],
    ],
];
```

**2. 鍒涘缓鏂囨。鏂囦欢**锛?
```markdown
# Weline_Meta 妯″潡鎵╁睍鏂囨。

## 姒傝堪

Weline_Meta 妯″潡鎻愪緵浜嗕竴涓厓鏁版嵁瑙勭害绯荤粺锛屽厑璁稿叾浠栨ā鍧楅€氳繃鍒涘缓 \`@meta.json\` 鏂囦欢鏉ュ畾涔夊拰绠＄悊鍏冩暟鎹粨鏋勩€?
## 蹇€熷紑濮?
### 1. 鍒涘缓 Meta 瑙勭害鏂囦欢

鍦ㄦ偍鐨勬ā鍧椾腑鍒涘缓浠ヤ笅鐩綍缁撴瀯锛?
\`\`\`
app/code/YourModule/
鈹斺攢鈹€ extends/
    鈹斺攢鈹€ Weline_Meta/
        鈹斺攢鈹€ YourModule/
            鈹斺攢鈹€ @meta.json
\`\`\`

### 2. 缂栧啓 @meta.json 鏂囦欢

\`\`\`json
{
    "meta": {
        "base_path": "YourModule::view/templates",
        "namespace": {
            "layouts": {
                "name": "甯冨眬",
                "description": "椤甸潰甯冨眬妯℃澘",
                "type": "layout",
                "path": "layouts"
            }
        }
    }
}
\`\`\`
```

## 妫€鏌ユ竻鍗?
鍒涘缓 Extends 鏃讹紝纭繚瀹屾垚浠ヤ笅妫€鏌ワ細

- [ ] 鍒涘缓浜?`extends.php` 鏂囦欢
- [ ] 瀹氫箟浜嗘墿灞曠偣鐨勫畬鏁翠俊鎭紙path銆乼ype銆乨escription绛夛級
- [ ] 濡傛灉鎵╁睍鐐归渶瑕佹帴鍙ｏ紝瀹氫箟浜?`interface` 瀛楁
- [ ] 鍒涘缓浜?`extends.md` 鏂囨。鏂囦欢
- [ ] 鏂囨。璺緞鍦?`extends.php` 涓纭寚瀹?- [ ] 鏂囨。鍐呭瀹屾暣锛堝寘鎷杩般€佸揩閫熷紑濮嬨€佽缁嗚鏄庛€佺ず渚嬶級
- [ ] 鏂囨。涓寘鍚唬鐮佺ず渚?- [ ] 鍒涘缓浜?`doc/` 鐩綍锛堟帹鑽愶級
- [ ] 鍦?`doc/` 鐩綍涓嬪垱寤轰簡鎵╁睍鐩稿叧鏂囨。锛堟帹鑽愶級
- [ ] 濡傛灉瀹氫箟浜嗘帴鍙ｏ紝纭繚鎺ュ彛绫诲瓨鍦ㄤ笖瀹屾暣
- [ ] 杩愯浜?`setup:upgrade` 鎴栫浉鍏冲懡浠ゆ洿鏂版敞鍐岃〃

## ⚠️ 重要说明：定义 vs 实现

### 本技能：定义扩展点（Defining）

用于创建新的扩展点，让其他模块可以扩展你的功能。

**使用场景：**
- 创建 extends.php 规约文件
- 定义扩展接口（Interface）
- 编写 extends.md 文档
- 创建 Registry Service 收集实现

### 如果要实现扩展点（Implementing）

请使用 `implement-extends` 技能。

**使用场景：**
- 为 Weline_Seo 添加 Sitemap Provider
- 为 Weline_Ai 添加场景适配器
- 实现其他模块定义的扩展点

### 快速判断

| 问题 | 使用技能 |
|------|---------|
| 我想让其他模块扩展我的功能 | `create-extends`（本技能） |
| 我想扩展其他模块的功能 | `implement-extends` |
| 我要创建 extends.php 文件 | `create-extends`（本技能） |
| 我要在 extends/module/ 创建类 | `implement-extends` |

## 鐩稿叧璧勬簮

- [ExtendsRegistry 婧愮爜](../../app/code/Weline/Framework/Extends/ExtendsRegistry.php)
- [ExtendsScanner 婧愮爜](../../app/code/Weline/Framework/Extends/ExtendsScanner.php)
- [CompletenessChecker 婧愮爜](../../app/code/Weline/Framework/Extends/CompletenessChecker.php)

## Related Skills

- `implement-extends` - **实现扩展点**：在 extends/module/ 目录实现其他模块的扩展点
- `module-development` - 完整的模块开发流程