import os
import re

dirs = ['faculty', 'program_head', 'dean', 'admin1', 'admin2', 'auth']

issues = []

for d in dirs:
    if not os.path.isdir(d): continue
    for f in os.listdir(d):
        if not f.endswith('.php'): continue
        p = os.path.join(d, f)
        with open(p, 'r', encoding='utf-8', errors='ignore') as fh:
            content = fh.read()

        # Check inline styles and <style> blocks for fixed widths
        # 1. Unscoped min-width: 900+ or width: 900+
        fixed_widths = re.findall(r'(?:min-width|width)\s*:\s*([7-9]\d\d|1\d\d\d)px', content)
        
        # 2. Check if page has sidebar but sidebar isn't hiding or main-wrap margin is fixed
        has_sidebar = 'includes/sidebar.php' in content
        
        # 3. Check for grid-template-columns with multiple columns not in media queries
        style_blocks = re.findall(r'<style[^>]*>(.*?)</style>', content, re.DOTALL)
        for sb in style_blocks:
            # strip media queries
            sb_no_mq = re.sub(r'@media[^{]+\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}', '', sb, flags=re.DOTALL)
            grids = re.findall(r'grid-template-columns\s*:\s*repeat\(([3-9]|\d{2,})', sb_no_mq)
            if grids:
                issues.append((f"{d}/{f}", f"Hardcoded {grids[0]} columns outside media query"))

print(f"Total potential grid/style issues found: {len(issues)}")
for target, desc in issues[:20]:
    print(f" - {target}: {desc}")
