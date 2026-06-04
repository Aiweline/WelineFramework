# CTA 杞寲浜嬩欢璺熻釜鍔熻兘 - 娴嬭瘯鎶ュ憡

## 娴嬭瘯鏃ユ湡
2025-10-27

## 娴嬭瘯鐜
- 绯荤粺锛歐indows 10
- PHP锛?.x
- 鏁版嵁搴擄細MySQL (weline)
- 琛ㄥ悕锛歡uolairen_page_builder_page

## 娴嬭瘯鍐呭

### 1. 鏁版嵁搴撳瓧娈甸獙璇?鉁?

**娴嬭瘯缁撴灉锛?*
```
瀛楁鍚嶏細cta_event_name
绫诲瀷锛歏ARCHAR(100)
娉ㄩ噴锛欳TA杞寲浜嬩欢鍚嶇О
浣嶇疆锛氬湪 fb_pixel_id 涔嬪悗
榛樿鍊硷細NULL
```

### 2. 鍚庡彴琛ㄥ崟鏄剧ず 鉁?

**娴嬭瘯椤甸潰锛?* `pagebuilder/backend/page/edit?id=1`

**娴嬭瘯缁撴灉锛?*
- 鉁?銆岃窡韪唬鐮併€嶅尯鍩熸纭樉绀恒€孋TA 杞寲浜嬩欢鍚嶇О銆嶈緭鍏ユ
- 鉁?杈撳叆妗嗗甫鏈夊府鍔╀俊鎭浘鏍?
- 鉁?杈撳叆妗嗘湁 placeholder 鎻愮ず锛歚渚嬪锛歮oney_calendar_signup (鐣欑┖鑷姩鐢熸垚)`
- 鉁?涓嬫柟鏄剧ず甯姪鏂囨湰锛歚鐢ㄤ簬 Google Analytics 鍜?Facebook Pixel 浜嬩欢璺熻釜銆傜暀绌烘椂灏嗚嚜鍔ㄧ敓鎴愪负锛?椤甸潰鍙ユ焺>_form_submit`

### 3. 鑷姩鐢熸垚榛樿鍊?鉁?

**娴嬭瘯鍦烘櫙锛?* 缂栬緫宸插瓨鍦ㄧ殑椤甸潰锛坈ta_event_name 瀛楁涓虹┖锛?

**椤甸潰鏁版嵁锛?*
- 椤甸潰 ID锛?
- 椤甸潰鍙ユ焺锛歚index`
- CTA 浜嬩欢鍚嶇О锛堟暟鎹簱锛夛細NULL

**娴嬭瘯缁撴灉锛?*
- 鉁?缂栬緫椤甸潰鏃讹紝杈撳叆妗嗚嚜鍔ㄦ樉绀猴細`index_form_submit`
- 鉁?绗﹀悎棰勬湡鐨勮嚜鍔ㄧ敓鎴愯鍒欙細`{椤甸潰鍙ユ焺}_form_submit`

### 4. 淇濆瓨鍔熻兘娴嬭瘯 鉁?

**娴嬭瘯姝ラ锛?*
1. 璁块棶缂栬緫椤甸潰锛歚pagebuilder/backend/page/edit?id=1`
2. CTA 浜嬩欢鍚嶇О杈撳叆妗嗘樉绀猴細`index_form_submit`
3. 鐐瑰嚮銆屾洿鏂伴〉闈€嶆寜閽?
4. 瑙傚療淇濆瓨缁撴灉

**娴嬭瘯缁撴灉锛?*
- 鉁?鏄剧ず鎴愬姛娑堟伅锛?*銆屾搷浣滄垚鍔燂紒 椤甸潰鏇存柊鎴愬姛锛併€?*
- 鉁?椤甸潰淇濇寔鍦ㄧ紪杈戦〉闈紙URL鏈彉锛?
- 鉁?鏃燬QL閿欒
- 鉁?鏁版嵁搴撻獙璇侊細`cta_event_name = 'index_form_submit'`

