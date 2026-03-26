#!/usr/bin/env python3
"""
Skill Converter: Convert existing SKILL.md into personal skills entries.

This script scans:
- dev/ai/skills/**/SKILL.md
- dev/ai/codex-skills/**/SKILL.md

For each found skill, it creates a personal entry under:
- dev/ai/my-skills/<skill-slug>/README.md

Usage:
- Run: python3 dev/ai/tools/convert-skills.py
"""
import os
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]  # repo/dev/ai
SKILL_ROOTS = [Path(ROOT, 'skills'), Path(ROOT, 'codex-skills')]
OUTPUT_ROOT = Path(ROOT, 'my-skills')

def slugify(name: str) -> str:
    s = name.strip().lower()
    s = re.sub(r"[^a-z0-9]+", '-', s)
    s = re.sub(r"-+", '-', s)
    return s.strip('-')

def parse_front_matter(text: str) -> dict:
    fm = {}
    lines = text.splitlines()
    in_fm = False
    current_key = None
    for line in lines:
        if line.strip() == '---' and not in_fm:
            in_fm = True
            continue
        if line.strip() == '---' and in_fm:
            break
        if in_fm:
            if ':' in line:
                key, val = line.split(':', 1)
                key = key.strip()
                val = val.strip()
                if val == '':
                    current_key = key
                    fm[key] = []
                else:
                    fm[key] = val
                    current_key = None
            elif line.strip().startswith('-') and current_key:
                fm[current_key].append(line.strip().lstrip('-').strip())
    return fm

def extract_skill_info(skill_md_path: Path) -> dict:
    content = skill_md_path.read_text(encoding='utf-8')
    parts = content.split('\n---\n', 1)
    if len(parts) == 2:
        front, body = parts
        fm = parse_front_matter(front)
        fm['body'] = body
        return fm
    # Fallback: try to parse simple header lines
    return {'name': skill_md_path.stem, 'description': ''}

def main():
    OUTPUT_ROOT.mkdir(parents=True, exist_ok=True)
    found = []
    for root in SKILL_ROOTS:
        if not root.exists():
            continue
        for md in root.rglob('SKILL.md'):
            info = extract_skill_info(md)
            name = info.get('name', md.parent.name)
            slug = slugify(name)
            dest_dir = OUTPUT_ROOT / slug
            dest_dir.mkdir(parents=True, exist_ok=True)
            readme_path = dest_dir / 'README.md'
            # Build a simple summary README
            with readme_path.open('w', encoding='utf-8') as f:
                f.write(f"# {name}\n\n")
                desc = info.get('description', '')
                if isinstance(desc, list):
                    desc = ' '.join(desc)
                if desc:
                    f.write(desc + "\n\n")
                f.write("来源: " + str(md) + "\n")
                if 'globs' in info:
                    f.write('\n## 触发点\n')
                    for g in info['globs']:
                        f.write(f"- {g}\n")
            found.append((name, readme_path))
    print("Converted skills:")
    for name, path in found:
        print(f"- {name} -> {path}")

if __name__ == '__main__':
    main()
