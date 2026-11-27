with open('faculty/dashboard.php', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find('@media (max-width: 991.98px)')
print(text[idx:idx+800])
