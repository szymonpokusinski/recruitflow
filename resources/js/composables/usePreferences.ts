import { reactive } from 'vue';
import type { ThemePreference } from '../types/recruitment.ts';
interface Preferences {
    displayName: string;
    compactRows: boolean;
    theme: ThemePreference;
}
const defaults = (): Preferences => ({
    displayName: 'Użytkownik lokalny',
    compactRows: true,
    theme: 'system',
});
function load(): Preferences {
    try {
        const data: unknown = JSON.parse(
            localStorage.getItem('recruitflow.preferences.v1') ?? 'null',
        );
        if (typeof data !== 'object' || data === null) return defaults();
        const value = data as Record<string, unknown>;
        return {
            displayName:
                typeof value.displayName === 'string' &&
                value.displayName.trim()
                    ? value.displayName
                    : defaults().displayName,
            compactRows:
                typeof value.compactRows === 'boolean'
                    ? value.compactRows
                    : true,
            theme: ['system', 'light', 'dark'].includes(String(value.theme))
                ? (value.theme as ThemePreference)
                : 'system',
        };
    } catch {
        return defaults();
    }
}
export const preferences = reactive(load());
export function savePreferences(next: Preferences): void {
    const displayName = next.displayName.trim();
    if (!displayName || displayName.length > 80)
        throw new Error('Nazwa użytkownika musi zawierać od 1 do 80 znaków.');
    try {
        localStorage.setItem(
            'recruitflow.preferences.v1',
            JSON.stringify({ ...next, displayName }),
        );
    } catch {
        throw new Error('Nie można zapisać ustawień lokalnie.');
    }
    Object.assign(preferences, next, { displayName });
}
