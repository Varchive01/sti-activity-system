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
    
    print(f"================== {fpath} ==================")
    head_start = text.find('<head>')
    head_end = text.find('</head>')
    head = text[head_start:head_end] if head_start != -1 else text[:3000]
    
    # Check media queries in head
    for m in re.finditer(r'@media[^{]+', head):
        print("  MQ:", m.group(0).strip())
        
    # Check if there are fixed grid-template-columns or min-width
    for line_idx, line in enumerate(head.splitlines()):
        if any(term in line for term in ['grid-template-columns', 'min-width', 'width:', 'flex:']) and '@media' not in line:
            if any(term in line for term in ['px', 'fr', '%']):
                if any(k in line for k in ['dashboard', 'stat', 'layout', 'grid', 'table', 'split', 'content', 'sidebar']):
                    print(f"    Line {line_idx+1}: {line.strip()[:90]}")
