import { app, BrowserWindow } from 'electron';
import { readFileSync, existsSync } from 'node:fs';
import { pathToFileURL } from 'node:url';
import { join } from 'node:path';
import { DeferredRecruitFlowLink } from './deep-links.mjs';

// This developer launcher uses the installed NativePHP runtime and the existing project.
// Its generated settings contain paths only, never OAuth or NativePHP secrets.
const settings = JSON.parse(readFileSync(new URL('./settings.json', import.meta.url), 'utf8'));
if (!existsSync(join(settings.project, 'artisan')) || !existsSync(settings.php)) {
    throw new Error('RecruitFlow project/runtime moved. Rebuild the development launcher.');
}
process.env.NODE_ENV = 'development';
process.env.APP_PATH = settings.project;
if (process.argv.includes('--recruitflow-read-only-preview')) {
    process.env.RECRUITFLOW_READ_ONLY_PREVIEW = 'true';
}
app.setName('RecruitFlow');
const nativeHandlers = new Set();
function collectNativeHandlers() {
    for (const listener of app.listeners('open-url')) {
        if (listener !== receive) { nativeHandlers.add(listener); app.removeListener('open-url', listener); }
    }
}
const links = new DeferredRecruitFlowLink(async value => {
    collectNativeHandlers();
    if (nativeHandlers.size === 0) throw new Error('NativePHP URL handler is not ready');
    // Invoke NativePHP's own authenticated relay, retaining its runtime secret and state.
    for (const handler of nativeHandlers) handler({ preventDefault() {} }, value);
    console.info('RecruitFlow navigation delivered to NativePHP');
    const window = BrowserWindow.getAllWindows().find(item => item.getTitle() === 'RecruitFlow');
    if (window) { if (window.isMinimized()) window.restore(); window.show(); window.focus(); }
});
const receive = (event, value) => {
    event.preventDefault();
    if (links.receive(value)) console.info('RecruitFlow navigation received', links.ready ? 'warm' : 'cold');
};
app.on('open-url', receive); // Registered before whenReady: cold-start URLs must not be lost.
app.on('newListener', (eventName, listener) => {
    if (eventName === 'open-url' && listener !== receive) {
        nativeHandlers.add(listener);
        queueMicrotask(() => app.removeListener('open-url', listener));
    }
});
if (!app.requestSingleInstanceLock()) {
    app.quit();
} else {
    app.on('second-instance', (_event, argv) => {
        for (const arg of argv) links.receive(arg);
    });
    app.on('browser-window-created', (_event, window) => {
        window.webContents.on('did-finish-load', () => {
            try {
                const page = new URL(window.webContents.getURL());
                if (page.protocol === 'http:' && ['127.0.0.1', 'localhost'].includes(page.hostname) && page.pathname !== '/menu-bar') {
                    collectNativeHandlers();
                    void links.markReady();
                }
            } catch { /* Ignore non-application windows. */ }
        });
    });
    app.whenReady().then(async () => {
        // Load the existing compiled main so NativePHP preload paths and Electron dependencies stay intact.
        await import(pathToFileURL(join(settings.electron, 'out/main/index.js')).href);
        collectNativeHandlers();
        for (const arg of process.argv) links.receive(arg);
    }).catch(error => {
        console.error('RecruitFlow launcher startup failed:', error.name);
        app.quit();
    });
}
