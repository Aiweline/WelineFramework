// updater.dart — 自更新逻辑（UC6）
// V1 合约硬边界：Android 应用内下载 APK 安装；iOS 只跳 App Store（不做热更新，App Store 审核合规）
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../tokens.dart';
import 'app_config.dart';

enum UpdateChannel { android, ios, web }

UpdateChannel currentChannel() {
  if (kIsWeb) return UpdateChannel.web;
  switch (defaultTargetPlatform) {
    case TargetPlatform.android:
      return UpdateChannel.android;
    case TargetPlatform.iOS:
      return UpdateChannel.ios;
    default:
      return UpdateChannel.web;
  }
}

class UpdateCheckResult {
  UpdateCheckResult({
    required this.hasUpdate,
    required this.info,
    required this.forceUpdate,
    required this.channel,
  });
  final bool hasUpdate;
  final VersionInfo? info; // null = 检测失败或无产物
  final bool forceUpdate; // V1 契约：true 时 Android 强制下载 / iOS 跳商店（对话框隐藏 Later）
  final UpdateChannel channel;
}

class AppUpdater {
  AppUpdater(this._config);
  final AppConfigService _config;

  /// 检查更新：与本地 [currentVersion] 比对（语义化比较 major.minor.patch）
  Future<UpdateCheckResult> check(String currentVersion) async {
    final channel = currentChannel();
    if (channel == UpdateChannel.web) {
      return UpdateCheckResult(
          hasUpdate: false, info: null, forceUpdate: false, channel: channel);
    }
    final platform = channel == UpdateChannel.ios ? 'ios' : 'android';
    final info = await _config.fetchLatestVersion(platform);
    if (info == null || info.version.isEmpty) {
      return UpdateCheckResult(
          hasUpdate: false, info: null, forceUpdate: false, channel: channel);
    }
    return UpdateCheckResult(
      hasUpdate: _isNewer(info.version, currentVersion),
      info: info,
      forceUpdate: info.forceUpdate,
      channel: channel,
    );
  }

  /// 执行更新（在更新对话框按钮里调用）
  /// Android → 打开 download 接口（302 到 APK 静态地址，系统下载器接管安装）
  /// iOS     → 打开 config.downloads.ios（App Store 占位链接；V1 无云编译时为占位）
  Future<bool> performUpdate(UpdateCheckResult result,
      {AppConfig? cfg}) async {
    Uri target;
    if (result.channel == UpdateChannel.ios) {
      final iosUrl = cfg?.downloads['ios'] as String?;
      if (iosUrl == null || iosUrl.isEmpty) return false;
      target = Uri.parse(iosUrl);
    } else {
      target = _config.downloadUrl('android');
    }
    return launchUrl(target, mode: LaunchMode.externalApplication);
  }

  /// 语义化版本比较：1.2.10 > 1.2.9（单测经 @visibleForTesting 直测）
  @visibleForTesting
  bool isNewer(String remote, String local) => _isNewer(remote, local);

  bool _isNewer(String remote, String local) {
    final r = _parse(remote);
    final l = _parse(local);
    for (var i = 0; i < 3; i++) {
      if (r[i] != l[i]) return r[i] > l[i];
    }
    return false;
  }

  @visibleForTesting
  List<int> parseVersion(String v) => _parse(v);

  List<int> _parse(String v) {
    final core = v.split('-').first.split('+').first;
    final parts = core.split('.');
    return [
      int.tryParse(parts.isNotEmpty ? parts[0] : '0') ?? 0,
      int.tryParse(parts.length > 1 ? parts[1] : '0') ?? 0,
      int.tryParse(parts.length > 2 ? parts[2] : '0') ?? 0,
    ];
  }

  /// 更新对话框（壳在启动/切 Account Tab 时调用；Android 走应用内下载，iOS 跳商店）
  /// 强调预算：本屏朱砂只给「Update」主按钮一组（token-map §5 checklist）
  Future<void> showUpdateDialog(
    BuildContext context,
    UpdateCheckResult result, {
    AppConfig? cfg,
  }) async {
    final info = result.info;
    if (info == null) return;
    final ios = result.channel == UpdateChannel.ios;
    await showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.paper,
        shape: const RoundedRectangleBorder(borderRadius: Radii.lg),
        title: Text(ios ? 'Update Available' : 'New Version ${info.version}'),
        content: Text(info.changelog.isEmpty
            ? 'A newer version ${info.version} is available.'
            : info.changelog),
        actions: [
          if (!result.forceUpdate)
            TextButton(
              onPressed: () => Navigator.of(ctx).pop(),
              child: const Text('Later'),
            ),
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: AppColors.cinnabar, // 唯一强调组
              shape: const RoundedRectangleBorder(borderRadius: Radii.md),
            ),
            onPressed: () async {
              Navigator.of(ctx).pop();
              await performUpdate(result, cfg: cfg);
            },
            child: Text(ios ? 'Open App Store' : 'Update Now'),
          ),
        ],
      ),
    );
  }
}
