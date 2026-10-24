// tokens.dart — 汉服 App Token 盘（照抄 flutter-token-map.md 代码块，勿改值）
// 来源：dev/team/app-build-module/flutter-token-map.md §1.2/§2/§3/§4
// 原型出处：prototypes/hanfu-app.html 注释区 Token；[H] = hanfu design 既有实践
import 'package:flutter/material.dart';

/// 汉服 App 色板（朱砂与 hanfu design 同源）
abstract final class AppColors {
  static const cinnabar = Color.fromARGB(255, 177, 63, 60); // rgb(177,63,60) 主强调
  static const cinnabarDeep = Color.fromARGB(255, 146, 50, 47); // 按压态
  static const ink = Color.fromARGB(255, 43, 38, 34); // 主文字
  static const ink2 = Color.fromARGB(255, 94, 86, 78); // 次级文字
  static const ink3 = Color.fromARGB(255, 140, 131, 122); // 占位/弱化
  static const cream = Color.fromARGB(255, 247, 242, 233); // 页面底
  static const paper = Color.fromARGB(255, 255, 255, 255); // 卡片面
  static const line = Color.fromARGB(255, 226, 218, 206); // 发丝线
}

/// M3 种子方案：seed=朱砂；surface 钉奶油防止 seed 派生漂移
/// （fromSeed 会派生 dynamic 档——凡原型有明确值的槽位必须显式覆盖）
final ColorScheme hanfuScheme = ColorScheme.fromSeed(
  seedColor: AppColors.cinnabar,
  surface: AppColors.cream,
  onSurface: AppColors.ink,
  onSurfaceVariant: AppColors.ink2,
  outline: AppColors.ink3,
  outlineVariant: AppColors.line,
);

/// 与原型 4 级阶梯 + 补 1 档 headlineMedium；行高按品味法：标题 1.25 / 正文 1.5
/// display/title 槽宋体系（iOS Songti SC 内建；Android 资产后补，先 fallback serif）
/// body/label 槽系统默认（.SF/Roboto），不引第三方字重
final TextTheme hanfuText = const TextTheme(
  displayLarge: TextStyle(
      fontSize: 34, height: 1.25, fontWeight: FontWeight.w600, color: AppColors.ink, fontFamily: 'Songti'),
  headlineMedium: TextStyle(
      fontSize: 28, height: 1.25, fontWeight: FontWeight.w600, color: AppColors.ink, fontFamily: 'Songti'),
  titleLarge: TextStyle(
      fontSize: 20, height: 1.3, fontWeight: FontWeight.w600, color: AppColors.ink, fontFamily: 'Songti'),
  bodyLarge: TextStyle(fontSize: 15, height: 1.5, fontWeight: FontWeight.w400, color: AppColors.ink),
  bodyMedium: TextStyle(fontSize: 15, height: 1.5, fontWeight: FontWeight.w400, color: AppColors.ink),
  labelSmall: TextStyle(fontSize: 12, height: 1.5, fontWeight: FontWeight.w500, color: AppColors.ink2),
);

/// 8pt 基线 + 4pt 半步；禁止 3/5/7/9/11 等私造值（品味 §4）
abstract final class Space {
  static const s1 = 4.0, s2 = 8.0, s3 = 12.0, s4 = 16.0, s5 = 24.0, s6 = 32.0, s7 = 48.0;
}

/// 圆角预算：≤2 套 + 圆件
abstract final class Radii {
  static const md = BorderRadius.all(Radius.circular(12)); // 控件/输入框/缩略图
  static const lg = BorderRadius.all(Radius.circular(16)); // 卡片/Hero 大图
  static const full = BorderRadius.all(Radius.circular(999)); // chips/头像/Tab 徽标
}

/// 唯一阴影（组件禁止私写第二枚——品味法律）
/// 原型双层合并近似；如需双层用 List<BoxShadow> 两条同源值
const BoxShadow appCardShadow = BoxShadow(
  offset: Offset(0, 4),
  blurRadius: 14,
  spreadRadius: 0,
  color: Color(0x142B2622), // rgba(43,38,34,.08)
);
