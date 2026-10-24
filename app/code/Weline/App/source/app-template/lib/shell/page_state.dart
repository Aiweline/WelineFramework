import 'dart:convert';

class PageState {
  const PageState({
    required this.layout,
    required this.title,
    required this.cartCount,
    required this.loggedIn,
    required this.primaryColor,
  });

  final String layout;
  final String title;
  final int cartCount;
  final bool loggedIn;
  final String primaryColor;

  static const PageState empty = PageState(
    layout: 'unknown',
    title: '',
    cartCount: 0,
    loggedIn: false,
    primaryColor: '',
  );

  static const List<String> tabs = ['home', 'shop', 'search', 'bag', 'account'];
  static const Map<String, String> tabUrls = {
    'home': '/',
    'shop': '/shop',
    'search': '/search',
    'bag': '/cart',
    'account': '/customer/account',
  };

  static const Set<String> _layouts = {
    'homepage',
    'shop',
    'products',
    'product-detail',
    'cart',
    'checkout',
    'search',
    'not_found',
    'account_auth_content',
  };

  factory PageState.fromMessage(Object? message) {
    Object? data = message;
    if (data is String) {
      try {
        data = jsonDecode(data);
      } catch (_) {
        return empty;
      }
    }
    if (data is! Map) return empty;

    return PageState(
      layout: _normalizeLayout(data['layout']),
      title: data['title'] is String ? data['title'] as String : '',
      cartCount: _normalizeCartCount(data['cartCount']),
      loggedIn: _normalizeLoggedIn(data['loggedIn']),
      primaryColor: _normalizePrimaryColor(data['primaryColor']),
    );
  }

  static String _normalizeLayout(Object? value) {
    if (value is String && _layouts.contains(value)) return value;
    return 'unknown';
  }

  static int _normalizeCartCount(Object? value) {
    int? result;
    if (value is num) {
      result = value.toInt();
    } else if (value is String) {
      result = int.tryParse(value);
    }
    if (result == null || result < 0) return 0;
    return result;
  }

  static bool _normalizeLoggedIn(Object? value) {
    if (value is bool) return value;
    if (value is num) return value == 1;
    if (value is String) {
      if (value == 'true') return true;
      if (value == 'false') return false;
    }
    return false;
  }

  static String _normalizePrimaryColor(Object? value) {
    if (value is! String) return '';
    if (value.length != 4 && value.length != 7) return '';
    if (!value.startsWith('#')) return '';
    final hex = value.substring(1);
    return RegExp(r'^[0-9a-fA-F]+$').hasMatch(hex) ? value : '';
  }

  static String tabKeyForLayout(String layout) {
    switch (layout) {
      case 'homepage':
        return 'home';
      case 'shop':
      case 'products':
      case 'product-detail':
        return 'shop';
      case 'search':
        return 'search';
      case 'cart':
        return 'bag';
      case 'account_auth_content':
        return 'account';
      case 'checkout':
      case 'not_found':
      case 'unknown':
        return '';
      default:
        return '';
    }
  }

  int get tabIndex => tabs.indexOf(tabKeyForLayout(layout));
  bool get showsTabs => tabIndex >= 0;
}
