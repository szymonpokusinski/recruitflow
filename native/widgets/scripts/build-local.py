"""Compile actual native binaries using CLT. No installation or production signing."""
from pathlib import Path
import plistlib
import subprocess

root = Path(__file__).resolve().parents[1]
build = root / ".build/local"
app = build / "RecruitFlow Widgets.app"
extension = app / "Contents/PlugIns/RecruitFlowWidgetExtension.appex"
sdk = subprocess.check_output(["xcrun","--show-sdk-path"],text=True).strip()
shared = sorted((root / "Sources/Core").glob("*.swift")) + sorted((root / "Sources/Shared").glob("*.swift"))
for target,bundle,identifier,executable in [
    ("Host",app,"pl.szymonpokusinski.recruitflow.widgets","RecruitFlow Widgets"),
    ("Widgets",extension,"pl.szymonpokusinski.recruitflow.widgets.extension","RecruitFlowWidgetExtension")]:
    (bundle / "Contents/MacOS").mkdir(parents=True,exist_ok=True)
    info = plistlib.loads((root / ("Config/Host-Info.plist" if target=="Host" else "Config/Widget-Info.plist")).read_bytes())
    info["CFBundleIdentifier"]=identifier;info["CFBundleExecutable"]=executable
    with (bundle / "Contents/Info.plist").open("wb") as file: plistlib.dump(info,file)
    command=["xcrun","swiftc","-parse-as-library","-sdk",sdk,"-target","arm64-apple-macosx14.0",
        "-module-cache-path",str(root / ".build/poc/cache")]
    if target=="Widgets":command += ["-application-extension","-Xlinker","-e","-Xlinker","_NSExtensionMain"]
    files=shared+sorted((root / "Sources" / target).glob("*.swift"))
    subprocess.run(command+[str(file) for file in files]+["-o",str(bundle / "Contents/MacOS" / executable)],check=True)
    print(target+" executable compiled",flush=True)
print("Unsigned build only; use the Xcode project with a valid team/App Group for installation.")
