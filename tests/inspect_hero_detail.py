with open('faculty/dashboard.php', 'r', encoding='utf-8') as f:
    text = f.read()

import re
matches = [m.start() for m in re.finditer(r'\.faculty-hero-banner\b', text)]
for idx in matches:
    print(text[idx-20:idx+450])
    print('---')
