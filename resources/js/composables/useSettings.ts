import { reactive, watch } from 'vue';
import { Dialog, Notify } from 'quasar';
import { preferences, savePreferences } from './usePreferences.ts';
import { useRecruitment } from './useRecruitment.ts';
export function useSettings() {
    const draft = reactive({ ...preferences });
    watch(
        () => preferences.theme,
        (theme) => {
            draft.theme = theme;
        },
    );
    const { resetDemo } = useRecruitment();
    function save(): void {
        try {
            savePreferences(draft);
            Notify.create({
                type: 'positive',
                message: 'Ustawienia zapisane na tym urządzeniu.',
            });
        } catch (error) {
            Notify.create({
                type: 'negative',
                message:
                    error instanceof Error
                        ? error.message
                        : 'Nie można zapisać ustawień.',
            });
        }
    }
    function confirmReset(): void {
        Dialog.create({
            title: 'Przywrócić dane DEMO?',
            message:
                'Usunie to wyłącznie lokalne zmiany statusów i notatki DEMO. Ustawienia wyglądu pozostaną zachowane.',
            cancel: { label: 'Anuluj', flat: true },
            ok: { label: 'Przywróć DEMO', color: 'primary' },
            persistent: true,
        }).onOk(() => {
            void resetDemo();
        });
    }
    return { draft, save, confirmReset };
}
