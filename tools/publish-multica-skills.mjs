#!/usr/bin/env node

import { existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { spawn, spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";

const scriptDir = dirname(fileURLToPath(import.meta.url));

const cliArgs = process.argv.slice(2);
const positionalArgs = cliArgs.filter((arg) => !arg.startsWith("--"));
const inputDir = positionalArgs[0] || "dev/ai/skills";
const skillsDir = resolve(process.cwd(), inputDir);
const owner = process.env.CLAWHUB_OWNER || "";
const version = process.env.SKILL_VERSION || "";
const clawhubToken = process.env.CLAWHUB_TOKEN || "";
const dryRun = cliArgs.includes("--dry-run");
const manifestPath = join(scriptDir, "clawhub-skill-manifest.json");
const publishConcurrency = Number.parseInt(process.env.CLAWHUB_CONCURRENCY || "4", 10);
const changelog = version
  ? `Publish_WelineFramework_Multica_role_skills_${version}`
  : "Publish_WelineFramework_Multica_role_skills";
const publishVersion = version || "1.1.0";
const skillSlugMap = new Map([
  ["CI发布工程师-CI与发布门禁", "ci-release-gate"],
  ["CI发布工程师-环境兼容与命令安全", "ci-env-command-safety"],
  ["E2E自动化工程师-端到端流程测试", "e2e-flow-test"],
  ["E2E自动化工程师-路由与UI冒烟验证", "e2e-route-ui-smoke"],
  ["QA测试主管-测试策略治理", "qa-test-strategy"],
  ["QA测试主管-质量门禁验收", "qa-quality-gate"],
  ["WLS运行时工程师-Session与SSE运行时", "wls-session-sse"],
  ["WLS运行时工程师-WLS进程稳定", "wls-process-stability"],
  ["业务模块工程师-服务层与业务逻辑", "business-service-logic"],
  ["业务模块工程师-模块开发", "business-module-development"],
  ["业务模块工程师-配置缓存与后台权限", "business-config-cache-acl"],
  ["前端主题工程师-主题模板开发", "frontend-theme-template"],
  ["通用工程师-国际化与用户提示", "common-i18n-notification"],
  ["前端主题工程师-组件与页面构建", "frontend-component-pagebuilder"],
  ["单元测试工程师-单元测试覆盖", "unit-test-coverage"],
  ["单元测试工程师-测试数据与回归", "unit-test-data-regression"],
  ["安全权限工程师-ACL与后台安全", "security-acl-admin"],
  ["安全权限工程师-会话配置与数据保护", "security-session-data"],
  ["技术主管-一级验收与进度追踪", "tech-lead-acceptance-progress"],
  ["技术主管-任务拆分与调度", "tech-lead-task-scheduling"],
  ["通用工程师-开发规范与代码质量", "common-development-standards"],
  ["文档知识库工程师-技能索引与知识库", "docs-skill-index"],
  ["文档知识库工程师-文档规范与变更记录", "docs-change-records"],
  ["框架核心工程师-ORM与数据模型", "core-orm-model"],
  ["框架核心工程师-命令与代码生成", "core-command-codegen"],
  ["框架核心工程师-框架核心开发", "core-development"],
  ["框架核心工程师-路由事件与扩展", "core-routing-extension"],
]);

function printDivider() {
  console.log("=".repeat(72));
}

function shouldUseColor() {
  return process.env.NO_COLOR !== "1" && (process.stdout.isTTY || process.env.FORCE_COLOR);
}

function color(text, code) {
  return shouldUseColor() ? `\u001b[${code}m${text}\u001b[0m` : text;
}

function green(text) {
  return color(text, "32");
}

function blue(text) {
  return color(text, "34");
}

function red(text) {
  return color(text, "31");
}

function yellow(text) {
  return color(text, "33");
}

function colorUrls(text) {
  return text.replace(/https?:\/\/[^\s)]+/giu, (url) => blue(url));
}

function errorText(text) {
  return red(colorUrls(text));
}

function isInteractiveTerminal() {
  return Boolean(process.stdin.isTTY && process.stdout.isTTY);
}

function getNpxCommand() {
  return process.platform === "win32" ? "npx.cmd" : "npx";
}

function quoteForCmd(arg) {
  if (arg === "") {
    return '""';
  }

  if (!/[\s"]/u.test(arg)) {
    return arg;
  }

  return `"${arg.replace(/"/g, '\\"')}"`;
}

function buildCommandInvocation(cmd, args) {
  const isWindowsCmdShim = process.platform === "win32" && /\.cmd$/iu.test(cmd);
  const command = isWindowsCmdShim ? (process.env.ComSpec || "cmd.exe") : cmd;
  const commandArgs = isWindowsCmdShim
    ? ["/d", "/s", "/c", [cmd, ...args].map(quoteForCmd).join(" ")]
    : args;

  return { command, commandArgs };
}

function printLocalSetupGuide() {
  printDivider();
  console.log("ClawHub local publish setup guide");
  printDivider();
  console.log("This script can publish in two modes:");
  console.log("1. Local interactive mode: reuse your existing clawhub login session.");
  console.log("2. CI or unattended mode: use CLAWHUB_TOKEN.");
  console.log("");
  console.log("Recommended local steps:");
  console.log("  1. npx clawhub login");
  console.log("  2. node tools/publish-multica-skills.mjs --dry-run");
  console.log("  3. node tools/publish-multica-skills.mjs");
  console.log("");
  console.log("Recommended CI steps:");
  console.log("  1. Set CLAWHUB_TOKEN as a secret or environment variable.");
  console.log("  2. Optionally set CLAWHUB_OWNER to generate expected public URLs.");
  console.log("  3. Run: node tools/publish-multica-skills.mjs");
  console.log("");
  console.log("Optional override:");
  console.log("  You can still pass a custom skills directory as argv[2] if needed.");
  console.log("");
  console.log("Windows persistent token example:");
  console.log("  setx CLAWHUB_TOKEN <your-token>");
  console.log("  setx CLAWHUB_OWNER <your-handle>");
  console.log("");
  console.log("GitHub Actions secret names used by the workflow:");
  console.log("  CLAWHUB_TOKEN");
  console.log("  CLAWHUB_OWNER");
  printDivider();
}

function failWithGuide(message, details = []) {
  console.error("");
  console.error(errorText(`ERROR: ${message}`));
  for (const detail of details) {
    console.error(errorText(`- ${detail}`));
  }
  console.error("");
  printLocalSetupGuide();
  process.exit(1);
}

function run(cmd, args, options = {}) {
  const { command, commandArgs } = buildCommandInvocation(cmd, args);

  const finalResult = spawnSync(command, commandArgs, {
    stdio: options.captureOutput ? "pipe" : "inherit",
    encoding: options.captureOutput ? "utf8" : undefined,
    shell: false,
    ...options,
  });

  if (options.echoOutput && typeof finalResult.stdout === "string" && finalResult.stdout.length > 0) {
    process.stdout.write(finalResult.stdout);
  }

  if (options.echoOutput && typeof finalResult.stderr === "string" && finalResult.stderr.length > 0) {
    process.stderr.write(errorText(finalResult.stderr));
  }

  if (finalResult.error) {
    const error = new Error(`Command failed to start: ${cmd} ${args.join(" ")}`);
    error.commandOutput = finalResult.error.message || "";
    throw error;
  }

  if (finalResult.status !== 0) {
    const stderr = typeof finalResult.stderr === "string" ? finalResult.stderr.trim() : "";
    const stdout = typeof finalResult.stdout === "string" ? finalResult.stdout.trim() : "";
    const output = [stdout, stderr].filter(Boolean).join("\n");
    const error = new Error(`Command failed: ${cmd} ${args.join(" ")}`);
    error.commandOutput = output;
    throw error;
  }

  return finalResult;
}

function runStreaming(cmd, args, options = {}) {
  const { command, commandArgs } = buildCommandInvocation(cmd, args);

  return new Promise((resolveRun, rejectRun) => {
    const child = spawn(command, commandArgs, {
      shell: false,
      stdio: ["inherit", "pipe", "pipe"],
      ...options,
    });

    let output = "";

    child.stdout.on("data", (chunk) => {
      const text = chunk.toString();
      output += text;
      process.stdout.write(colorUrls(text));
    });

    child.stderr.on("data", (chunk) => {
      const text = chunk.toString();
      output += text;
      process.stderr.write(errorText(text));
    });

    child.on("error", (spawnError) => {
      const error = new Error(`Command failed to start: ${cmd} ${args.join(" ")}`);
      error.commandOutput = spawnError.message || output;
      rejectRun(error);
    });

    child.on("close", (status) => {
      if (status !== 0) {
        const error = new Error(`Command failed: ${cmd} ${args.join(" ")}`);
        error.commandOutput = output.trim();
        rejectRun(error);
        return;
      }

      resolveRun({ status, commandOutput: output });
    });
  });
}

function runCapturedAsync(cmd, args, options = {}) {
  const { command, commandArgs } = buildCommandInvocation(cmd, args);

  return new Promise((resolveRun, rejectRun) => {
    const child = spawn(command, commandArgs, {
      shell: false,
      stdio: ["ignore", "pipe", "pipe"],
      ...options,
    });

    let stdout = "";
    let stderr = "";

    child.stdout.on("data", (chunk) => {
      stdout += chunk.toString();
    });

    child.stderr.on("data", (chunk) => {
      stderr += chunk.toString();
    });

    child.on("error", (spawnError) => {
      const error = new Error(`Command failed to start: ${cmd} ${args.join(" ")}`);
      error.commandOutput = spawnError.message || "";
      rejectRun(error);
    });

    child.on("close", (status) => {
      if (status !== 0) {
        const output = [stdout.trim(), stderr.trim()].filter(Boolean).join("\n");
        const error = new Error(`Command failed: ${cmd} ${args.join(" ")}`);
        error.commandOutput = output;
        rejectRun(error);
        return;
      }

      resolveRun({ status, stdout, stderr });
    });
  });
}

function isRateLimitError(output = "") {
  return /rate limit|max 5 new skills per hour|please wait before publishing more/iu.test(output);
}

function isVersionExistsError(output = "") {
  return /version already exists/iu.test(output);
}

function failWithRateLimit(publishedCount, failedSkillName, output = "") {
  console.error("");
  console.error(errorText("ERROR: ClawHub new-skill rate limit reached."));
  console.error(errorText(`- Published in this run before stopping: ${publishedCount}`));
  console.error(errorText(`- Stopped at skill: ${failedSkillName}`));
  console.error(errorText("- ClawHub currently allows at most 5 new skills per hour."));
  console.error(errorText("- Wait for the ClawHub reset window, then run this same command again:"));
  console.error(errorText("  node tools/publish-multica-skills.mjs"));
  console.error(errorText("- Re-running is expected. Already published skills should be handled by ClawHub as existing skills."));
  if (output) {
    console.error(errorText(`- CLI output: ${output}`));
  }
  process.exit(2);
}

function ensureSkillFrontmatterVersion(skillPath) {
  const content = readFileSync(skillPath, "utf8");

  if (!content.startsWith("---")) {
    failWithGuide("Missing YAML frontmatter.", [
      `Skill file: ${skillPath}`,
      "Every ClawHub skill must start with YAML frontmatter.",
    ]);
  }

  const end = content.indexOf("---", 3);
  if (end === -1) {
    failWithGuide("Invalid YAML frontmatter.", [
      `Skill file: ${skillPath}`,
      "Could not find the closing --- marker.",
    ]);
  }

  const frontmatter = content.slice(3, end);
  const nextContent = /^version:\s*.+$/m.test(frontmatter)
    ? content.replace(/^version:\s*.+$/m, `version: ${publishVersion}`)
    : `${content.slice(0, end)}version: ${publishVersion}\n${content.slice(end)}`;

  if (nextContent !== content) {
    writeFileSync(skillPath, nextContent, "utf8");
  }
}

function slugify(name) {
  const mappedSlug = skillSlugMap.get(name);
  if (mappedSlug) {
    return mappedSlug;
  }

  return name
    .normalize("NFKD")
    .replace(/[^\w-]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .toLowerCase();
}

function normalizeConcurrency(value) {
  if (!Number.isFinite(value) || value < 1) {
    return 1;
  }

  return Math.min(Math.floor(value), 8);
}

async function runPublishBatch(tasks, concurrency) {
  const results = [];
  let nextIndex = 0;

  async function worker() {
    while (nextIndex < tasks.length) {
      const currentIndex = nextIndex;
      nextIndex += 1;
      const task = tasks[currentIndex];

      try {
        const result = await runCapturedAsync(getNpxCommand(), task.args);
        results[currentIndex] = { ...task, status: "published", output: [result.stdout, result.stderr].filter(Boolean).join("\n") };
      } catch (error) {
        const output = error.commandOutput || "";
        if (isVersionExistsError(output)) {
          results[currentIndex] = { ...task, status: "skipped-existing", output };
          continue;
        }

        if (isRateLimitError(output)) {
          results[currentIndex] = { ...task, status: "rate-limit", output };
          continue;
        }

        results[currentIndex] = { ...task, status: "failed", output };
      }
    }
  }

  await Promise.all(Array.from({ length: concurrency }, () => worker()));
  return results;
}

if (!existsSync(skillsDir)) {
  failWithGuide("Skills directory not found.", [
    `Checked path: ${skillsDir}`,
    "Run the script from the repository root, or pass a valid skills directory path.",
  ]);
}

const skillDirs = readdirSync(skillsDir, { withFileTypes: true })
  .filter((entry) => entry.isDirectory())
  .map((entry) => join(skillsDir, entry.name))
  .filter((dir) => existsSync(join(dir, "SKILL.md")));

if (skillDirs.length === 0) {
  failWithGuide("No SKILL.md files found.", [
    `Checked path: ${skillsDir}`,
    "Each skill must be a directory containing a SKILL.md file.",
  ]);
}

console.log(`Found ${skillDirs.length} skills under ${skillsDir}`);

const manifest = [];

for (const dir of skillDirs) {
  const skillPath = join(dir, "SKILL.md");
  const content = readFileSync(skillPath, "utf8");
  const nameMatch = content.match(/^name:\s*(.+)$/m);
  const skillName = nameMatch?.[1]?.trim() || dir.split(/[\\/]/).pop();
  const slug = slugify(skillName);

  manifest.push({
    name: skillName,
    slug,
    path: dir,
    expectedClawHubUrl: owner
      ? `https://clawhub.ai/${owner}/${slug}`
      : `https://clawhub.ai/<your-handle>/${slug}`,
  });
}

manifest.sort((a, b) => a.name.localeCompare(b.name, "zh-Hans-CN"));

const manifestDir = scriptDir;
if (!existsSync(manifestDir)) {
  mkdirSync(manifestDir, { recursive: true });
}

writeFileSync(manifestPath, JSON.stringify(manifest, null, 2), "utf8");

console.log(`Generated ${manifestPath}`);

if (dryRun) {
  console.log("Dry run only. No publish executed.");
  process.exit(0);
}

if (clawhubToken) {
  console.log("Using CLAWHUB_TOKEN for non-interactive publish.");
} else {
  console.log("Checking clawhub login...");
  try {
    run(getNpxCommand(), ["clawhub", "whoami"], { captureOutput: true });
  } catch (error) {
    if (isInteractiveTerminal()) {
      console.log(green("No ClawHub login session found. Launching `npx clawhub login`..."));
      try {
        await runStreaming(getNpxCommand(), ["clawhub", "login"]);
        console.log(green("Login completed. Continuing with publish..."));
        run(getNpxCommand(), ["clawhub", "whoami"], { captureOutput: true });
      } catch (loginError) {
        failWithGuide("ClawHub login did not complete successfully.", [
          "Complete the browser authorization flow opened by `npx clawhub login`.",
          "Or set `CLAWHUB_TOKEN` if you want non-interactive publish.",
          loginError.commandOutput
            ? `CLI output: ${loginError.commandOutput}`
            : "The ClawHub login flow exited with a non-zero status.",
        ]);
      }
    } else {
      failWithGuide("No usable ClawHub login session was found.", [
        "Run `npx clawhub login` first if you are publishing locally.",
        "Or set `CLAWHUB_TOKEN` if you want non-interactive publish.",
        error.commandOutput ? `CLI output: ${error.commandOutput}` : "The ClawHub CLI check failed before publish.",
      ]);
    }
  }
}

console.log("Publishing skills...");
console.log("Using concurrent per-skill publish. ClawHub sync is not used because it also scans shared OpenClaw skills.");
const publishTasks = [];

for (const dir of skillDirs) {
  const skillPath = join(dir, "SKILL.md");
  const skillName = manifest.find((item) => item.path === dir)?.name || dir;
  const args = [
    "clawhub",
    "skill",
    "publish",
    dir,
    "--slug",
    slugify(skillName),
    "--version",
    publishVersion,
    "--changelog",
    changelog,
    "--tags",
    "latest",
  ];

  ensureSkillFrontmatterVersion(skillPath);
  publishTasks.push({ skillName, dir, args });
}

const concurrency = normalizeConcurrency(publishConcurrency);
console.log(`Checking/publishing ${publishTasks.length} skills with concurrency ${concurrency}...`);
const publishResults = await runPublishBatch(publishTasks, concurrency);
const publishedResults = publishResults.filter((result) => result.status === "published");
const skippedExistingResults = publishResults.filter((result) => result.status === "skipped-existing");
const rateLimitResults = publishResults.filter((result) => result.status === "rate-limit");
const failedResults = publishResults.filter((result) => result.status === "failed");

for (const result of publishedResults) {
  console.log(green(`Published ${result.skillName}.`));
  if (result.output) {
    process.stdout.write(colorUrls(result.output.endsWith("\n") ? result.output : `${result.output}\n`));
  }
}

if (skippedExistingResults.length > 0) {
  console.log(yellow(`Skipped existing versions: ${skippedExistingResults.length}`));
  for (const result of skippedExistingResults) {
    console.log(yellow(`- ${result.skillName}`));
  }
}

if (rateLimitResults.length > 0) {
  const firstRateLimit = rateLimitResults[0];
  failWithRateLimit(publishedResults.length, firstRateLimit.skillName, firstRateLimit.output || "");
}

if (failedResults.length > 0) {
  const firstFailure = failedResults[0];
  failWithGuide("ClawHub publish failed.", [
    `Failed skills: ${failedResults.length}`,
    `First failed skill: ${firstFailure.skillName}`,
    "Check whether the ClawHub CLI is reachable through `npx clawhub --help`.",
    "Check whether your login session is still valid or whether `CLAWHUB_TOKEN` is configured.",
    "If you are on Windows, this usually indicates an argument-passing issue or a CLI option mismatch, not a login failure.",
    "Check whether the target skills directory contains valid `SKILL.md` files.",
    firstFailure.output ? `CLI output: ${firstFailure.output}` : "The publish command exited with a non-zero status.",
  ]);
}

console.log("Done.");
console.log(`Published: ${publishedResults.length}; skipped existing versions: ${skippedExistingResults.length}.`);
console.log(`Check ${manifestPath} for expected URLs.`);
