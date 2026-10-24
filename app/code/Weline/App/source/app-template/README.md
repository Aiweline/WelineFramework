# Weline App Template · 长安汉服（Flutter）

> Weline_App 模块的权威 Flutter 模板源。汉服风（朱砂/奶油/水墨）+ 海外使用习惯（底部 Tab/大标题/无启动广告）。
> Token 权威：`dev/team/app-build-module/flutter-token-map.md`；API 契约：`dev/team/app-build-module/contracts.md` §1。

## 结构

```
lib/
├── main.dart                  # 入口：MaterialApp（Token 主题）→ 启动拉 config → HomeShell
├── tokens.dart                # AppColors/hanfuScheme/hanfuText/Space/Radii/appCardShadow（勿改值）
├── services/
│   ├── app_config.dart        # config/version/download 三接口封装（弱网静默降级缓存）
│   └── updater.dart           # UC6 自更新：版本比对 → Android 装 APK / iOS 跳 App Store
├── widgets/
│   └── home_shell.dart        # 底部 NavigationBar 4 tab（Home/Shop/Search/Account）
└── pages/
    ├── home_page.dart         # Hero-first 首页（新款大图=第一视线 + 横滑 + 分类）
    ├── shop_page.dart         # 商品瀑布流占位 + 下拉刷新
    ├── search_page.dart       # 搜索框 + 热搜词条
    └── account_page.dart      # 账户页：语言/货币行 + Check for Updates（UC6 落点）
对接说明.md                    # FlutterBuilder 占位符约定与对接步骤（Lead 派发改造用）
```

## 本地运行

```bash
cd app-template
flutter create . --platforms=web,android   # 补平台目录（首次）
flutter pub get
flutter run -d chrome \
  --dart-define=WELINE_BASE_URL=https://p05113ef3.test.weline.com:9555 \
  --dart-define=WELINE_APP_NAME=长安汉服
```

打包（对接 FlutterBuilder 后由 `php bin/w app:build` 触发；手动验证同参数）：
`flutter build web --release --no-tree-shake-icons --dart-define=…`

## 汉服元素落地点（对照 token-map §6 六条建议）

| # | 建议 | 模板落点 |
|---|---|---|
| 1 | Hero 卡宋体大标题 `[H]` | `home_page.dart` `_heroCard()`：NEW TODAY 徽标（字距 2）+ `headlineMedium` 宋体标题 |
| 2 | 墨线分隔 `[H]` | `account_page.dart` 设置组 `Divider(color: AppColors.line)`；各处 `Border.all(color: AppColors.line)` 发丝线 |
| 3 | 朱砂仅强调 `[H]` | 每屏一组：Tab 激活（home_shell）/ Update 主按钮（updater dialog）/ 更新徽标点（account）；全部实心，无空心描边印 |
| 4 | 奶油底 + 纸面卡 `[H]` | `main.dart` `scaffoldBackgroundColor: AppColors.cream`；卡片 `AppColors.paper` + 唯一 `appCardShadow` |
| 5 | 品牌汉字保留 | `home_page.dart` `长安汉服 · HANFU XI'AN`（宋体位）；正文全英文 |
| 6 | 水墨留白 `[H]` | 区块间 `Space.s6(32)`、半层顶 `Space.s7(48)`；删多余分割线 |

## 海外习惯 checklist（token-map §5 11 条对照）

✅ 4-tab NavigationBar（无底色块指示器）· ✅ 34px 大标题 · ✅ 系统返回手势 · ✅ 无启动广告（config 静默）· ✅ RefreshIndicator（Home/Shop）· ✅ 卡片 16 圆角单一密度 · ⏳ 详情半层 sheet（V2）· ✅ 每屏朱砂预算 1 组 · ✅ Account 内 Check for Updates · ✅ Language & Currency 行 · ✅ Tab 词条 i18n 落点已注释

## 平台说明

- Web：打包即用（T5 链路验证）。
- Android：需 JDK17+Android SDK（`app:doctor`/`app:install-deps`）；APK 签名走平台统一签名。
- iOS：需 macOS+完整 Xcode；V1 应用内自更新=跳 App Store（合规硬边界，不做热更新）。
