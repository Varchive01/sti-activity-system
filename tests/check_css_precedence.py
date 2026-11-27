with open(r'c:\xampp\htdocs\sti-activity-system\faculty\dashboard.php', 'r', encoding='utf-8') as f:
    text = f.read()

head_start = text.find('<head>')
head_end = text.find('</head>')
head_content = text[head_start:head_end+7]

import re
links = re.findall(r'<link[^>]*>', head_content)
styles = re.findall(r'<style[^>]*>', head_content)
print("LINKS IN HEAD:")
for l in links:
    print("  ", l)
print("STYLES IN HEAD:")
for s in styles:
    print("  ", s)

# Check where main.css is compared to <style>
pos_css = head_content.find('main.css')
pos_style = head_content.find('<style')
print(f"Position of main.css in head: {pos_css}")
print(f"Position of <style> in head: {pos_style}")
if pos_style > pos_css:
    print("WARNING: Inline <style> block is AFTER main.css, meaning rules in <style> OVERRIDE main.css!")
