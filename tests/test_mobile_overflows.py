import re

def check_mobile_overflows(file_path):
    with open(file_path, 'r', encoding='utf-8') as f:
        content = f.read()
    
    print(f"\n=================== Checking {file_path} ===================")
    
    # 1. Check if <meta name="viewport" is present
    has_viewport = 'name="viewport"' in content or "name='viewport'" in content
    print(f"Viewport meta tag present: {has_viewport}")
    
    # 2. Check CSS link
    css_match = re.search(r'href=[\'"][^\'"]*main\.css[^\'"]*[\'"]', content)
    print(f"main.css link: {css_match.group(0) if css_match else 'NONE'}")
    
    # 3. Check for any elements with inline width > 375px
    inline_widths = re.findall(r'style=[\'"][^\'"]*width:\s*([4-9]\d{2,3}|[1-9]\d{3,4})px[^\'"]*[\'"]', content)
    if inline_widths:
        print(f"Inline style widths > 375px: {inline_widths}")
    else:
        print("Inline style widths > 375px: None")
        
    # 4. Check for <style> blocks and find rules that apply to mobile
    styles = re.findall(r'<style[^>]*>(.*?)</style>', content, re.DOTALL)
    for s_idx, s in enumerate(styles):
        # find min-width without max-width
        lines = s.splitlines()
        for l_num, l in enumerate(lines):
            m = re.search(r'min-width\s*:\s*([5-9]\d{2,3}|[1-9]\d{3,4})px', l)
            if m:
                print(f"Style block {s_idx} line {l_num+1}: {l.strip()}")

for fp in ['faculty/dashboard.php', 'program_head/dashboard.php', 'admin1/dashboard.php', 'admin2/dashboard.php', 'dean/dashboard.php', 'auth/login.php', 'includes/sidebar.php']:
    check_mobile_overflows(fp)
