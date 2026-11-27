import urllib.request
import ssl
import re

# Simulate browser following the __test cookie
ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE

# Let's inspect local auth/login.php first
with open('auth/login.php', 'r', encoding='utf-8') as f:
    local_login = f.read()

print("Local auth/login.php viewport line:")
for line in local_login.splitlines()[:25]:
    if 'viewport' in line.lower():
        print("  Found:", line)

# Let's check faculty/dashboard.php
with open('faculty/dashboard.php', 'r', encoding='utf-8') as f:
    local_faculty = f.read()

print("Local faculty/dashboard.php viewport line:")
for line in local_faculty.splitlines()[:25]:
    if 'viewport' in line.lower():
        print("  Found:", line)
