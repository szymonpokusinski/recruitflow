#!/usr/bin/env python3
"""Build a self-contained local macOS app using NativePHP's supported builder."""
import argparse
import json
import os
import pathlib
import plistlib
import shutil
import subprocess

parser = argparse.ArgumentParser()
parser.add_argument('--team', required=True)
parser.add_argument('--identity', required=True)
parser.add_argument('--repackage', action='store_true', help='Repackage the already prepared Laravel build with updated Electron code')
parser.add_argument('--refresh-frontend', action='store_true', help='Copy a newly built Vite frontend into an existing prepared Laravel build (frontend-only changes)')
args = parser.parse_args()
if len(args.team) != 10 or not args.team.isalnum() or not args.team.isupper():
    raise SystemExit('Invalid signing Team ID')
root = pathlib.Path(__file__).resolve().parents[2]
entitlements = plistlib.loads((root / 'nativephp/electron/build/entitlements.mac.plist').read_bytes())
entitlements['com.apple.security.application-groups'] = [f'{args.team}.pl.szymonpokusinski.recruitflow.widgets']
(root / 'nativephp/electron/.entitlements.local.plist').write_bytes(plistlib.dumps(entitlements))
env = dict(os.environ, RECRUITFLOW_SIGNING_TEAM=args.team, RECRUITFLOW_SIGNING_IDENTITY=args.identity)
if args.refresh_frontend:
    if not args.repackage:
        raise SystemExit('--refresh-frontend requires --repackage; full native:build already copies the current frontend')
    prepared = root / 'vendor/nativephp/desktop/resources/build/app'
    if not (prepared / 'artisan').is_file() or not (root / 'public/build/manifest.json').is_file():
        raise SystemExit('Prepared Laravel build or Vite manifest is missing')
    # Touch only generated build inputs. Never access a user SQLite or OAuth store.
    if (prepared / 'public/build').exists():
        shutil.rmtree(prepared / 'public/build')
    for relative in ['public/build', 'resources/js', 'resources/css']:
        shutil.copytree(root / relative, prepared / relative, dirs_exist_ok=True)
    shutil.copy2(root / 'config/nativephp.php', prepared / 'config/nativephp.php')
if args.repackage:
    # Resolve the same paths/config as native:build without logging credentials.
    code = r'''require 'vendor/autoload.php'; $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $command = $app->make(Native\Desktop\Drivers\Electron\Commands\BuildCommand::class);
    $method = new ReflectionMethod($command, 'getEnvironmentVariables');
    echo json_encode($method->invoke($command), JSON_THROW_ON_ERROR);'''
    values = json.loads(subprocess.check_output(['php', '-r', code], cwd=root))
    env.update({key: str(value) for key, value in values.items() if value is not None})
    subprocess.run(['npm', 'run', 'build:mac-arm64'], cwd=root / 'nativephp/electron', env=env, check=True)
else:
    subprocess.run(['php', 'artisan', 'native:build', 'mac', 'arm64', '--no-interaction'], cwd=root, env=env, check=True)
app = root / 'nativephp/electron/dist/mac-arm64/RecruitFlow.app'
if not (app / 'Contents/Resources/build/php/php').is_file():
    raise SystemExit('Build is incomplete: PHP binary missing')
subprocess.run(['/usr/bin/codesign', '--verify', '--deep', '--strict', str(app)], check=True)
print('Self-contained application:', app)
