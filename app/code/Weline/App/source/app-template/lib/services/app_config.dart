// app_config.dart — Weline_App 三接口轻封装（contracts.md §1）
// config / version / download；网络失败静默降级用缓存（海外弱网优先可用性）
import 'dart:convert';

import 'package:http/http.dart' as http;

/// 编译期注入的站点基址（FlutterBuilder 打包时替换 {{BASE_URL}}）；
/// 本地开发可 dart-define 覆盖：--dart-define=WELINE_BASE_URL=https://...
const String kBaseUrl = String.fromEnvironment(
  'WELINE_BASE_URL',
  defaultValue: '',
);

/// App 显示名（打包时渲染 {{APP_NAME}}）
const String kAppName = String.fromEnvironment(
  'WELINE_APP_NAME',
  defaultValue: '{{APP_NAME}}',
);

/// 单条 API 错误（契约错误结构 {"error":{code,message}}）
class ApiError implements Exception {
  ApiError(this.code, this.message, this.statusCode);
  final String code;
  final String message;
  final int statusCode;

  @override
  String toString() => 'ApiError($statusCode, $code, $message)';
}

/// 启动配置（UC4）：locales/currencies/theme/downloads
class AppConfig {
  AppConfig.fromJson(Map<String, dynamic> j)
      : domain = (j['domain'] ?? '') as String,
        locales = ((j['locales'] ?? const []) as List)
            .whereType<Map<String, dynamic>>()
            .toList(growable: false),
        currencies = ((j['currencies'] ?? const []) as List)
            .whereType<Map<String, dynamic>>()
            .toList(growable: false),
        theme = (j['theme'] ?? const {}) as Map<String, dynamic>,
        appVersion = ((j['app_version'] ?? j['version']) ?? '') as String,
        downloads = (j['downloads'] ?? const {}) as Map<String, dynamic>;

  final String domain;
  final List<Map<String, dynamic>> locales;
  final List<Map<String, dynamic>> currencies;
  final Map<String, dynamic> theme;
  final String appVersion;
  final Map<String, dynamic> downloads;
}

/// 版本检测结果（UC6）
class VersionInfo {
  VersionInfo.fromJson(Map<String, dynamic> j)
      : version = (j['version'] ?? '') as String,
        buildId = (j['build_id'] ?? 0) as int,
        buildTs = (j['build_ts'] ?? 0) as int,
        url = (j['url'] ?? '') as String,
        forceUpdate = (j['force_update'] ?? false) as bool,
        changelog = (j['changelog'] ?? '') as String;

  final String version;
  final int buildId;
  final int buildTs;
  final String url;
  final bool forceUpdate;
  final String changelog;
}

class AppConfigService {
  AppConfigService({http.Client? client}) : _client = client ?? http.Client();
  final http.Client _client;

  // 网络失败降级缓存（进程内即可：App 冷启动重新拉取）
  AppConfig? _cachedConfig;

  Uri _uri(String path, [Map<String, String>? q]) =>
      Uri.parse('$kBaseUrl$path').replace(queryParameters: q);

  /// GET /api/weline_app/rest/v1/config/config（UC4）
  /// 失败时返回缓存；无缓存抛 ApiError（调用方决定兜底 UI）
  Future<AppConfig> fetchConfig({String? currency}) async {
    try {
      final res = await _client
          .get(_uri('/api/weline_app/rest/v1/config/config',
              currency == null ? null : {'currency': currency}))
          .timeout(const Duration(seconds: 10));
      final body = _decode(res);
      final cfg = AppConfig.fromJson(body);
      _cachedConfig = cfg;
      return cfg;
    } on ApiError {
      rethrow;
    } catch (_) {
      // 网络失败静默降级（弱网可用性优先）
      final cached = _cachedConfig;
      if (cached != null) return cached;
      rethrow;
    }
  }

  /// GET /api/weline_app/rest/v1/version/version?platform=…（UC6）
  /// 无 released 产物 → 404 artifact_not_found → 返回 null（App 端显示已是最新）
  Future<VersionInfo?> fetchLatestVersion(String platform) async {
    try {
      final res = await _client
          .get(_uri('/api/weline_app/rest/v1/version/version',
              {'platform': platform}))
          .timeout(const Duration(seconds: 10));
      if (res.statusCode == 404) return null;
      final body = _decode(res);
      return VersionInfo.fromJson(body);
    } on ApiError {
      rethrow;
    } catch (_) {
      return null; // 检测失败视同无更新
    }
  }

  /// 产物下载地址（Android APK 安装 / iOS 跳商店时由 updater 使用）
  Uri downloadUrl(String platform) =>
      _uri('/api/weline_app/rest/v1/download/download', {'platform': platform});

  Map<String, dynamic> _decode(http.Response res) {
    final j = jsonDecode(utf8.decode(res.bodyBytes));
    if (res.statusCode >= 400) {
      final err = (j is Map ? j['error'] : null) as Map<String, dynamic>?;
      throw ApiError(
        (err?['code'] ?? 'http_${res.statusCode}') as String,
        (err?['message'] ?? res.reasonPhrase ?? 'request failed') as String,
        res.statusCode,
      );
    }
    // 控制器 success() 包一层 {success,msg,data}
    if (j is Map && j['data'] is Map<String, dynamic>) {
      return j['data'] as Map<String, dynamic>;
    }
    return j as Map<String, dynamic>;
  }
}
