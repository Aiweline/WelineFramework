// shell_app.dart — 增强壳：WebView 容器 + 原生 5 Tab + 顶栏/角标/断网页（task-1 / T24）
// 协议：lib/shell/protocol_js.dart 注入 → window.WelineApp.postMessage → page_state.dart 解析
// 硬约束：禁止在 app/code 内 flutter build（L140）；构建走 /tmp/weline_shell_build
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:webview_flutter/webview_flutter.dart';
import 'package:webview_flutter_android/webview_flutter_android.dart';

import 'page_state.dart';
import 'protocol_js.dart';
import '../tokens.dart';

/// 站点基址（编译期注入；缺省生产域名）
const String kShellBaseUrl = String.fromEnvironment(
  'WELINE_BASE_URL',
  defaultValue: 'https://www.changanhanfu.com',
);

/// 同站域名（绝对链接会指向这些域，需在 WebView 内改写而非外跳）
const String kSiteHostsCsv = String.fromEnvironment(
  'WELINE_SITE_HOSTS',
  defaultValue: 'www.changanhanfu.com,changanhanfu.com',
);

/// 支付网关域名：必须留在 WebView 内（3DS/回跳），不能交系统浏览器
const Set<String> kPaymentHosts = {
  'checkout.stripe.com', 'api.stripe.com', 'hooks.stripe.com',
  'paypal.com', 'www.paypal.com', 'c.paypal.com', 'api-m.paypal.com',
};

/// 原生 5 Tab（协议 §3：切 Tab = 原生 loadURL，非网页跳转）
class _Tab {
  const _Tab(this.key, this.label, this.url, this.icon);
  final String key;
  final String label;
  final String url;
  final IconData icon;
}

const List<_Tab> kTabs = [
  _Tab('home', 'Home', '/', Icons.home_outlined),
  _Tab('shop', 'Shop', '/shop', Icons.storefront_outlined),
  _Tab('search', 'Search', '/search', Icons.search),
  _Tab('bag', 'Bag', '/cart', Icons.shopping_bag_outlined),
  _Tab('account', 'Account', '/customer/account', Icons.person_outline),
];

class ShellApp extends StatefulWidget {
  const ShellApp({super.key, this.initialPath = '/'});

  final String initialPath;

  @override
  State<ShellApp> createState() => _ShellAppState();
}

class _ShellAppState extends State<ShellApp> {
  late final WebViewController _controller;
  PageState _state = PageState.empty;
  bool _loading = true;
  bool _offline = false;
  int _activeTabIndex = 0;
  String _currentUrl = kShellBaseUrl;

  @override
  void initState() {
    super.initState();
    _controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setNavigationDelegate(_delegate())
      ..addJavaScriptChannel(
        'WelineApp',
        onMessageReceived: (msg) {
          setState(() => _state = PageState.fromMessage(msg.message));
        },
      )
      ..loadRequest(Uri.parse(kShellBaseUrl + widget.initialPath));
    // 注入协议脚本（页面开始时即可，幂等）
    _controller.runJavaScript(kProtocolScript).catchError((_) {});
  }

  NavigationDelegate _delegate() {
    return NavigationDelegate(
      onNavigationRequest: (req) {
        final uri = Uri.tryParse(req.url);
        if (uri == null || (uri.scheme != 'http' && uri.scheme != 'https')) {
          return NavigationDecision.navigate;
        }
        if (uri.host == Uri.parse(kShellBaseUrl).host) {
          return NavigationDecision.navigate;
        }
        // 同站绝对链接：改写到壳基址，留在 WebView 内
        final siteHosts = kSiteHostsCsv.split(',').map((e) => e.trim()).toSet();
        if (siteHosts.contains(uri.host)) {
          final target = kShellBaseUrl +
              uri.path +
              (uri.hasQuery ? '?${uri.query}' : '');
          _controller.loadRequest(Uri.parse(target));
          return NavigationDecision.prevent;
        }
        // 支付网关：留在 WebView（3DS/回跳）
        if (kPaymentHosts.contains(uri.host)) {
          return NavigationDecision.navigate;
        }
        // 其余外链：交系统浏览器
        launchUrl(uri, mode: LaunchMode.externalApplication);
        return NavigationDecision.prevent;
      },
      onPageStarted: (url) {
        setState(() {
          _loading = true;
          _offline = false;
          _currentUrl = url;
        });
      },
      onPageFinished: (url) async {
        _controller.runJavaScript(kProtocolScript).catchError((_) {});
        try {
          final canGo = await _controller.canGoBack();
          if (mounted) setState(() { _loading = false; _canGoBack = canGo; });
        } catch (_) {
          if (mounted) setState(() => _loading = false);
        }
      },
      onWebResourceError: (err) {
        // 主文档错误才算断网（子资源失败不提示）
        if (err.isForMainFrame != false) {
          setState(() => _offline = true);
        }
      },
      onHttpError: (err) {
        final code = err.response?.statusCode;
        if (code != null && code >= 500) {
          setState(() => _offline = true);
        }
      },
    );
  }

  bool _canGoBack = false;
  bool get _showTabs => _state.showsTabs;

  void _switchTab(int index) {
    final tab = kTabs[index];
    setState(() => _activeTabIndex = index);
    _controller.loadRequest(Uri.parse(kShellBaseUrl + tab.url));
  }

  Future<bool> _onWillPop() async {
    if (await _controller.canGoBack()) {
      await _controller.goBack();
      return false;
    }
    return true;
  }

