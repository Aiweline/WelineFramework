#!/usr/bin/env node

import { existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from "node:fs";
import { join, resolve } from "node:path";
import { spawnSync } from "node:child_process";

const inputDir = process.argv[2] || "dev/ai/skills";
const skillsDir = resolve(process.cwd(), inputDir);
const owner = process.env.CLAWHUB_OWNER || "";
const version = process.env.SKILL_VERSION || "1.0.0";
const dryRun = process.argv.includes("--dry-run");
const manifestPath = resolve(process.cwd(), "clawhub-skill-manifest.json");

function run(cmd, args, options = {}) {
  const result = spawnSync(cmd, args, {
    stdio: "inherit",
    shell: process.platform === "win32",
    ...options,
  });

  if (result.status !== 0) {
    throw new Error(`Command failed: ${cmd} ${args.join(" ")}`);
  }
}

function slugify(name) {
  return name
    .normalize("NFKD")
    .replace(/[^\w\u4e00-\u9fa5-]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .toLowerCase();
}

if (!existsSync(skillsDir)) {
  throw new Error(`Skills directory not found: ${skillsDir}`);
}

const skillDirs = readdirSync(skillsDir, { withFileTypes: true })
  .filter((entry) => entry.isDirectory())
  .map((entry) => join(skillsDir, entry.name))
  .filter((dir) => existsSync(join(dir, "SKILL.md")));

if (skillDirs.length === 0) {
  throw new Error(`No SKILL.md found under: ${skillsDir}`);
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

const manifestDir = resolve(process.cwd());
if (!existsSync(manifestDir)) {
  mkdirSync(manifestDir, { recursive: true });
}

writeFileSync(manifestPath, JSON.stringify(manifest, null, 2), "utf8");

console.log(`Generated ${manifestPath}`);

if (dryRun) {
  console.log("Dry run only. No publish executed.");
  process.exit(0);
}

console.log("Checking clawhub login...");
run("npx", ["clawhub", "whoami"]);

console.log("Publishing skills...");
run("npx", [
  "clawhub",
  "sync",
  "--root",
  skillsDir,
  "--all",
  "--bump",
  "patch",
  "--changelog",
  `Publish WelineFramework Multica role skills ${version}`,
  "--concurrency",
  "2",
]);

console.log("Done.");
console.log(`Check ${manifestPath} for expected URLs.`);
