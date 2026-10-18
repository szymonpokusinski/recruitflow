"""Local ad-hoc WidgetKit experiment. Does not replace an Xcode/signing build."""
from pathlib import Path
import plistlib
import subprocess

root = Path(__file__).resolve().parents[1]
build = root / ".build" / "poc"
app = build / "RecruitFlow Widgets PoC.app"
extension = app / "Contents" / "PlugIns" / "RecruitFlowWidget.appex"
sdk = subprocess.check_output(["xcrun", "--show-sdk-path"], text=True).strip()
for bundle, identifier, executable, kind in [
    (app, "pl.szymonpokusinski.recruitflow.widgets.poc", "RecruitFlowWidgetsPOC", "APPL"),
    (extension, "pl.szymonpokusinski.recruitflow.widgets.poc.extension", "RecruitFlowWidget", "XPC!"),
]:
    (bundle / "Contents" / "MacOS").mkdir(parents=True, exist_ok=True)
    info = {"CFBundleIdentifier": identifier, "CFBundleExecutable": executable,
            "CFBundleName": "RecruitFlow", "CFBundleDisplayName": "RecruitFlow",
            "CFBundlePackageType": kind, "CFBundleVersion": "1",
            "CFBundleShortVersionString": "0.1", "LSMinimumSystemVersion": "14.0"}
    if kind == "XPC!":
        info["NSExtension"] = {"NSExtensionPointIdentifier": "com.apple.widgetkit-extension"}
    with (bundle / "Contents" / "Info.plist").open("wb") as file:
        plistlib.dump(info, file)
    command = ["xcrun", "swiftc", "-parse-as-library", "-sdk", sdk,
               "-target", "arm64-apple-macosx14.0", "-module-cache-path", str(build / "cache"),
               str(root / "POC" / ("Host.swift" if kind == "APPL" else "Widget.swift")),
               "-o", str(bundle / "Contents" / "MacOS" / executable)]
    if kind == "XPC!":
        command += ["-application-extension", "-Xlinker", "-e", "-Xlinker", "_NSExtensionMain"]
    subprocess.run(command, check=True)
    if kind == "XPC!":
        subprocess.run(["codesign", "--force", "--sign", "-", str(bundle)], check=True)
subprocess.run(["codesign", "--force", "--sign", "-", str(app)], check=True)
print(app)
