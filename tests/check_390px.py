with open(r'c:\xampp\htdocs\sti-activity-system\assets\css\main.css', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find('max-width: 390px;')
if idx != -1:
    print("FOUND max-width: 390px; at index", idx)
    print(text[idx-150:idx+250])
else:
    print("Not found")
