import re

with open("web/app/public/pages/dashboard.html", "r") as f:
    html = f.read()

m = re.search(r"<script>(.*?)</script>", html, re.DOTALL)
js = m.group(1)
lines = js.split("\n")

dep = {"(": 0, "[": 0, "{": 0}
for i, line in enumerate(lines):
    for ch in line:
        if ch in dep:
            dep[ch] += 1
        elif ch == ")":
            dep["("] -= 1
            if dep["("] < 0:
                print("Unmatched ) at L%d col %d: %s" % (i+1, j, line.strip()[:100]))
                raise SystemExit()
        elif ch == "]":
            dep["["] -= 1
            if dep["["] < 0:
                print("Unmatched ] at L%d col %d: %s" % (i+1, j, line.strip()[:100]))
                raise SystemExit()
        elif ch == "}":
            dep["{"] -= 1
            if dep["{"] < 0:
                print("Unmatched } at L%d col %d: %s" % (i+1, j, line.strip()[:100]))
                raise SystemExit()

print("Final: parens=%d  brackets=%d  braces=%d" % (dep["("], dep["["], dep["{"]))
if dep["("] != 0:
    print("UNCLOSED parens: +%d" % dep["("])
if dep["["] != 0:
    print("UNCLOSED brackets: +%d" % dep["["])
if dep["{"] != 0:
    print("UNCLOSED braces: +%d" % dep["{"])
