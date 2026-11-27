import os

root = r'c:\xampp\htdocs\sti-activity-system'
app_dirs = ['admin1', 'admin2', 'dean', 'faculty', 'program_head']

has_toggle = []
missing_toggle = []

for ad in app_dirs:
    dp = os.path.join(root, ad)
    for f in os.listdir(dp):
        if not f.endswith('.php'):
            continue
        fpath = os.path.join(dp, f)
        relpath = f"{ad}/{f}"
        with open(fpath, 'r', encoding='utf-8', errors='ignore') as fp:
            content = fp.read()
        
        # Only check pages that include sidebar.php
        if 'sidebar.php' in content:
            if 'mobileNavToggle' in content:
                has_toggle.append(relpath)
            else:
                missing_toggle.append(relpath)

print(f"Pages with mobileNavToggle ({len(has_toggle)}):")
for p in has_toggle[:10]:
    print("  ", p)

print(f"\nPages MISSING mobileNavToggle ({len(missing_toggle)}):")
for p in missing_toggle[:20]:
    print("  ", p)
if len(missing_toggle) > 20:
    print(f"  ... and {len(missing_toggle)-20} more")
