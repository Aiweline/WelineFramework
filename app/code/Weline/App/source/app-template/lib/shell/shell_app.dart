// shell_app.dart — 增强壳：WebView 容器 + 原生 5 Tab + 顶栏/角标/断网页（task-1 / T24）
// 协议：lib/shell/protocol_js.dart 注入 → window.WelineApp.postMessage → page_state.dart 解析
// 硬约束：禁止在 app/code 内 flutter build（L140）；构建走 /tmp/weline_shell_build
import 'dart:convert';

import 'package:flutter/foundation.dart';
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
        'WLDBG',
        onMessageReceived: (msg) {
          debugPrint('WLDBG ' + msg.message);
        },
      )
      ..addJavaScriptChannel(
        'WelineApp',
        onMessageReceived: (msg) {
          final raw = msg.message;
          try {
            final decoded = jsonDecode(raw);
            if (decoded is Map && decoded['nav'] is String) {
              final target = Uri.tryParse(decoded['nav'] as String);
              if (target != null) _controller.loadRequest(target);
              return;
            }
          } catch (_) {}
          setState(() => _state = PageState.fromMessage(raw));
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
        if (uri == null) return NavigationDecision.navigate;
        if (uri.scheme != 'http' && uri.scheme != 'https') {
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
        // reCAPTCHA / Google 嵌入资源：iframe 内加载，绝不能外跳（R49：曾把 Safari 拉到前台）
        if (uri.host.endsWith('google.com') ||
            uri.host.endsWith('gstatic.com') ||
            uri.host.endsWith('googleapis.com')) {
          return NavigationDecision.navigate;
        }
        // 其余外链：仅 http/https 交系统浏览器；其它 scheme（tel:/mailto:/itms-apps: 等）
        // 一律忽略——避免把 Safari/拨号盘拉到前台打断购物流程
        if (uri.scheme == 'http' || uri.scheme == 'https') {
          launchUrl(uri, mode: LaunchMode.externalApplication);
        }
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
        // AUTO-CHECKOUT（测试构建，kDebugMode 门控）：结算页自动填假数据 + 点提交订单
        if (kDebugMode) {
          _controller.runJavaScript('''
            window.__wlCoTimer && clearInterval(window.__wlCoTimer);
            window.__wlCoTimer = setInterval(function(){
              try {
                if (window.__wlCheckoutDone) return;
                var layout=(document.body?document.body.getAttribute('data-layout'):'')||'';
                if (layout!=='checkout') return;
                function wlfill(sel, val){
                  var el=document.querySelector(sel);
                  if (!el) return false;
                  var desc=Object.getOwnPropertyDescriptor(Object.getPrototypeOf(el),'value');
                  if (desc && desc.set) { desc.set.call(el, val); } else { el.value=val; }
                  el.dispatchEvent(new Event('input',{bubbles:true}));
                  el.dispatchEvent(new Event('change',{bubbles:true}));
                  return true;
                }
                var fields=[['input[name=name]','Lin Wei'],['input[name=phone]','+14155552210'],
                            ['input[name=email]','lin@example.com'],['input[name=postal_code]','94103'],
                            ['input[name=address]','1200 Market St'],['input[name=city]','San Francisco'],
                            ['input[name=street]','1200 Market St'],['input[name=address1]','1200 Market St'],
                            ['input[name=lastname]','Wei'],['input[name=firstname]','Lin']];
                var filled=[];
                fields.forEach(function(f){ if(wlfill(f[0],f[1])) filled.push(f[0]); });
                if (filled.length){
                  try { WLDBG.postMessage(JSON.stringify({checkoutFilled: filled})); } catch(e){}
                  window.__wlCheckoutDone=1;
                  var sub=document.querySelector('.weline-checkout__submit');
                  if (sub){ try { WLDBG.postMessage(JSON.stringify({submit:'clicking'})); } catch(e){} setTimeout(function(){ sub.click(); }, 500); }
                }
              } catch(e){}
            }, 3000);
          ''').catchError((_) {});
        }
        // TEST-PROBE(WLFIND)：测试构建专用——把候选操作元素屏幕坐标写进 title（kDebugMode 门控）
        if (kDebugMode) {
          _controller.runJavaScript('''
            (function(){
              var out=[]; var top=115;
                // AUTO-ATC（测试构建）：详情页自动加购——class 定位 + realClick 完整事件序列
                var atcEl0=document.querySelector('.product-native-detail__add');
                try { WLDBG.postMessage(JSON.stringify({atcProbe: !!atcEl0, path: location.pathname.slice(0,40), done: !!window.__wlAtcDone})); } catch(e){}
                if (atcEl0 && !window.__wlAtcDone){
                  function realClick(el){
                    if (!el) return false;
                    var r=el.getBoundingClientRect();
                    var opts={bubbles:true,cancelable:true,view:window,clientX:r.x+r.width/2,clientY:r.y+r.height/2};
                    ['pointerdown','mousedown','pointerup','mouseup','click'].forEach(function(t){
                      var Ev = t.indexOf('pointer')===0 ? window.PointerEvent || MouseEvent : MouseEvent;
                      try { el.dispatchEvent(new Ev(t, opts)); } catch(e){ el.dispatchEvent(new MouseEvent('click', opts)); }
                    });
                    return true;
                  }
                  var opts2=document.querySelectorAll('.product-native-detail__variant-option');
                  for (var oi=0;oi<opts2.length;oi++){
                    if (!/is-selected/.test(opts2[oi].className)) { realClick(opts2[oi]); break; }
                  }
                  realClick(atcEl0);
                  window.__wlAtcDone=1;
                  try { WLDBG.postMessage(JSON.stringify({atc:'clicked', sizes:opts2.length})); } catch(e){}
                }
                var p0=document.querySelector('a[href*="/product/"]');
                if (p0){ var pr=p0.getBoundingClientRect(); if (pr.top<80||pr.bottom>700){ p0.scrollIntoView({block:'center'}); } }
              var defs=[['选购衣裳','a,button'],['加入购物车','a,button'],['立即购买','a,button'],
                        ['Cart','a,button'],['Checkout','a,button'],['Place Order','a,button'],
                        ['提交订单','a,button'],['去支付','a,button'],['结算','a,button'],
                        ['PROD','a[href*="/product/"]']];
              for (var i=0;i<defs.length;i++){
                var els=document.querySelectorAll(defs[i][1]);
                for (var j=0;j<els.length;j++){
                  var e=els[j]; var txt=(e.textContent||'').trim();
                  var hit = defs[i][0]==='PROD' ? true : txt.indexOf(defs[i][0])>=0;
                  if (!hit) continue;
                  var r=e.getBoundingClientRect();
                  if (r.width<5||r.height<5) continue;
                  out.push(defs[i][0]+'|'+Math.round(r.x+r.width/2)+','+Math.round(r.y+r.height/2)+'|dpr'+window.devicePixelRatio);
                  break;
                }
              }
              if(out.length){
                var dpr = window.devicePixelRatio || 1;
                var raw = [];
                var els2=[document.querySelectorAll('a[href*="/product/"]'), document.querySelectorAll('.product-native-detail__add')];
                for (var gi=0; gi<els2.length; gi++){
                  for (var gj=0; gj<els2[gi].length; gj++){
                    var rr=els2[gi][gj].getBoundingClientRect();
                    if (rr.width<5) continue;
                    raw.push((gi===0?'PROD':'ATC')+'|'+Math.round(rr.x)+','+Math.round(rr.y));
                    break;
                  }
                }
                try { WLDBG.postMessage(JSON.stringify({wlfind: out.join(' | '), pts: raw, dpr: dpr})); } catch(e){}
              }
                // DEBUG-AUTO：页面级自动流（kDebugMode 门控的测试脚手架）
                var lay=(document.body?document.body.getAttribute('data-layout'):'')||'';
                // AUTO-LOGIN（测试构建）：登录墙自动登录测试账号
                if (location.pathname.indexOf('/customer/account/login')===0 && !window.__wlLoginDone){
                  function wlfill2(sel, val){
                    var el=document.querySelector(sel);
                    if (!el) return false;
                    var desc=Object.getOwnPropertyDescriptor(Object.getPrototypeOf(el),'value');
                    if (desc && desc.set) { desc.set.call(el, val); } else { el.value=val; }
                    el.dispatchEvent(new Event('input',{bubbles:true}));
                    el.dispatchEvent(new Event('change',{bubbles:true}));
                    return true;
                  }
                  var f1=wlfill2('#email, input[name=username], input[type=email]', 'wlapp-final@test.weline.com');
                  var f2=wlfill2('#password, input[name=password]', 'WlApp2026Test');
                  if (f1 && f2){
                    // 等 invisible reCAPTCHA token 就绪（最多 30s），再提交
                    var tries=0;
                    var waitTok=setInterval(function(){
                      tries++;
                      var tok='';
                      try { tok = window.grecaptcha && window.grecaptcha.getResponse ? window.grecaptcha.getResponse() : ''; } catch(e){}
                      if (tok || tries>30){
                        clearInterval(waitTok);
                        window.__wlLoginDone=1;
                        try { WLDBG.postMessage(JSON.stringify({login: tok?'submitting-with-token':'submitting-no-token', tries: tries})); } catch(e){}
                        var btn=document.querySelector('button[type=submit]');
                        if (btn){ btn.click(); }
                      }
                    }, 1000);
                  }
                }
                try { WLDBG.postMessage(JSON.stringify({url: location.pathname.slice(0,50), layout: lay})); } catch(e){}
                if (lay==='checkout'){
                  var sub=document.querySelector('.weline-checkout__submit');
                  if (sub){
                    // 滚动容器可能是内部 div（body 不滚），逐层找可滚祖先并拉到底
                    var sc=sub.parentElement;
                    var scrolled=false;
                    while (sc && sc!==document.body){
                      if (sc.scrollHeight>sc.clientHeight+50){
                        sc.scrollTop=sc.scrollHeight; scrolled=true;
                        try { WLDBG.postMessage(JSON.stringify({scrolledTo: sc.className.slice(0,40), sh: sc.scrollHeight})); } catch(e){}
                        break;
                      }
                      sc=sc.parentElement;
                    }
                    if (sr.top<60 || sr.bottom>780){
                      // 兜底：form submit 按钮的原生提交不依赖视口/滚动
                      try { WLDBG.postMessage(JSON.stringify({directSubmit: true})); } catch(e){}
                      sub.click();
                    }
                    var sr=sub.getBoundingClientRect();
                    try { WLDBG.postMessage(JSON.stringify({submitY: Math.round(sr.y), scrolled: scrolled})); } catch(e){}
                  }
                }
                if (lay==='cart'){
                  var co=document.querySelector('.weline-cart-shell__checkout, a[href="/checkout"]');
                  if (co){ var cr=co.getBoundingClientRect(); try { WLDBG.postMessage(JSON.stringify({checkoutBtn: Math.round(cr.x+cr.width/2)+','+Math.round(cr.y+cr.height/2)})); } catch(e){} }
                }
            })()
          ''').catchError((_) {});
        }
        try {
          final canGo = await _controller.canGoBack();
          if (mounted) setState(() { _loading = false; _canGoBack = canGo; });
        } catch (_) {
          if (mounted) setState(() => _loading = false);
        }
      },
      onWebResourceError: (err) {
        // 主文档错误才算断网（子资源失败不提示）
        if (err.isForMainFrame == true) {
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
    // 站点移动端 Cart 页缺可见结算入口（weline-cart-shell__checkout 被 CSS 隐藏）——壳补原生浮动按钮
    final showCheckoutFab = _state.layout == 'cart' && !_loading;
    return Stack(
      children: [
        _webView(),
        if (_loading) _skeleton(), // 首屏骨架
        if (showCheckoutFab)
          Positioned(
            right: 16,
            bottom: 24,
            child: FloatingActionButton.extended(
              heroTag: 'wl-checkout-fab',
              backgroundColor: AppColors.cinnabar,
              foregroundColor: Colors.white,
              onPressed: () => _controller.loadRequest(
                  Uri.parse(kShellBaseUrl + '/checkout')),
              icon: const Icon(Icons.shopping_cart_checkout, size: 20),
              label: const Text('Checkout',
                  style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
            ),
          ),
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

