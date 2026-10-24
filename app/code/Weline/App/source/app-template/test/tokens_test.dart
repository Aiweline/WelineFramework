// tokens_test.dart — Token 盘防篡改断言（T16）
// 朱砂是全 App 唯一强调色（token-map §3）：色值被后人改动即红
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:weline_app/tokens.dart';

void main() {
  group('AppColors 防篡改', () {
    test('cinnabar = rgb(177,63,60)（唯一强调色，与 hanfu design 同源）', () {
      expect(AppColors.cinnabar, const Color.fromARGB(255, 177, 63, 60));
    });

    test('cinnabarDeep = rgb(146,50,47)（按压态）', () {
      expect(AppColors.cinnabarDeep, const Color.fromARGB(255, 146, 50, 47));
    });

    test('cream 页面底 / ink 主文字（奶油底+水墨 [H]）', () {
      expect(AppColors.cream, const Color.fromARGB(255, 247, 242, 233));
      expect(AppColors.ink, const Color.fromARGB(255, 43, 38, 34));
    });
  });

  group('间距/圆角预算', () {
    test('Space 8pt 基线（禁私造值）', () {
      expect(Space.s4, 16.0); // 页边距
      expect(Space.s5, 24.0); // 组间距 = 组内 ×2
      expect(Space.s6, 32.0); // 水墨留白 [H]
      expect(Space.s7, 48.0); // 半层顶部留白
    });

    test('Radii 两套 + 圆件', () {
      expect(Radii.md, BorderRadius.circular(12));
      expect(Radii.lg, BorderRadius.circular(16)); // 卡片/Hero
    });
  });
}
