// updater_test.dart — UC6 自更新核心逻辑单测（T16）
// 覆盖：_isNewer 语义化比对（经 @visibleForTesting isNewer）与 _parse（parseVersion）
// 场景：同版本/主次补丁三维/前导零/非法串/后缀（-beta/+build）忽略
import 'package:flutter_test/flutter_test.dart';

import 'package:weline_app/services/app_config.dart';
import 'package:weline_app/services/updater.dart';

void main() {
  final updater = AppUpdater(AppConfigService());

  group('isNewer（语义化版本比对）', () {
    test('同版本 → false', () {
      expect(updater.isNewer('1.0.1', '1.0.1'), isFalse);
    });

    test('主版本 greater → true', () {
      expect(updater.isNewer('2.0.0', '1.9.9'), isTrue);
    });

    test('次版本 greater → true', () {
      expect(updater.isNewer('1.1.0', '1.0.9'), isTrue);
    });

    test('补丁版本 greater → true（多位数进位：1.2.10 > 1.2.9）', () {
      expect(updater.isNewer('1.2.10', '1.2.9'), isTrue);
    });

    test('更旧 → false', () {
      expect(updater.isNewer('1.0.0', '1.0.1'), isFalse);
      expect(updater.isNewer('0.9.9', '1.0.0'), isFalse);
    });

    test('前导零按十进制：1.02.3 == 1.2.3 → false', () {
      // int.tryParse('02')=2，与 2 相等 → 非更新
      expect(updater.isNewer('1.02.3', '1.2.3'), isFalse);
    });

    test('非法串（abc/空串）→ 按 0.0.0 不误判有更新', () {
      expect(updater.isNewer('abc', '1.0.0'), isFalse);
      expect(updater.isNewer('', '1.0.0'), isFalse);
      expect(updater.isNewer('abc', '0.0.0'), isFalse); // 0.0.0 vs 0.0.0
    });

    test('后缀忽略：-beta 与 +build 不参与比较', () {
      expect(updater.isNewer('1.0.1-beta', '1.0.1'), isFalse);
      expect(updater.isNewer('1.0.1+build.5', '1.0.1'), isFalse);
      expect(updater.isNewer('1.0.2-beta+7', '1.0.1'), isTrue);
    });
  });

  group('parseVersion（解析形态）', () {
    test('标准三段', () {
      expect(updater.parseVersion('1.2.3'), [1, 2, 3]);
    });

    test('非数字段回落 0', () {
      expect(updater.parseVersion('abc'), [0, 0, 0]);
      expect(updater.parseVersion(''), [0, 0, 0]);
      expect(updater.parseVersion('1.x.3'), [1, 0, 3]);
    });

    test('带后缀取 core', () {
      expect(updater.parseVersion('1.2.3-beta+exp.1'), [1, 2, 3]);
    });

    test('缺段补 0', () {
      expect(updater.parseVersion('1.2'), [1, 2, 0]);
      expect(updater.parseVersion('2'), [2, 0, 0]);
    });
  });
}
