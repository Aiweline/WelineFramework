/**
 * 统一 E2E 用例命名：支持 [module:*] 与 [case:*] 标签，便于命令侧精准过滤。
 */
function buildCaseTitle(meta, title) {
  const moduleTag = meta?.module ? `[module:${meta.module}]` : '';
  const caseTag = meta?.id ? `[case:${meta.id}]` : '';
  return [moduleTag, caseTag, title].filter(Boolean).join(' ');
}

function moduleDescribe(test, moduleName, suiteTitle, callback) {
  const describeTitle = `[module:${moduleName}] ${suiteTitle}`;
  test.describe(describeTitle, callback);
}

function moduleCase(test, meta, title, callback) {
  test(buildCaseTitle(meta, title), callback);
}

module.exports = {
  buildCaseTitle,
  moduleDescribe,
  moduleCase,
};

