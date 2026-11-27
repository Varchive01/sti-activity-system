import os
import re

dirs = ['faculty', 'program_head', 'dean', 'admin1', 'admin2', 'auth', 'includes', 'assets/css']
findings = []

pattern = re.compile(r'([^{]+)\{[^}]*?(min-width|width)\s*:\s*([4-9]\d\d|1\d\d\d)px', re.DOTALL)

for d in dirs:
    if not os.path.exists(d): continue
    for root, _, files in os.walk(d):
        for f in files:
            if not (f.endswith('.php') or f.endswith('.css')): continue
            p = os.path.join(root, f)
            with open(p, 'r', encoding='utf-8', errors='ignore') as fh:
                content = fh.read()
            
            # Check for width or min-width in style attributes
            style_matches = re.findall(r'style=["\'][^"\']*?(min-width|width)\s*:\s*([4-9]\d\d|1\d\d\d)px', content)
            for prop, val in style_matches:
                findings.append((p, f"Inline style {prop}: {val}px"))

            # Check style blocks
            style_blocks = re.findall(r'<style[^>]*>(.*?)</style>', content, re.DOTALL)
            for sb in style_blocks:
                for sel, prop, val in pattern.findall(sb):
                    sel_c = sel.strip().replace('\n', ' ')[:40]
                    findings.append((p, f"<style> {sel_c} -> {prop}: {val}px"))

print(f"Total wide declarations (>=400px): {len(findings)}")
for p, desc in findings[:30]:
    print(f"  {p}: {desc}")
