/** Accept navigation only; these URLs never become commands, file paths or remote URLs. */
export function validatedRecruitFlowLink(value) {
    if (typeof value !== 'string' || value.length > 256 || !value.startsWith('recruitflow://')) return null;
    try {
        const url = new URL(value);
        if (url.username || url.password || url.port || url.search || url.hash) return null;
        if (['recruitflow://offers/new', 'recruitflow://activity', 'recruitflow://refresh'].includes(value)) return value;
        if (/^recruitflow:\/\/offer\/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/.test(value)) return value;
    } catch { /* Malformed links never reach the application. */ }
    return null;
}

/** One pending navigation, delivered after NativePHP has loaded its first local page. */
export class DeferredRecruitFlowLink {
    pending = null;
    ready = false;
    delivering = false;
    constructor(deliver) { this.deliver = deliver; }
    receive(value) {
        const valid = validatedRecruitFlowLink(value);
        if (!valid) return false;
        this.pending = valid;
        void this.flush();
        return true;
    }
    async markReady() { this.ready = true; await this.flush(); }
    async flush() {
        if (!this.ready || this.delivering) return;
        this.delivering = true;
        try {
            while (this.pending) {
                const value = this.pending;
                this.pending = null;
                try { await this.deliver(value); }
                catch { this.pending ??= value; break; }
            }
        } finally { this.delivering = false; }
    }
}
