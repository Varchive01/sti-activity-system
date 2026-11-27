import os, re

for root, dirs, files in os.walk('.'):
    if '.git' in root or 'node_modules' in root:
        continue
    for f in files:
        if f.endswith('.php') or f.endswith('.js'):
            p = os.path.join(root, f)
            try:
                with open(p, 'r', encoding='utf-8', errors='ignore') as fh:
                    for i, line in enumerate(fh, 1):
                        if 'submitted' in line.lower() and 'under review' in line.lower():
                            print(f"{p}:{i}: {line.strip()}")
            except Exception:
                pass
