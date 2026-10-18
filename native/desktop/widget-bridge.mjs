import { readFileSync, writeFileSync, mkdirSync, renameSync, statSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { execFileSync } from 'node:child_process';

/** The installed desktop app publishes a bounded snapshot to its signed App Group. */
export function startWidgetBridge(app) {
    let timer;
    try {
        const plist = join(process.resourcesPath, '..', 'Info.plist');
        const team = execFileSync('/usr/bin/plutil', ['-extract', 'RecruitFlowSigningTeam', 'raw', plist], { encoding: 'utf8' }).trim();
        if (!/^[A-Z0-9]{10}$/.test(team)) return;
        const directory = join(app.getPath('home'), 'Library', 'Group Containers', `${team}.pl.szymonpokusinski.recruitflow.widgets`);
        const source = join(app.getPath('userData'), 'storage', 'app', 'private', 'widgets', 'native', 'dashboard-v1.json');
        let previous = null;
        const publish = () => {
            try {
                const size = statSync(source).size;
                if (size > 2_000_000) throw new Error('Oversized snapshot');
                const data = readFileSync(source, 'utf8');
                if (data === previous) return;
                const snapshot = JSON.parse(data);
                if (snapshot.schema_version !== 1 || !Array.isArray(snapshot.offers)) throw new Error('Invalid snapshot');
                mkdirSync(directory, { recursive: true, mode: 0o700 });
                const temporary = join(directory, `.desktop-snapshot-${process.pid}`);
                writeFileSync(temporary, data, { mode: 0o600 });
                renameSync(temporary, join(directory, 'dashboard-v1.json'));
                writeFileSync(join(directory, '.desktop-publisher'), 'RecruitFlow Desktop 1\n', { mode: 0o600 });
                previous = data;
                console.info('RecruitFlow widget snapshot published:', snapshot.offers.length);
                // Wake the installed companion, which owns WidgetCenter and the extension.
                const companion = join('/Applications', 'RecruitFlow Widgets.app');
                if (existsSync(companion)) {
                    execFileSync('/usr/bin/open', ['-g', '-a', companion, 'recruitflow-widgets://refresh']);
                }
            } catch (error) {
                if (error.code !== 'ENOENT') console.warn('RecruitFlow widget publication unavailable:', error.code ?? error.name);
            }
        };
        publish();
        timer = setInterval(publish, 2000);
        timer.unref();
    } catch (error) {
        console.warn('RecruitFlow widget bridge unavailable:', error.code ?? error.name);
    }
    app.once('before-quit', () => clearInterval(timer));
}
