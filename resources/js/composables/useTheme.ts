import { computed, watch } from 'vue';
import { Dark, Notify } from 'quasar';
import { preferences, savePreferences } from './usePreferences.ts';
import type { ThemePreference } from '../types/recruitment.ts';
let initialized = false;
export function initializeTheme(): void {
    if (initialized) return;
    initialized = true;
    watch(
        () => preferences.theme,
        (theme) => Dark.set(theme === 'system' ? 'auto' : theme === 'dark'),
        { immediate: true },
    );
}
export function useTheme() {
    const dark = computed(() => Dark.isActive);
    function setTheme(theme: ThemePreference): void {
        try {
            savePreferences({ ...preferences, theme });
        } catch (error) {
            Notify.create({
                type: 'negative',
                message:
                    error instanceof Error
                        ? error.message
                        : 'Nie można zapisać motywu.',
            });
        }
    }
    return {
        dark,
        setTheme,
        toggleTheme: () => setTheme(dark.value ? 'light' : 'dark'),
    };
}
