with open(r'c:\xampp\htdocs\sti-activity-system\faculty\dashboard.php', 'r', encoding='utf-8') as f:
    text = f.read()

import re
matches = [m.start() for m in re.finditer(r'class=[\'"][^\'"]*activity-table', text)]
for idx in matches:
    print('--- MATCH ---')
    print(text[max(0, idx-300):min(len(text), idx+150)])
