"""Stand-in for clamscan used by the tests (run via sys.executable).

Exit 0 = clean, 1 = infected (file contains VIRUS-TEST-SIGNATURE),
2 = error (file contains SCANNER-ERROR or is missing). Mirrors clamscan's codes.
"""
import sys

path = sys.argv[-1]
try:
    data = open(path, "rb").read()
except OSError as e:
    print(f"ERROR: {e}")
    sys.exit(2)
if b"SCANNER-ERROR" in data:
    print(f"{path}: Can't read file ERROR")
    sys.exit(2)
if b"LIMITS-EXCEEDED" in data:  # what clamscan --alert-exceeds-max prints for a partly scanned file
    print(f"{path}: Heuristics.Limits.Exceeded.MaxFileSize FOUND")
    sys.exit(1)
if b"VIRUS-TEST-SIGNATURE" in data:
    print(f"{path}: Fake.Test.Signature FOUND")
    sys.exit(1)
print(f"{path}: OK")
sys.exit(0)
