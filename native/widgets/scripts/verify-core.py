from pathlib import Path
import subprocess
import sys
root = Path(__file__).resolve().parents[1]
directory = root / ".build/verification"
directory.mkdir(parents=True, exist_ok=True)
sdk = subprocess.check_output(["xcrun", "--show-sdk-path"], text=True).strip()
files = sorted((root / "Sources/Core").glob("*.swift")) + [root / "Verification/main.swift"]
binary = directory / "checks"
subprocess.run(["xcrun","swiftc","-sdk",sdk,"-target","arm64-apple-macosx14.0","-module-cache-path",str(root / ".build/poc/cache")]
    + [str(file) for file in files] + ["-o",str(binary)],check=True)
subprocess.run([str(binary)] + sys.argv[1:],check=True)
