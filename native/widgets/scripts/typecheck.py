from pathlib import Path
import subprocess

root = Path(__file__).resolve().parents[1]
sdk = subprocess.check_output(["xcrun", "--show-sdk-path"], text=True).strip()
shared = sorted((root / "Sources" / "Core").glob("*.swift")) + sorted((root / "Sources" / "Shared").glob("*.swift"))
for target in ["Host", "Widgets"]:
    files = shared + sorted((root / "Sources" / target).glob("*.swift"))
    command = ["xcrun", "swiftc", "-typecheck", "-parse-as-library", "-sdk", sdk,
               "-target", "arm64-apple-macosx14.0", "-module-cache-path", str(root / ".build" / "poc" / "cache")]
    if target == "Widgets": command.append("-application-extension")
    subprocess.run(command + [str(file) for file in files], check=True)
    print(target + " typecheck passed", flush=True)
