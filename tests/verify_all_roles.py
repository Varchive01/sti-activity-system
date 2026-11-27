import os

roles = ['faculty', 'program_head', 'dean', 'admin1', 'admin2', 'auth']
print("=== Verification Across All User Roles ===")

for role in roles:
    d = os.path.join('.', role)
    if not os.path.exists(d):
        continue
    php_files = [f for f in os.listdir(d) if f.endswith('.php')]
    viewport_count = 0
    main_css_count = 0
    sidebar_count = 0
    for f in php_files:
        p = os.path.join(d, f)
        with open(p, 'r', encoding='utf-8', errors='ignore') as fh:
            c = fh.read()
            if 'name="viewport"' in c:
                viewport_count += 1
            if 'main.css' in c:
                main_css_count += 1
            if 'sidebar.php' in c:
                sidebar_count += 1
    print(f"Role [{role.upper()}]: {len(php_files)} files | Viewport: {viewport_count} | main.css: {main_css_count} | sidebar: {sidebar_count}")
