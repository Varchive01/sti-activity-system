with open('faculty/calendar.php', 'r', encoding='utf-8') as f:
    text = f.read()

import re
matches = re.findall(r'@media[^{]+', text)
print(f"Total media queries in calendar.php: {len(matches)}")
for m in matches:
    print("  ", m.strip())
