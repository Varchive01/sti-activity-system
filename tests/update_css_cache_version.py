import os
import re

dirs = ['faculty', 'program_head', 'dean', 'admin1', 'admin2', 'auth', 'includes', '.']
updated = []

pattern = re.compile(r'href=["\'](<\?=\s*BASE_URL\s*\?>/assets/css/main\.css)(?:\?v=[^"\']*)?["\']')

for d in dirs:
    if d == '.':
        files = [f for f in os.listdir('.') if f.endswith('.php')]
        for f in files:
            p = os.path.join('.', f)
            with open(p, 'r', encoding='utf-8', errors='ignore') as fh:
                content = fh.read()
            if 'main.css' in content:
                new_content = pattern.sub(r'href="\1?v=1.1.0"', content)
                if new_content != content:
                    with open(p, 'w', encoding='utf-8') as fh:
                        fh.write(new_content)
                    updated.append(p)
        continue
    if not os.path.isdir(d): continue
    for root, _, files in os.walk(d):
        for f in files:
            if not f.endswith('.php'): continue
            p = os.path.join(root, f)
            with open(p, 'r', encoding='utf-8', errors='ignore') as fh:
                content = fh.read()
            
            if 'main.css' in content:
                new_content = pattern.sub(r'href="\1?v=1.1.0"', content)
                if new_content != content:
                    with open(p, 'w', encoding='utf-8') as fh:
                        fh.write(new_content)
                    updated.append(p)

print(f"Total files updated with ?v=1.1.0 cache busting: {len(updated)}")
for p in updated:
    print(f"  Updated: {p}")
