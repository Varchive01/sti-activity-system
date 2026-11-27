import os
import re

root = r'c:\xampp\htdocs\sti-activity-system'
app_dirs = ['admin1', 'admin2', 'dean', 'faculty', 'program_head', 'auth', 'includes', 'reports']
root_files = ['evaluate.php', 'notifications.php']

all_php_files = []
for d in app_dirs:
    dp = os.path.join(root, d)
    if os.path.isdir(dp):
        for f in os.listdir(dp):
            if f.endswith('.php'):
                all_php_files.append((d, f, os.path.join(dp, f)))

for rf in root_files:
    p = os.path.join(root, rf)
    if os.path.isfile(p):
        all_php_files.append(('.', rf, p))

print(f"Total PHP files audited: {len(all_php_files)}")

# Check 1: Missing Viewport Meta Tag in full HTML pages
missing_viewport = []
for d, f, path in all_php_files:
    with open(path, 'r', encoding='utf-8', errors='ignore') as fh:
        c = fh.read()
    if '<!DOCTYPE html' in c or '<html' in c:
        if 'name="viewport"' not in c and "name='viewport'" not in c:
            missing_viewport.append(f"{d}/{f}")

print(f"Missing Viewport Meta Tag: {len(missing_viewport)}")
for m in missing_viewport:
    print(f"  {m}")

# Check 2: Hardcoded fixed widths on cards, containers, or bodies that can overflow 360px
potential_overflows = []
for d, f, path in all_php_files:
    with open(path, 'r', encoding='utf-8', errors='ignore') as fh:
        lines = fh.readlines()
    for idx, l in enumerate(lines):
        # find inline style or style block with width: [4-9]00px or min-width: [4-9]00px
        m = re.search(r'(?<!max-)(width|min-width)\s*:\s*([4-9]\d{2,3}|[1-9]\d{3,4})px', l)
        if m:
            val = int(m.group(2))
            # skip if inside desktop media query or if line has overflow-x
            if '@media' in l or 'overflow' in l:
                continue
            if any(term in l for term in ['table', 'card', 'container', 'modal', 'content', 'wrap', 'banner', 'grid']):
                potential_overflows.append((f"{d}/{f}", idx+1, m.group(0), l.strip()[:80]))

print(f"\nPotential Uncontained Widths ({len(potential_overflows)}):")
for item in potential_overflows:
    print(f"  {item[0]}:{item[1]} -> {item[2]} | {item[3]}")
