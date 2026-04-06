import re

with open("web/app/public/pages/dashboard.html", "r") as f:
    html = f.read()

m = re.search(r'<script>(.*?)</script>', html, re.DOTALL)
if not m:
    print("No script tag found")
    exit(1)

js = m.group(1)
lines = js.split("\n")
print(f"Total JS lines: {len(lines)}")

# Try parsing with increasing line count to find the error
import subprocess
result = subprocess.run(
    ["node", "-e", """
const fs=require('fs');
const code = fs.readFileSync('/dev/stdin','utf8');
try { new Function(code); console.log('VALID'); }
catch(e) { console.log('ERROR: '+e.message); }
"""],
    input=js,
    capture_output=True, text=True
)
print("Node result:", result.stdout.strip())
if result.stderr:
    print("Stderr:", result.stderr.strip()[:200])

# Also try to find the issue by checking for common patterns
for i, line in enumerate(lines):
    # Check for unbalanced parentheses/brackets in complex lines
    if "FormData" in line and "new FormData" in line:
        print(f"\nLine {i+1} (0-indexed {i}): has new FormData:")
        print(f"  {line.strip()[:150]}")