### 5. 鏁版嵁搴撴寔涔呭寲楠岃瘉 鉁?

**楠岃瘉SQL锛?*
```sql
SELECT page_id, handle, cta_event_name 
FROM guolairen_page_builder_page 
WHERE page_id = 1;
```

**楠岃瘉缁撴灉锛?*
```
椤甸潰 ID: 1
椤甸潰鍙ユ焺: index
CTA 浜嬩欢鍚嶇О: index_form_submit
```

## 鍔熻兘鐗规€х‘璁?

### 鉁?宸插疄鐜扮殑鍔熻兘

1. **鏁版嵁搴撳眰闈?*
   - [x] 娣诲姞 `cta_event_name` 瀛楁鍒版暟鎹簱
   - [x] 瀛楁绫诲瀷锛歏ARCHAR(100) NULL
   - [x] 瀛楁浣嶇疆锛氬湪 `fb_pixel_id` 涔嬪悗

2. **鍚庡彴琛ㄥ崟**
   - [x] 鍦ㄨ窡韪唬鐮佸尯鍩熸坊鍔犺緭鍏ユ
   - [x] 鏄剧ず甯姪淇℃伅鍥炬爣
   - [x] 鏄剧ず placeholder 鎻愮ず
   - [x] 鏄剧ず璇︾粏璇存槑鏂囨湰

3. **鑷姩鐢熸垚閫昏緫**
   - [x] 缂栬緫鏃跺鏋滀负绌猴紝鏄剧ず鑷姩鐢熸垚鐨勫€?
   - [x] 淇濆瓨鏃跺鏋滀负绌猴紝鑷姩鐢熸垚骞朵繚瀛?
   - [x] 鐢熸垚瑙勫垯锛歚{椤甸潰鍙ユ焺}_form_submit`

4. **淇濆瓨鍔熻兘**
   - [x] 鏂板缓椤甸潰鏃惰嚜鍔ㄧ敓鎴?
   - [x] 缂栬緫椤甸潰鏃惰嚜鍔ㄧ敓鎴?
   - [x] 鑷畾涔夊€间紭鍏堜簬鑷姩鐢熸垚

5. **鍓嶇浜嬩欢璺熻釜**
   - [x] Google Analytics 4 浜嬩欢璺熻釜
   - [x] Facebook Pixel 浜嬩欢璺熻釜
   - [x] 浣跨敤鑷畾涔夋垨鑷姩鐢熸垚鐨勪簨浠跺悕绉?

## 浠ｇ爜淇敼娓呭崟

### 淇敼鐨勬枃浠?

1. `app/code/GuoLaiRen/PageBuilder/Model/Page.php`
   - 娣诲姞 `fields_CTA_EVENT_NAME` 甯搁噺
   - 鍦?`upgrade()` 鏂规硶涓坊鍔犲瓧娈佃縼绉婚€昏緫

2. `app/code/GuoLaiRen/PageBuilder/Controller/Backend/Page.php`
   - `postCreate()` 鏂规硶锛氭坊鍔犺嚜鍔ㄧ敓鎴愰€昏緫
   - `postEdit()` 鏂规硶锛氭坊鍔犺嚜鍔ㄧ敓鎴愰€昏緫

3. `app/code/GuoLaiRen/PageBuilder/view/templates/Backend/Page/form.phtml`
   - 娣诲姞 CTA 浜嬩欢鍚嶇О杈撳叆妗?
   - 娣诲姞鑷姩鐢熸垚鏄剧ず閫昏緫

4. `app/code/GuoLaiRen/PageBuilder/view/templates/style/marketing-landing/content.phtml`
   - 娣诲姞浜嬩欢鍚嶇О閰嶇疆鍙橀噺
   - 娣诲姞 GA4 浜嬩欢璺熻釜浠ｇ爜
   - 娣诲姞 Facebook Pixel 浜嬩欢璺熻釜浠ｇ爜

### 鏂板鐨勬枃浠?

1. `app/code/GuoLaiRen/PageBuilder/doc/鍔熻兘-CTA杞寲浜嬩欢璺熻釜.md`
   - 鍔熻兘璇存槑鏂囨。

## Git 鎻愪氦璁板綍

```
c17d6cbf - feat: Add CTA conversion event tracking for PageBuilder
00a7b959 - fix: Handle null value in cta_event_name field to avoid PHP 8.1+ deprecation warning
4be65b16 - feat: Auto-generate CTA event name when empty
```

## 娴嬭瘯缁撹

鉁?**鎵€鏈夋祴璇曢€氳繃锛佸姛鑳芥甯歌繍琛岋紒**

### 宸查獙璇佺殑鍔熻兘鐐?

- [x] 鏁版嵁搴撳瓧娈垫纭坊鍔?
- [x] 鍚庡彴琛ㄥ崟姝ｅ父鏄剧ず
- [x] 鑷姩鐢熸垚閫昏緫姝ｇ‘
- [x] 淇濆瓨鍔熻兘姝ｅ父
- [x] 鏁版嵁姝ｇ‘鎸佷箙鍖?
- [x] 鏃?PHP 閿欒
- [x] 鏃?SQL 閿欒
- [x] 鐢ㄦ埛浣撻獙鑹ソ

### 浣跨敤璇存槑

**鍦烘櫙 1锛氱暀绌鸿嚜鍔ㄧ敓鎴愶紙鎺ㄨ崘锛?*
1. 缂栬緫椤甸潰锛屼笉濉啓 CTA 浜嬩欢鍚嶇О
2. 淇濆瓨鏃惰嚜鍔ㄧ敓鎴愪负锛歚{椤甸潰鍙ユ焺}_form_submit`
3. 渚嬪锛氶〉闈㈠彞鏌勪负 `money-calendar`锛屽垯鐢熸垚 `money-calendar_form_submit`

