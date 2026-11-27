import os
import re

modal_classes = set()
for root, dirs, files in os.walk('.'):
    if any(ignore in root for ignore in ['node_modules', '.git', 'vendor', '.gemini', 'uploads']):
        continue
    for f in files:
        if f.endswith('.php'):
            p = os.path.join(root, f)
            with open(p, 'r', encoding='utf-8', errors='ignore') as fh:
                content = fh.read()
                matches = re.findall(r'class=["\']([^"\']*(?:modal|dialog|popup)[^"\']*)["\']', content, re.I)
                for m in matches:
                    for cls in m.split():
                        if any(k in cls.lower() for k in ['modal', 'dialog', 'popup']):
                            modal_classes.add(cls)

print('Found modal classes:', sorted(list(modal_classes)))
