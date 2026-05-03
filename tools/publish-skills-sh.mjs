#!/usr/bin/env node

import { existsSync } from "node:fs";
import { resolve } from "node:path";
import { spawn, spawnSync } from "node:child_process";

const cliArgs = process.argv.slice(2);
const positionalArgs = cliArgs.filter((arg) => !arg.startsWith("--"));
const inputDir = positionalArgs[0] || "dev/ai/skills";
const skillsDir = resolve(process.cwd(), inputDir);
const dryRun = cliArgs.includes("--dry-run");
const tag = process.env.SKILLS_SH_TAG || "weline-skills-v1.0.0";
const githubToken = process.env.GH_TOKEN || process.env.GITHUB_TOKEN || "";

function shouldUseColor() {
  return process.env.NO_COLOR !== "1" && (process.stdout.isTTY || process.env.FORCE_COLOR);
}

function color(text, code) {
  return shouldUseColor() ? `\u001b[${code}m${text}\u001b[0m` : text;
}

function red(text) {
  return color(text, "31");
}

function green(text) {
  return color(text, "32");
}

function blue(text) {
  return color(text, "34");
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

function printGuide() {
  console.log("=".repeat(72));
  console.log("Skills.sh publish setup guide");
  console.log("=".repeat(72));
  console.log("Skills.sh publishing uses GitHub's skill publisher:");
  console.log(`  gh skill publish dev/ai/skills --tag ${tag}`);
  console.log("");
  console.log("Local prerequisites:");
  console.log("  1. Install GitHub CLI: https://cli.github.com/");
  console.log("  2. Authenticate: gh auth login --web");
  console.log("  3. Validate only: node tools/publish-skills-sh.mjs --dry-run");
  console.log("  4. Publish: node tools/publish-skills-sh.mjs");
  console.log("");
  console.log("CI prerequisites:");
  console.log("  1. Provide GH_TOKEN or GITHUB_TOKEN.");
  console.log("  2. The workflow must have contents: write permission.");
  console.log("  3. Optionally set SKILLS_SH_TAG to override the release tag.");
  console.log("=".repeat(72));
}

function fail(message, details = []) {
  console.error("");
  console.error(errorText(`ERROR: ${message}`));
  for (const detail of details) {
    console.error(errorText(`- ${detail}`));
  }
  console.error("");
  printGuide();
  process.exit(1);
}

function run(cmd, args) {
  const { command, commandArgs } = buildCommandInvocation(cmd, args);
  const result = spawnSync(command, commandArgs, {
    stdio: "inherit",
    shell: false,
  });

  if (result.error) {
    fail("Command failed to start.", [
      `${cmd} ${args.join(" ")}`,
      result.error.message,
    ]);
  }

  if (result.status !== 0) {
    fail("Skills.sh publish command failed.", [
      `${cmd} ${args.join(" ")}`,
      "Run the dry-run command first to see validation errors before publishing.",
    ]);
  }
}

function runCaptured(cmd, args) {
  const { command, commandArgs } = buildCommandInvocation(cmd, args);
  return spawnSync(command, commandArgs, {
    stdio: "pipe",
    encoding: "utf8",
    shell: false,
  });
}

function runStreaming(cmd, args) {
  const { command, commandArgs } = buildCommandInvocation(cmd, args);

  return new Promise((resolveRun, rejectRun) => {
    const child = spawn(command, commandArgs, {
      stdio: ["inherit", "pipe", "pipe"],
      shell: false,
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

function verifyGhCli() {
  const result = runCaptured("gh", ["--version"]);

  if (result.error || result.status !== 0) {
    fail("GitHub CLI is not available.", [
      "Install GitHub CLI before publishing to Skills.sh locally.",
      "On GitHub Actions, the hosted runner should provide gh automatically.",
    ]);
  }
}

async function ensureGhAuth() {
  if (githubToken) {
    console.log(green("Using GH_TOKEN/GITHUB_TOKEN for non-interactive GitHub authentication."));
    return;
  }

  const result = runCaptured("gh", ["auth", "status", "--hostname", "github.com"]);

  if (result.status === 0) {
    return;
  }

  if (!isInteractiveTerminal()) {
    fail("GitHub CLI is not authenticated.", [
      "Run `gh auth login --web` locally before publishing.",
      "In CI, ensure GH_TOKEN or GITHUB_TOKEN is available.",
      result.stderr ? `CLI output: ${result.stderr.trim()}` : "The GitHub auth status check failed.",
    ]);
  }

  console.log(green("No GitHub CLI login session found. Launching browser authentication..."));

  try {
    await runStreaming("gh", ["auth", "login", "--web", "--hostname", "github.com", "--git-protocol", "https"]);
  } catch (error) {
    fail("GitHub CLI browser authentication did not complete successfully.", [
      "Complete the browser authorization flow opened by `gh auth login --web`.",
      error.commandOutput ? `CLI output: ${error.commandOutput}` : "The GitHub CLI login flow exited with a non-zero status.",
    ]);
  }

  const afterLogin = runCaptured("gh", ["auth", "status", "--hostname", "github.com"]);
  if (afterLogin.status !== 0) {
    fail("GitHub CLI authentication still failed after login.", [
      afterLogin.stderr ? `CLI output: ${afterLogin.stderr.trim()}` : "Run `gh auth status --hostname github.com` for details.",
    ]);
  }
}

if (!existsSync(skillsDir)) {
  fail("Skills directory not found.", [
    `Checked path: ${skillsDir}`,
    "Run from the repository root, or pass a valid skills directory path.",
  ]);
}

const args = ["skill", "publish", skillsDir];

if (dryRun) {
  args.push("--dry-run");
} else {
  args.push("--tag", tag);
}

console.log(green(`Publishing Skills.sh source directory: ${skillsDir}`));
console.log(green(dryRun ? "Mode: dry-run validation only." : `Mode: publish with tag ${tag}.`));
verifyGhCli();
await ensureGhAuth();
run("gh", args);
console.log(green("Skills.sh publish command completed."));
