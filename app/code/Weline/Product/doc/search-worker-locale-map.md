# 商品搜索的公开标题语言映射

`ProductSearchProvider` 从搜索索引读取的 `localized_titles['']` 是内部默认标题桶。先由 `resolveDisplayTitle` 按当前locale及默认桶选择真实展示标题，再移除公开搜索行中的空locale键。其它真实locale条目和已选择的 `title` 保留，商品内容与搜索索引不改写。

原因：WelineBinaryCodec PHP编码器可以输出空map键，而PHP解码器及前台Worker解码器均拒绝空map键。真实Bagua搜索的两个商品同时在 `hits/{n}/payload/localized_titles` 与 `hits/{n}/localized_titles` 携带这个内部键，导致HTTP200二进制响应在浏览器报 `Invalid Weline map key`。修复归属Product公开投影边界，不能放宽通用协议解码器或删掉所有商品语言信息。

## 最小复现与验证

通过真实 `DetectWebsite::installNavigationScope('https://p05113ef3.test.weline.com/daocharms/')` 建立已发布目录Scope，再使用公开 `w_query('search', 'search', ['q'=>'Bagua', 'type'=>'product', 'area'=>'frontend'])` 获取真实商品。对响应递归查空键，再用官方 `WelineBinaryCodec::encodePacket/decodePacket` 往返验证。

2026-10-02修复前：两件商品命中，22741字节，PHP解码失败 `Invalid UTF-8 map key`；浏览器真实Worker失败 `Invalid Weline map key`。修复后：两件商品命中，22569字节，PHP往返完全相等、原标题保留。以同一包交现有 `weline-api-worker.js` 的 `decodePacket` 在离线JS环境执行，成功解码两件商品；未修改Worker或协议。PHP语法与精确diff检查通过。

该开发验证只证明公开数据可传输，实际镇内Search→定位→原购买面板→购物车须正常WLS重载后单独运行验收。临时公共数据包位于dev/tmp，不包含密钥/账户/会话，不作为发布素材。
