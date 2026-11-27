import os
import re

root = r'c:\xampp\htdocs\sti-activity-system'
app_dirs = ['admin1', 'admin2', 'dean', 'faculty', 'program_head', 'auth', 'includes', 'reports', 'maintenance']
root_files = ['evaluate.php', 'index.php', 'notifications.php']

missing_viewports = []
fixed_widths = []

files_to_check = []
for ad in app_dirs:
    dp = os.path.join(root, ad)
    if os.path.exists(dp):
        for f in os.listdir(dp):
            if f.endswith('.php'):
                files_to_check.append(os.path.join(dp, f))

for rf in root_files:
    files_to_check.append(os.path.join(root, rf))

print(f"Total files to check: {len(files_to_check)}")

for fpath in files_to_check:
    relpath = os.path.relpath(fpath, root).replace('\\', '/')
    try:
        with open(fpath, 'r', encoding='utf-8', errors='ignore') as f:
            content = f.read()
    except Exception:
        continue

    # Viewport check if it renders full HTML
    if '<!DOCTYPE html' in content or '<html' in content:
        if 'name="viewport"' not in content and "name='viewport'" not in content:
            missing_viewports.append(relpath)

    # Check for hardcoded width or min-width >= 400px in style tags or inline styles
    lines = content.splitlines()
    in_style = False
    for idx, line in enumerate(lines):
        if '<style' in line:
            in_style = True
        if '</style>' in line:
            in_style = False
            
        m = re.search(r'(?<!max-)(width|min-width)\s*:\s*([4-9]\d{2,3}|[1-9]\d{3,4})px', line, re.IGNORECASE)
        if m:
            val = int(m.group(2))
            # skip if inside desktop media query like min-width: 1024px or min-width: 992px
            if '@media' in line:
                continue
            fixed_widths.append((relpath, idx + 1, m.group(0), line.strip()[:90]))

print(f"=== MISSING VIEWPORT TAGS ({len(missing_viewports)}) ===")
for p in missing_viewports:
    print(f"  {p}")

print(f"\n=== HARDCODED FIXED WIDTHS >= 400px ({len(fixed_widths)}) ===")
for item in fixed_widths:
    print(f"  {item[0]}:{item[1]} -> {item[2]} | {item[3]}")
