"""Build a local development .app from the already installed Electron runtime.

No Laravel copy, SQLite copy, secret copy, dependency install or vendor modification.
The resulting .app depends on this project checkout and is not a distribution build.
"""
from pathlib import Path
import json
import plistlib
import shutil
import subprocess
import argparse

parser = argparse.ArgumentParser()
parser.add_argument('--identity', required=True)
parser.add_argument('--update', action='store_true', help='Update only this generated launcher, preserving its Electron runtime')
args = parser.parse_args()
root = Path(__file__).resolve().parents[2]
desktop = Path(__file__).resolve().parent
electron = root / 'vendor/nativephp/desktop/resources/electron'
runtime = root / 'vendor/nativephp/desktop/resources/build'
source = electron / 'node_modules/electron/dist/Electron.app'
destination = desktop / '.build/RecruitFlow.app'
for path in [source, runtime / 'php/php', runtime / 'cacert.pem', electron / 'out/main/index.js']:
    if not path.exists(): raise SystemExit(f'Missing installed NativePHP dependency: {path}')
existing = destination.exists()
if existing and not args.update:
    raise SystemExit('Launcher already exists. Preserve or archive it before rebuilding; no automatic deletion.')
destination.parent.mkdir(parents=True, exist_ok=True)
if not existing: subprocess.run(['ditto', str(source), str(destination)], check=True)
plist_path = destination / 'Contents/Info.plist'
with plist_path.open('rb') as file: info = plistlib.load(file)
if existing and info.get('CFBundleIdentifier') != 'pl.szymonpokusinski.recruitflow.desktop':
    raise SystemExit('Refusing to update an unrelated application bundle')
info.update(CFBundleIdentifier='pl.szymonpokusinski.recruitflow.desktop', CFBundleName='RecruitFlow',
    CFBundleDisplayName='RecruitFlow', CFBundleShortVersionString='0.1.0', CFBundleVersion='1',
    CFBundleURLTypes=[{'CFBundleURLName':'RecruitFlow Navigation','CFBundleURLSchemes':['recruitflow'],'CFBundleTypeRole':'Viewer'}])
with plist_path.open('wb') as file: plistlib.dump(info, file)
application = destination / 'Contents/Resources/app'
application.mkdir(exist_ok=True)
for name in ['main.mjs', 'deep-links.mjs']: shutil.copy2(desktop / name, application / name)
(application / 'package.json').write_text(json.dumps({'name':'recruitflow-desktop-launcher','version':'0.1.0','type':'module','main':'main.mjs'}))
(application / 'settings.json').write_text(json.dumps({'project':str(root),'electron':str(electron),
    'php':str(runtime / 'php/php'),'certificate':str(runtime / 'cacert.pem'),'icon':str(runtime / 'icon.png')}))
frameworks = destination / 'Contents/Frameworks'
for framework in ([] if existing else sorted(frameworks.glob('*.framework'))):
    subprocess.run(['codesign','--force','--sign',args.identity,str(framework)],check=True)
for helper in ([] if existing else sorted(frameworks.glob('*.app'))):
    subprocess.run(['codesign','--force','--sign',args.identity,str(helper)],check=True)
subprocess.run(['codesign','--force','--sign',args.identity,str(destination)],check=True)
subprocess.run(['codesign','--verify','--deep','--strict',str(destination)],check=True)
print(destination)
