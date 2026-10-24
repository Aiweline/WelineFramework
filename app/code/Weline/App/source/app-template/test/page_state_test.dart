import 'package:flutter_test/flutter_test.dart';
import 'package:weline_app/shell/page_state.dart';

void main() {
  group('PageState', () {
    test('parses normal JSON with all fields', () {
      const json =
          '{"layout":"homepage","title":"Home","cartCount":3,'
          '"loggedIn":true,"primaryColor":"#b13f3c"}';
      final state = PageState.fromMessage(json);
      expect(state.layout, 'homepage');
      expect(state.title, 'Home');
      expect(state.cartCount, 3);
      expect(state.loggedIn, isTrue);
      expect(state.primaryColor, '#b13f3c');
    });

    test('parses Map input', () {
      final state = PageState.fromMessage(<String, Object?>{
        'layout': 'shop',
        'title': 'Shop',
        'cartCount': 2,
        'loggedIn': 1,
        'primaryColor': '#fff',
      });
      expect(state.layout, 'shop');
      expect(state.title, 'Shop');
      expect(state.cartCount, 2);
      expect(state.loggedIn, isTrue);
      expect(state.primaryColor, '#fff');
    });

    test('invalid JSON string returns empty', () {
      expect(PageState.fromMessage('{bad-json'), PageState.empty);
    });

    test('missing fields use defaults', () {
      final state = PageState.fromMessage(<String, Object?>{});
      expect(state.layout, 'unknown');
      expect(state.title, '');
      expect(state.cartCount, 0);
      expect(state.loggedIn, isFalse);
      expect(state.primaryColor, '');
    });

    test('invalid layout becomes unknown', () {
      expect(PageState.fromMessage({'layout': 'nope'}).layout, 'unknown');
    });

    test('cartCount accepts string and rejects negative', () {
      expect(PageState.fromMessage({'cartCount': '5'}).cartCount, 5);
      expect(PageState.fromMessage({'cartCount': -1}).cartCount, 0);
    });

    test('loggedIn accepts string forms', () {
      expect(PageState.fromMessage({'loggedIn': 'true'}).loggedIn, isTrue);
      expect(PageState.fromMessage({'loggedIn': 'no'}).loggedIn, isFalse);
    });

    test('primaryColor validation', () {
      expect(PageState.fromMessage({'primaryColor': '#b13f3c'}).primaryColor, '#b13f3c');
      expect(PageState.fromMessage({'primaryColor': 'red'}).primaryColor, '');
    });

    test('tabKeyForLayout mappings', () {
      expect(PageState.tabKeyForLayout('homepage'), 'home');
      expect(PageState.tabKeyForLayout('shop'), 'shop');
      expect(PageState.tabKeyForLayout('products'), 'shop');
      expect(PageState.tabKeyForLayout('search'), 'search');
      expect(PageState.tabKeyForLayout('cart'), 'bag');
      expect(PageState.tabKeyForLayout('checkout'), '');
    });

    test('lead 实测补集：product-detail → shop（商品页不掉 Tab）', () {
      expect(PageState.tabKeyForLayout('product-detail'), 'shop');
      expect(PageState.fromMessage({'layout': 'product-detail'}).showsTabs, isTrue);
    });

    test('lead 实测补集：account_auth_content → account（登录页保留底栏）', () {
      expect(PageState.tabKeyForLayout('account_auth_content'), 'account');
      expect(PageState.fromMessage({'layout': 'account_auth_content'}).showsTabs, isTrue);
    });

    test('showsTabs depends on tab mapping', () {
      expect(PageState.fromMessage({'layout': 'cart'}).showsTabs, isTrue);
      expect(PageState.fromMessage({'layout': 'checkout'}).showsTabs, isFalse);
    });
  });
}
