for fname in ['auth/login.php', 'faculty/dashboard.php', 'admin1/dashboard.php', 'dean/dashboard.php', 'program_head/dashboard.php']:
    with open(fname, 'r', encoding='utf-8') as f:
        c = f.read()
    print(f"{fname}: viewport count = {c.count('viewport')}")
    for idx, l in enumerate(c.splitlines()):
        if 'viewport' in l.lower():
            print(f"   Line {idx+1}: {l.strip()}")
