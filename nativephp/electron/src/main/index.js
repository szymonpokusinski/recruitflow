import NativePHP from '#plugin';
import { app, BrowserWindow } from 'electron';
import path from 'path';
import { createSplash } from './splash.js';
import { DeferredRecruitFlowLink } from '../../../../native/desktop/deep-links.mjs';
import { startWidgetBridge } from '../../../../native/desktop/widget-bridge.mjs';
// Inherit User's PATH in Process & ChildProcess
import fixPath from 'fix-path';
fixPath();

const buildPath = path.resolve(import.meta.dirname, import.meta.env.MAIN_VITE_NATIVEPHP_BUILD_PATH);
const defaultIcon = path.join(buildPath, 'icon.png');
const certificate = path.join(buildPath, 'cacert.pem');

const executable = process.platform === 'win32' ? 'php.exe' : 'php';
const phpBinary = path.join(buildPath, 'php', executable);
const appPath = path.join(buildPath, 'app');

let splashWindow;
app.setName('RecruitFlow');
if (process.argv.includes('--recruitflow-read-only-preview')) {
    process.env.RECRUITFLOW_READ_ONLY_PREVIEW = 'true';
}
const nativeHandlers = new Set();
function collectNativeHandlers() {
    for (const listener of app.listeners('open-url')) {
        if (listener !== receive) { nativeHandlers.add(listener); app.removeListener('open-url', listener); }
    }
}
const links = new DeferredRecruitFlowLink(async value => {
    collectNativeHandlers();
    if (!nativeHandlers.size) throw new Error('NativePHP navigation is not ready');
    for (const handler of nativeHandlers) handler({ preventDefault() {} }, value);
    console.info('RecruitFlow widget navigation delivered');
});
function receive(event, value) { event.preventDefault(); links.receive(value); }
// macOS delivers the launch URL before the PHP server and its event listener are ready.
app.on('open-url', receive);
app.on('newListener', (name, listener) => {
    if (name === 'open-url' && listener !== receive) {
        nativeHandlers.add(listener);
        queueMicrotask(() => app.removeListener('open-url', listener));
    }
});
const primaryInstance = app.requestSingleInstanceLock();
if (!primaryInstance) app.quit();
app.on('second-instance', (_event, argv) => {
    for (const value of argv) links.receive(value);
    const window = BrowserWindow.getAllWindows().find(item => item.getTitle() === 'RecruitFlow');
    if (window) { window.restore(); window.show(); window.focus(); }
});
app.on('activate', () => { if (links.ready) void links.markReady(); });

app.whenReady().then(() => {
    if (!primaryInstance) return;
    if (app.isPackaged) {
        app.setAsDefaultProtocolClient('recruitflow');
        startWidgetBridge(app);
    }
    try {
        splashWindow = createSplash(appPath, import.meta.dirname);
    } catch (error) {
        console.error('Error creating splash screen:', error);
    }

    NativePHP.bootstrap(app, defaultIcon, phpBinary, certificate, appPath);
    collectNativeHandlers();
    for (const value of process.argv) links.receive(value);
});

app.on('browser-window-created', (event, window) => {
    window.webContents.on('did-finish-load', () => {
        try {
            const page = new URL(window.webContents.getURL());
            if (page.protocol === 'http:' && ['127.0.0.1', 'localhost'].includes(page.hostname) && page.pathname !== '/menu-bar') {
                collectNativeHandlers();
                void links.markReady();
            }
        } catch { /* Ignore the splash and other non-application windows. */ }
    });
    if (splashWindow && window !== splashWindow) {
        window.webContents.on('did-navigate', (evt, url) => {
            if (url.startsWith('http://127.0.0.1') || url.startsWith('http://localhost')) {
                if (splashWindow) {
                    splashWindow.close();
                    splashWindow = null;
                }
            }
        });
    }
});
