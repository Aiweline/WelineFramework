// UC6 集成断言：版本比对语义（hasUpdate 数据态）
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter/foundation.dart';
import 'package:weline_app/services/app_config.dart';
import 'package:weline_app/services/updater.dart';

class _StubConfig implements AppConfigService {
  @override
  dynamic noSuchMethod(Invocation invocation) => null;
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('UC6: 远端高版本 2.0.0 vs 本地 1.0.1 → isNewer=true（弹框数据态）', () {
    final u = AppUpdater(_StubConfig());
    expect(u.isNewer('2.0.0', '1.0.1'), true);
  });

  test('UC6: 同版本 → isNewer=false（无更新分支）', () {
    final u = AppUpdater(_StubConfig());
    expect(u.isNewer('1.0.1', '1.0.1'), false);
  });

  test('UC6: forceUpdate 语义字段存在且透传', () {
    final r = UpdateCheckResult(
      hasUpdate: true,
      info: null,
      forceUpdate: true,
      channel: UpdateChannel.android,
    );
    expect(r.forceUpdate, true);
    expect(r.channel, UpdateChannel.android);
  });
}
