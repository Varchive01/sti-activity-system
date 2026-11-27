import os, re

dirs_to_check = ['admin1', 'admin2', 'dean', 'faculty', 'program_head', 'auth', '.']
php_files = []

for d in dirs_to_check:
    if d == '.':
        for f in os.listdir('.'):
            if f.endswith('.php') and os.path.isfile(f):
                php_files.append(f)
    elif os.path.isdir(d):
        for f in os.listdir(d):
            if f.endswith('.php'):
                php_files.append(os.path.join(d, f))

missing_viewport = []
has_viewport = []
fixed_width_inline = []

viewport_regex = re.compile(r'<meta[^>]+name=[\"\']viewport[\"\']', re.IGNORECASE)
doctype_regex = re.compile(r'<!DOCTYPE\s+html|<html', re.IGNORECASE)
fixed_width_regex = re.compile(r'width:\s*([6-9]\d{2}|[1-9]\d{3,})px', re.IGNORECASE)
min_width_regex = re.compile(r'min-width:\s*([6-9]\d{2}|[1-9]\d{3,})px', re.IGNORECASE)

for path in php_files:
    try:
        with open(path, 'r', encoding='utf-8', errors='ignore') as fh:
            content = fh.read()
            if doctype_regex.search(content):
                if not viewport_regex.search(content):
                    missing_viewport.append(path)
                else:
                    has_viewport.append(path)
                
                matches_w = fixed_width_regex.findall(content)
                matches_mw = min_width_regex.findall(content)
                if matches_w or matches_mw:
                    fixed_width_inline.append((path, matches_w, matches_mw))
    except Exception as e:
        pass

print(f"Total HTML-rendering PHP files found: {len(missing_viewport) + len(has_viewport)}")
print(f"Files WITH viewport tag: {len(has_viewport)}")
print(f"Files MISSING viewport tag: {len(missing_viewport)}")
for p in missing_viewport:
    print(f"  MISSING VIEWPORT: {p}")

print(f"\nFiles with large fixed widths/min-widths (>=600px): {len(fixed_width_inline)}")
for p, w, mw in fixed_width_inline:
    print(f"  {p}: width={w}, min-width={mw}")