**鍦烘櫙 2锛氳嚜瀹氫箟浜嬩欢鍚?*
1. 鍦?CTA 浜嬩欢鍚嶇О杈撳叆妗嗕腑杈撳叆鑷畾涔夊€?
2. 渚嬪锛歚custom_event_signup`
3. 淇濆瓨鍚庝娇鐢ㄨ嚜瀹氫箟鍊?

### 鍚庣画寤鸿

1. 寤鸿鍦?`install()` 鏂规硶涓篃娣诲姞璇ュ瓧娈碉紙鏂板畨瑁呮椂锛?
2. 鑰冭檻娣诲姞浜嬩欢鍚嶇О鏍煎紡楠岃瘉锛堝彧鍏佽瀛楁瘝銆佹暟瀛椼€佷笅鍒掔嚎锛?
3. 鑰冭檻鍦ㄥ垪琛ㄩ〉鏄剧ず浜嬩欢鍚嶇О
4. 鑰冭檻娣诲姞鎵归噺璁剧疆浜嬩欢鍚嶇О鍔熻兘

## 闄勫綍锛氭祴璇曞懡浠?

### 楠岃瘉瀛楁鏄惁瀛樺湪
```bash
php -r "require 'app/bootstrap.php'; use GuoLaiRen\PageBuilder\Model\Page; use Weline\Framework\Manager\ObjectManager; $p = ObjectManager::getInstance(Page::class); $pdo = $p->getConnection()->getConnector()->getLink(); $stmt = $pdo->query('DESCRIBE guolairen_page_builder_page'); while($row = $stmt->fetch(PDO::FETCH_ASSOC)) { if($row['Field'] == 'cta_event_name') { echo 'Found: ' . $row['Field'] . PHP_EOL; } }"
```

### 鏌ョ湅椤甸潰鏁版嵁
```bash
php -r "require 'app/bootstrap.php'; use GuoLaiRen\PageBuilder\Model\Page; use Weline\Framework\Manager\ObjectManager; $p = ObjectManager::getInstance(Page::class); $p->load(1); echo 'Handle: ' . $p->getData('handle') . PHP_EOL; echo 'Event Name: ' . $p->getData('cta_event_name') . PHP_EOL;"
```

