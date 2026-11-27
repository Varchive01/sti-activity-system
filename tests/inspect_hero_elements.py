import re

files = [
    'faculty/dashboard.php',
    'program_head/dashboard.php',
    'admin1/dashboard.php',
    'admin2/dashboard.php',
    'dean/dashboard.php'
]

for fpath in files:
    with open(fpath, 'r', encoding='utf-8') as f:
        text = f.read()
    print(f"=== {fpath} ===")
    matches = re.findall(r'<div[^>]*class=["\'][^"\']*(?:hero|welcome|banner|greeting)[^"\']*["\'][^>]*>', text, re.IGNORECASE)
    for m in matches:
        print("  ", m[:120])
    
    # check styles for hero
    styles = re.findall(r'(\.[a-zA-Z0-9_-]*(?:hero|welcome|banner)[a-zA-Z0-9_-]*\s*\{[^}]*\})', text, re.IGNORECASE)
    for s in styles[:3]:
        print("   STYLE:", s.strip().replace('\n', ' ')[:100])
