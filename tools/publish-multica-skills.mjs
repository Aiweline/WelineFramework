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
const changelog = version
  ? `Publish_WelineFramework_Multica_role_skills_${version}`
  : "Publish_WelineFramework_Multica_role_skills";
const publishVersion = isSemver(version) ? version : "";

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

function colorUrls(text) {
  return text.replace(/https?:\/\/[^\s)]+/giu, (url) => blue(url));
}

function errorText(text) {
  return red(colorUrls(text));
}

function isSemver(value) {
  return /^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/u.test(value);
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

function isRateLimitError(output = "") {
  return /rate limit|max 5 new skills per hour|please wait before publishing more/iu.test(output);
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

function slugify(name) {
  return name
    .normalize("NFKD")
    .replace(/[^\w\u4e00-\u9fa5-]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .toLowerCase();
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

if (version && !publishVersion) {
  console.log(`SKILL_VERSION is not semver, so it will be used only in the changelog: ${version}`);
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
console.log("Using per-skill publish. ClawHub sync is not used.");
let publishedCount = 0;

try {
  for (const dir of skillDirs) {
    const skillName = manifest.find((item) => item.path === dir)?.name || dir;
    const args = ["clawhub", "skill", "publish", dir, "--changelog", changelog, "--tags", "latest"];

    if (publishVersion) {
      args.push("--version", publishVersion);
    }

    console.log(`Publishing ${skillName}...`);
    run(getNpxCommand(), args, { captureOutput: true, echoOutput: true });
    publishedCount += 1;
  }
} catch (error) {
  if (isRateLimitError(error.commandOutput || "")) {
    const currentSkill = manifest[publishedCount]?.name || "unknown";
    failWithRateLimit(publishedCount, currentSkill, error.commandOutput || "");
  }

  failWithGuide("ClawHub publish failed.", [
    "Check whether the ClawHub CLI is reachable through `npx clawhub --help`.",
    "Check whether your login session is still valid or whether `CLAWHUB_TOKEN` is configured.",
    "If you are on Windows, this usually indicates an argument-passing issue or a CLI option mismatch, not a login failure.",
    "Check whether the target skills directory contains valid `SKILL.md` files.",
    error.commandOutput ? `CLI output: ${error.commandOutput}` : "The publish command exited with a non-zero status.",
  ]);
}

console.log("Done.");
console.log(`Check ${manifestPath} for expected URLs.`);
