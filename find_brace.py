import re

with open("web/app/public/pages/dashboard.html", "r") as f:
    html = f.read()

m = re.search(r"<script>(.*?)</script>", html, re.DOTALL)
js = m.group(1)
lines = js.split("\n")

depth = 0
for i, line in enumerate(lines):
    line_depth_start = depth
    for ch in line:
        if ch == "{":
            depth += 1
        elif ch == "}":
            depth -= 1
    if depth != line_depth_start:
        print("L%d (depth %d->%d): %s" % (i+1, line_depth_start, depth, line.strip()[:120]))

print("\nFinal depth: %d" % depth)
