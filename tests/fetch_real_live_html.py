import urllib.request
import ssl
import re

ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE

# 1. Fetch challenge page
req = urllib.request.Request('https://sti-armes.freedev.app/auth/login.php', headers={'User-Agent': 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X)'})
with urllib.request.urlopen(req, timeout=15, context=ctx) as r:
    html = r.read().decode('utf-8', errors='ignore')

print("Challenge HTML snippet:", html[:200])

# Extract a, b, c from script
# var a=toNumbers("..."),b=toNumbers("..."),c=toNumbers("...");
match = re.search(r'toNumbers\(["\']([0-9a-fA-F]+)["\']\)[^"]*toNumbers\(["\']([0-9a-fA-F]+)["\']\)[^"]*toNumbers\(["\']([0-9a-fA-F]+)["\']\)', html)
if match:
    a_hex, b_hex, c_hex = match.group(1), match.group(2), match.group(3)
    print("Found keys:")
    print(" a:", a_hex)
    print(" b:", b_hex)
    print(" c:", c_hex)
else:
    print("Could not find keys in HTML")