  Future<void> _reload() async {
    setState(() => _offline = false);
    await _controller.reload();
  }

  void _share() {
    final url = _currentUrl;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(url),
        backgroundColor: AppColors.ink,
        behavior: SnackBarBehavior.floating,
        shape: const RoundedRectangleBorder(borderRadius: Radii.md),
      ),
    );
    // 系统分享由平台 channel 完成；V1 用剪贴板替代 share_plus（少一个依赖）
    // TODO(share): share_plus 接入（V2），或直接接收 URL 参数由系统 sheet 处理
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) async {
        if (!didPop) await _onWillPop();
      },
      child: Scaffold(
        backgroundColor: AppColors.cream,
        appBar: _topBar(context),
        body: _body(),
        bottomNavigationBar: _showTabs ? _bottomBar() : null,
      ),
    );
  }

  PreferredSizeWidget _topBar(BuildContext context) {
    return AppBar(
      backgroundColor: AppColors.paper,
      surfaceTintColor: Colors.transparent,
      foregroundColor: AppColors.ink,
      elevation: 0.5,
      title: Text(
        _state.title.isEmpty ? 'Weline' : _state.title,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w600, color: AppColors.ink),
      ),
      leading: IconButton(
        icon: const Icon(Icons.arrow_back, size: 22),
        onPressed: _canGoBack ? () => _onWillPop() : null,
      ),
      actions: [
        IconButton(icon: const Icon(Icons.ios_share, size: 20), onPressed: _share),
      ],
    );
  }

  /// Android 用混合合成（hybrid composition），避免平台视图盖住原生底栏/顶栏的命中区
  Widget _webView() {
    if (WebViewPlatform.instance is AndroidWebViewPlatform) {
      return WebViewWidget.fromPlatformCreationParams(
        params: AndroidWebViewWidgetCreationParams(
          controller: _controller.platform as AndroidWebViewController,
          displayWithHybridComposition: true,
        ),
      );
    }
    return WebViewWidget(controller: _controller);
  }

  Widget _body() {
    if (_offline) return _offlineView();
    return Stack(
      children: [
        _webView(),
        if (_loading) _skeleton(), // 首屏骨架
      ],
    );
  }

  Widget _skeleton() {
    return Container(
      color: AppColors.cream,
      child: ListView(
        padding: const EdgeInsets.all(Space.s4),
        children: [
          Container(height: 220, decoration: BoxDecoration(color: AppColors.line.withValues(alpha: 0.35), borderRadius: Radii.lg)),
          const SizedBox(height: Space.s5),
          Container(height: 20, width: 160, color: AppColors.line.withValues(alpha: 0.35)),
          const SizedBox(height: Space.s3),
          Row(children: [
            Expanded(child: Container(height: 140, decoration: BoxDecoration(color: AppColors.line.withValues(alpha: 0.3), borderRadius: Radii.lg))),
            const SizedBox(width: Space.s3),
            Expanded(child: Container(height: 140, decoration: BoxDecoration(color: AppColors.line.withValues(alpha: 0.3), borderRadius: Radii.lg))),
          ]),
        ],
      ),
    );
  }

  Widget _offlineView() {
    return Center(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          const Icon(Icons.wifi_off, size: 48, color: AppColors.ink3),
          const SizedBox(height: Space.s3),
          const Text('No connection', style: TextStyle(color: AppColors.ink2)), // i18n 落点
          const SizedBox(height: Space.s5),
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: AppColors.cinnabar, // 本屏唯一强调组
              shape: const RoundedRectangleBorder(borderRadius: Radii.md),
            ),
            onPressed: _reload,
            child: const Text('Retry'),
          ),
        ],
      ),
    );
  }

  Widget _bottomBar() {
    // 按 data-layout 高亮（协议 §3）；状态未回传时按 URL 前缀兜底（§5.1）
    final layoutKey = PageState.tabKeyForLayout(_state.layout);
    final activeKey = layoutKey.isNotEmpty ? layoutKey : kTabs[_activeTabIndex].key;
    return NavigationBarTheme(
      data: NavigationBarThemeData(
        backgroundColor: AppColors.paper,
        indicatorColor: Colors.transparent, // 无底色块（海外习惯）
        height: 64,
        labelTextStyle: WidgetStateProperty.resolveWith((states) => TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.w500,
              color: states.contains(WidgetState.selected) ? AppColors.cinnabar : AppColors.ink2,
            )),
      ),
      child: NavigationBar(
        selectedIndex: kTabs.indexWhere((t) => t.key == activeKey),
        onDestinationSelected: _switchTab,
        destinations: [
          for (final t in kTabs)
            NavigationDestination(
              icon: _tabIcon(t, activeKey),
              selectedIcon: _tabIcon(t, activeKey, selected: true),
              label: t.label,
            ),
        ],
      ),
    );
  }

  Widget _tabIcon(_Tab tab, String activeKey, {bool selected = false}) {
    final color = tab.key == activeKey ? AppColors.cinnabar : AppColors.ink2;
    if (tab.key == 'bag' && _state.cartCount > 0) {
      // Bag 角标 = state.cartCount（MutationObserver 实时回传）
      return Badge(
        label: Text('${_state.cartCount}'),
        backgroundColor: AppColors.cinnabar,
        child: Icon(tab.icon, color: color),
      );
    }
    return Icon(tab.icon, color: color);
  }
}

