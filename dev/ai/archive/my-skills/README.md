Overview
- 将 dev/ai 目录下的技能信息整理成我的个人技能库，便于快速查阅与复用。每个技能独立成一个条目，包含名称、描述、来源、核心要点、以及示例用法与代码片段。 

结构建议
- dev/ai/my-skills/<skill-slug>/README.md  -- 技能概要与使用要点
- dev/ai/my-skills/<skill-slug>/snippets/  -- 常用代码片段/模板
- dev/ai/my-skills/<skill-slug>/notes/  -- 个人笔记与学习要点

转换方法
- 使用 dev/ai/tools/convert-skills.py 生成初始条目；
- 手动补充示例、场景化任务、以及个人笔记。

后续工作
- 结合项目实际场景逐步完善每个技能，持续更新并加入验证用例。

注
- 如果某个技能相关源文件移动或更新，请重新运行转换脚本以同步。
