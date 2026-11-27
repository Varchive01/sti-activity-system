import os
import re

dirs = ['faculty', 'program_head', 'dean', 'admin1', 'admin2', 'auth']
all_files = []
rigid_elements = []

for d in dirs:
    if not os.path.isdir(d): continue
    for f in os.listdir(d):
        if not f.endswith('.php'): continue
        p = os.path.join(d, f)
        all_files.append(p)
        with open(p, 'r', encoding='utf-8', errors='ignore') as fh:
            content = fh.read()
        
        # Check if it renders HTML
        if '<!DOCTYPE' not in content and '<html' not in content:
            continue
            
        # Check style blocks for non-media-query fixed grids or widths
        style_blocks = re.findall(r'<style[^>]*>(.*?)</style>', content, re.DOTALL)
        for sb in style_blocks:
            sb_no_mq = re.sub(r'@media[^{]+\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}', '', sb, flags=re.DOTALL)
            
            # Check wide min-width
            wide_mw = re.findall(r'([^{]+)\{[^}]*?(min-width|width)\s*:\s*([6-9]\d\d|1\d\d\d)px[^}]*?\}', sb_no_mq)
            for sel, prop, val in wide_mw:
                sel_clean = sel.strip().replace('\n', ' ')[:40]
                rigid_elements.append((p, f"Fixed {prop}: {val}px on '{sel_clean}'"))
                
            # Check multi-column grids
            wide_grids = re.findall(r'([^{]+)\{[^}]*?grid-template-columns\s*:\s*repeat\(([3-9]|\d{2,})', sb_no_mq)
            for sel, col_count in wide_grids:
                sel_clean = sel.strip().replace('\n', ' ')[:40]
                rigid_elements.append((p, f"Hardcoded {col_count} columns on '{sel_clean}'"))

print(f"Total HTML files audited: {len(all_files)}")
print(f"Total rigid elements found: {len(rigid_elements)}")
for p, desc in rigid_elements:
    print(f"  {p}: {desc}")
