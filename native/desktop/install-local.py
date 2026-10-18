#!/usr/bin/env python3
"""Install only RecruitFlow bundles and seed an empty app database using SQLite backup.

Existing non-empty installed databases are always preserved. OAuth remains in Keychain.
Run after build-installed.py and the widget Xcode build.
"""
from datetime import datetime
from pathlib import Path
import argparse
import os
import plistlib
import signal
import sqlite3
import subprocess
import time

ROOT = Path(__file__).resolve().parents[2]
APPLICATIONS = Path('/Applications')
LSREGISTER = '/System/Library/Frameworks/CoreServices.framework/Frameworks/LaunchServices.framework/Support/lsregister'


def widget_process_ids(listing: str, bundles: list[Path]):
    executables = {str(bundle / path) for bundle in bundles for path in [
        'Contents/MacOS/RecruitFlow Widgets',
        'Contents/PlugIns/RecruitFlowWidgetExtension.appex/Contents/MacOS/RecruitFlowWidgetExtension',
    ]}
    result = []
    for line in listing.splitlines():
        parts = line.strip().split(maxsplit=1)
        if len(parts) == 2 and parts[0].isdigit() and int(parts[0]) > 1 and parts[1] in executables:
            result.append(int(parts[0]))
    return result


def stop_stale_widget_processes(bundles: list[Path]):
    # WidgetKit can keep an old dylib in memory while LaunchServices already
    # knows a newer bundle. It then rejects the timeline with a version mismatch.
    def running():
        listing = subprocess.check_output(['ps', '-axo', 'pid=,comm='], text=True)
        return widget_process_ids(listing, bundles)
    pids = running()
    for pid in pids:
        try:
            os.kill(pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
    deadline = time.monotonic() + 5
    while pids and time.monotonic() < deadline:
        remaining = set(running()).intersection(pids)
        if not remaining:
            break
        time.sleep(0.1)
    else:
        if pids and set(running()).intersection(pids):
            raise RuntimeError('Old widget processes did not exit; installation stopped')
    print(f'Stopped {len(pids)} RecruitFlow widget processes before bundle replacement')


def migrate_empty_database(source: Path, destination: Path, backups: Path):
    if not source.is_file():
        raise RuntimeError('Native development SQLite does not exist')
    if destination.exists():
        with sqlite3.connect(destination.as_uri() + '?mode=ro', uri=True) as existing:
            tables = existing.execute("SELECT name FROM sqlite_master WHERE type='table'").fetchall()
            if tables:
                # Preserve even an initialized empty database: it may contain settings/accounts.
                return 'Existing installed database preserved'
    backups.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now().strftime('%Y%m%d-%H%M%S')
    snapshot = backups / f'native-before-install-{stamp}.sqlite'
    with sqlite3.connect(source.as_uri() + '?mode=ro', uri=True) as original:
        with sqlite3.connect(snapshot) as backup:
            original.backup(backup)
            if backup.execute('PRAGMA integrity_check').fetchone()[0] != 'ok':
                raise RuntimeError('SQLite integrity verification failed; installation stopped')
            counts = {table: backup.execute(f'SELECT COUNT(*) FROM {table}').fetchone()[0]
                      for table in ['offers', 'applications', 'recruitment_events', 'integration_accounts', 'data_sources']}
    os.chmod(snapshot, 0o600)
    destination.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    temporary = destination.with_name('.recruitflow-install.sqlite')
    if temporary.exists():
        raise RuntimeError('Temporary migration already exists; refusing to overwrite it')
    with sqlite3.connect(snapshot) as backup, sqlite3.connect(temporary) as installed:
        backup.backup(installed)
    os.chmod(temporary, 0o600)
    if destination.exists():
        os.rename(destination, backups / f'empty-installed-before-install-{stamp}.sqlite')
    os.replace(temporary, destination)
    return f'SQLite copied without changing original: {counts}; backup: {snapshot}'


def install(source: Path, identifier: str):
    info = plistlib.loads((source / 'Contents/Info.plist').read_bytes())
    if info.get('CFBundleIdentifier') != identifier:
        raise RuntimeError('Unexpected bundle identifier')
    subprocess.run(['codesign', '--verify', '--deep', '--strict', str(source)], check=True)
    destination = APPLICATIONS / source.name
    if destination.exists():
        old = plistlib.loads((destination / 'Contents/Info.plist').read_bytes())
        if old.get('CFBundleIdentifier') != identifier:
            raise RuntimeError('Refusing to replace an unrelated application')
        archive = ROOT / 'native/desktop/.build/install-backups' / datetime.now().strftime('%Y%m%d-%H%M%S')
        archive.mkdir(parents=True, exist_ok=True)
        archived = archive / (source.name + '.previous')
        os.rename(destination, archived)
        # Keep a recoverable bundle without exposing an extra *.app to Spotlight.
        subprocess.run([LSREGISTER, '-u', str(archived)], capture_output=True, check=False)
    subprocess.run(['ditto', str(source), str(destination)], check=True)
    subprocess.run(['codesign', '--verify', '--deep', '--strict', str(destination)], check=True)
    subprocess.run([LSREGISTER, '-f', '-R', str(destination)], check=True)
    print('Installed:', destination)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    selection = parser.add_mutually_exclusive_group()
    selection.add_argument('--widgets-only', action='store_true', help='Update only the widget host/extension; keep the main application running')
    selection.add_argument('--main-only', action='store_true', help='Update only RecruitFlow; keep widget processes and bundles unchanged')
    args = parser.parse_args()
    main = ROOT / 'nativephp/electron/dist/mac-arm64/RecruitFlow.app'
    widgets = ROOT / 'native/widgets/DerivedData/Build/Products/Debug/RecruitFlow Widgets.app'
    # Verify both before making any changes.
    for bundle in [main, widgets]:
        subprocess.run(['codesign', '--verify', '--deep', '--strict', str(bundle)], check=True)
    listing = subprocess.check_output(['ps', '-axo', 'comm='], text=True)
    if not args.widgets_only and '/Applications/RecruitFlow.app/Contents/MacOS/RecruitFlow' in [line.strip() for line in listing.splitlines()]:
        raise RuntimeError('Close RecruitFlow with Cmd+Q before updating its installed bundle')
    if not args.main_only:
        stop_stale_widget_processes([
            APPLICATIONS / widgets.name, widgets,
            ROOT / 'native/widgets/.build/local/RecruitFlow Widgets.app',
        ])
    if not args.widgets_only:
        print(migrate_empty_database(ROOT / 'database/nativephp.sqlite',
                                    Path.home() / 'Library/Application Support/RecruitFlow/database/database.sqlite',
                                    ROOT / 'database/backups'))
        install(main, 'pl.szymonpokusinski.recruitflow.desktop')
    if not args.main_only:
        install(widgets, 'pl.szymonpokusinski.recruitflow.widgets')
    for obsolete in [ROOT / 'native/desktop/.build/RecruitFlow.app', main, widgets,
                     ROOT / 'native/widgets/.build/local/RecruitFlow Widgets.app',
                     ROOT / 'native/widgets/.build/poc/RecruitFlow Widgets POC.app']:
        if obsolete.exists():
            # An unsigned test bundle may never have been registered (-10814).
            subprocess.run([LSREGISTER, '-u', str(obsolete)], capture_output=True, check=False)
    for installed in [APPLICATIONS / main.name, APPLICATIONS / widgets.name]:
        subprocess.run([LSREGISTER, '-f', '-R', str(installed)], check=True)
    print('Open /Applications/RecruitFlow.app from Finder or Spotlight. No terminal is required.')
