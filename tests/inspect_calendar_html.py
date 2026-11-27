with open('faculty/calendar.php', 'r', encoding='utf-8') as f:
    text = f.read()

import re
matches = re.findall(r'<div class=["\'][^"\']+["\']', text)
for m in matches[:15]:
    print(m)
