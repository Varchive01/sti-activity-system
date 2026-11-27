import urllib.request
import ssl

ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE

req = urllib.request.Request('https://sti-armes.freedev.app/evaluate.php', headers={'User-Agent': 'Mozilla/5.0'})
try:
    with urllib.request.urlopen(req, timeout=15, context=ctx) as r:
        content = r.read().decode('utf-8', errors='ignore')
    print("Live evaluate.php length:", len(content))
    print("Has '?v=1.1.0':", '?v=1.1.0' in content)
    print("Has 'box-sizing:border-box':", 'box-sizing:border-box' in content or 'box-sizing: border-box' in content)
    for line in content.splitlines()[:30]:
        if 'stylesheet' in line or 'viewport' in line:
            print("  ", line.strip())
except Exception as e:
    print("Error:", e)
