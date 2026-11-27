import urllib.request
import ssl

ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE

urls = [
    'https://sti-armes.freedev.app/auth/login.php',
    'https://sti-armes.freedev.app/assets/css/main.css'
]

for url in urls:
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X)'})
        with urllib.request.urlopen(req, timeout=15, context=ctx) as r:
            code = r.getcode()
            headers = dict(r.info())
            raw_body = r.read()
            body = raw_body.decode('utf-8', errors='ignore')
            print(f"=== URL: {url} ===")
            print(f"Status: {code}")
            print(f"Content-Type: {headers.get('Content-Type')}")
            print(f"Content-Length header: {headers.get('Content-Length')}")
            print(f"Actual downloaded length: {len(body)} bytes")
            if 'login.php' in url:
                print(f"Has viewport: {'viewport' in body.lower()}")
                print("HTML Start:")
                for line in body.splitlines()[:20]:
                    print("  ", line)
            elif 'main.css' in url:
                print("CSS snippet start:", body[:200].replace('\n', ' '))
                print("CSS snippet end:", body[-200:].replace('\n', ' '))
    except Exception as e:
        print(f"=== URL: {url} === ERROR: {e}")
