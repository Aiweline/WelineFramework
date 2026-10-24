// main.dart — 入口：增强壳（task-1/T24）：WebView 加载站点 + 原生 5 Tab
// 海外习惯硬约束：无启动广告/无 splash 倒计时；config 失败静默（壳模式由站点页面自供数据）
import 'package:flutter/material.dart';

import 'shell/shell_app.dart';
import 'tokens.dart';

void main() {
  runApp(const WelineApp());
}

class WelineApp extends StatelessWidget {
  const WelineApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Weline',
      debugShowCheckedModeBanner: false,
      supportedLocales: const [Locale('en', 'US'), Locale('zh', 'Hans_CN')],
      theme: ThemeData(
        useMaterial3: true,
        colorScheme: hanfuScheme, // seed=朱砂，surface=奶油（token-map §1.2）
        scaffoldBackgroundColor: AppColors.cream,
        textTheme: hanfuText, // 五级阶梯，display/title 宋体系
      ),
      home: const ShellApp(),
    );
  }
}
