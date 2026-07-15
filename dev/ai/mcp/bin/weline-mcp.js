#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');

const php = process.env.WELINE_MCP_PHP || process.env.PHP_BINARY || 'php';
const server = path.join(__dirname, 'learning-mcp');

if (!fs.existsSync(server)) {
  process.stderr.write('Weline MCP launcher error: PHP server entry is missing: ' + server + '\n');
  process.exit(1);
}

let launchFailed = false;
const child = spawn(php, [server, ...process.argv.slice(2)], {
  env: process.env,
  stdio: 'inherit',
  windowsHide: true,
});

child.once('error', (error) => {
  launchFailed = true;
  if (error && error.code === 'ENOENT') {
    process.stderr.write('Weline MCP launcher error: PHP 8.2+ was not found. Set WELINE_MCP_PHP or PHP_BINARY.\n');
    process.exitCode = 127;
    return;
  }
  process.stderr.write('Weline MCP launcher error: ' + error.message + '\n');
  process.exitCode = 1;
});

const signalNumbers = { SIGINT: 2, SIGTERM: 15 };
for (const signal of Object.keys(signalNumbers)) {
  process.on(signal, () => {
    if (child.exitCode === null && !child.killed) {
      child.kill(signal);
    }
  });
}

child.once('exit', (code, signal) => {
  if (launchFailed) {
    return;
  }
  process.exitCode = typeof code === 'number' ? code : 128 + (signalNumbers[signal] || 1);
});
