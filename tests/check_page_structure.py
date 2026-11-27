with open(r'c:\xampp\htdocs\sti-activity-system\assets\css\main.css', 'r', encoding='utf-8') as f:
    text = f.read()

import re
matches = [m.start() for m in re.finditer(r'\.main-wrap\b', text)]
print(f"Total .main-wrap occurrences: {len(matches)}")
for idx in matches:
    print(text[idx:idx+250])
    print('===')
