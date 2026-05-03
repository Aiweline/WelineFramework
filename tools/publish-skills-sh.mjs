#!/usr/bin/env node

import { existsSync } from "node:fs";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";

const cliArgs = process.argv.slice(2);
const positionalArgs = cliArgs.filter((arg) => !arg.startsWith("--"));
const inputDir = positionalArgs[0] || "dev/ai/skills";
const skillsDir = resolve(process.cwd(), inputDir);
const dryRun = cliArgs.includes("--dry-run");
const tag = process.env.SKILLS_SH_TAG || "weline-skills-v1.0.0";

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

function printGuide() {
  console.log("=".repeat(72));
  console.log("Skills.sh publish setup guide");
  console.log("=".repeat(72));
  console.log("Skills.sh publishing uses GitHub's skill publisher:");
  console.log(`  gh skill publish dev/ai/skills --tag ${tag}`);
  console.log("");
  console.log("Local prerequisites:");
  console.log("  1. Install GitHub CLI: https://cli.github.com/");
  console.log("  2. Authenticate: gh auth login");
  console.log("  3. Validate only: node tools/publish-skills-sh.mjs --dry-run");
  console.log("  4. Publish: node tools/publish-skills-sh.mjs");
  console.log("");
  console.log("CI prerequisites:");
  console.log("  1. The workflow must have contents: write permission.");
  console.log("  2. Optionally set SKILLS_SH_TAG to override the release tag.");
  console.log("=".repeat(72));
}

function fail(message, details = []) {
  console.error("");
  console.error(red(`ERROR: ${message}`));
  for (const detail of details) {
    console.error(red(`- ${detail}`));
  }
  console.error("");
  printGuide();
  process.exit(1);
}

function run(cmd, args) {
  const result = spawnSync(cmd, args, {
    stdio: "inherit",
    shell: process.platform === "win32",
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

function verifyGhCli() {
  const result = spawnSync("gh", ["--version"], {
    stdio: "pipe",
    encoding: "utf8",
    shell: process.platform === "win32",
  });

  if (result.error || result.status !== 0) {
    fail("GitHub CLI is not available.", [
      "Install GitHub CLI before publishing to Skills.sh locally.",
      "On GitHub Actions, the hosted runner should provide gh automatically.",
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
run("gh", args);
console.log(green("Skills.sh publish command completed."));
