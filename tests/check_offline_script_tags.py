import os, re
root = r'c:\xampp\htdocs\sti-activity-system'
for dp, _, fnames in os.walk(root):
    if any(s in dp for s in ['.git', 'node_modules', 'vendor', 'backups', 'uploads']): continue
    for fn in fnames:
        if not fn.endswith('.php'): continue
        p = os.path.join(dp, fn)
        with open(p, 'r', encoding='utf-8', errors='ignore') as f:
            c = f.read()
        m = re.findall(r'offline-drafts\.js[^\'\"\>]*', c)
        if m:
            print(f"{os.path.relpath(p, root)}: {m}")
