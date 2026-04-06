import re, subprocess, tempfile, os

with open("web/app/public/pages/dashboard.html", "r") as f:
    html = f.read()

m = re.search(r'<script>(.*?)</script>', html, re.DOTALL)
js = m.group(1)
lines = js.split("\n")

def test_code(code):
    tf = tempfile.NamedTemporaryFile(mode='w', suffix='.js', delete=False, dir='/tmp')
    tf.write(code)
    tf.close()
    r = subprocess.run(['node', '--check', tf.name], capture_output=True, text=True)
    os.unlink(tf.name)
    return r.returncode == 0, r.stderr.strip()[:200]

# Binary search for the problematic line
lo, hi = 0, len(lines)
while lo < hi - 1:
    mid = (lo + hi) // 2
    code_top = "\n".join(lines[:mid])
    ok, err = test_code(code_top + "\n;")
    print(f"  Lines 1-{mid}: {'OK' if ok else 'ERR: ' + err}")
    if not ok:
        hi = mid
    else:
        lo = mid

print(f"\nError is around line {lo} (1-indexed)")
for i in range(max(0, lo-3), min(len(lines), lo+3)):
    marker = " >>>" if i == lo-1 else "    "
    print(f"{marker} L{i+1}: {lines[i].strip()[:120]}")
