import urllib.request
import ssl

ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE

req = urllib.request.Request('https://sti-armes.freedev.app/assets/css/main.css', headers={'User-Agent': 'Mozilla/5.0'})
try:
    with urllib.request.urlopen(req, timeout=15, context=ctx) as r:
        live_css = r.read().decode('utf-8', errors='ignore')

    print("Live CSS size:", len(live_css))
    print("Has 'Universal Modal Containment on Small Screens':", 'Universal Modal Containment on Small Screens' in live_css)
    print("Has 'Universal Table Wrapper Containment':", 'Universal Table Wrapper Containment' in live_css)
    print("Has 'stat-grid > :last-child:nth-child(odd)':", 'stat-grid > :last-child:nth-child(odd)' in live_css)
except Exception as e:
    print("Error:", e)
