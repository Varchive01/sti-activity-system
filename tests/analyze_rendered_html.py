import re

with open('tests/test_faculty_dashboard_rendered.html', 'r', encoding='utf-8') as f:
    html = f.read()

# 1. Check all min-width and width in inline style="" attributes
inline_styles = re.findall(r'style=["\']([^"\']+)["\']', html)
wide_inline = []
for s in inline_styles:
    m = re.findall(r'(min-width|width)\s*:\s*([4-9]\d\d|1\d\d\d)px', s)
    if m:
        wide_inline.append((s, m))

print(f"Total wide inline styles (>=400px): {len(wide_inline)}")
for s, m in wide_inline[:10]:
    print("  Wide style:", s[:80], "->", m)

# 2. Check layout containers
print("\n--- Layout Containers ---")
for tag in ['dashboard-layout', 'dashboard-main', 'dashboard-rail', 'main-wrap', 'content', 'sidebar']:
    matches = re.findall(r'<[^>]+class=["\'][^"\']*' + tag + r'[^"\']*["\'][^>]*>', html)
    print(f"Class '{tag}': {len(matches)} occurrences")
    for m in matches[:2]:
        print("  ", m[:100])

# 3. Check all <style> blocks in the rendered page
style_blocks = re.findall(r'<style[^>]*>(.*?)</style>', html, re.DOTALL)
print(f"\nTotal <style> blocks in rendered page: {len(style_blocks)}")

# Check rules inside <style> blocks that specify wide min-width outside media queries
for idx, sb in enumerate(style_blocks):
    # remove media queries
    sb_no_mq = re.sub(r'@media[^{]+\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}', '', sb, flags=re.DOTALL)
    wide_rules = re.findall(r'([^{]+)\{([^}]+)\}', sb_no_mq)
    for sel, decl in wide_rules:
        m = re.findall(r'(min-width|width)\s*:\s*([4-9]\d\d|1\d\d\d)px', decl)
        if m:
            print(f"Style Block {idx+1} Wide Rule: {sel.strip()[:60]} -> {m}")
