import { computed, unref } from 'vue';
import { useRemember } from '@inertiajs/vue3';
import { useRecruitment } from './useRecruitment.ts';
import { matchesSearch } from '../domain/recruitment.ts';
export function useActivity() {
    const { activities } = useRecruitment();
    const filters = unref(
        useRemember(
            {
                search: '',
                actor: null as string | null,
                kind: null as string | null,
            },
            'activity.filters',
        ),
    );
    const actors = computed(() => [
        ...new Set(
            activities.value
                .map((item) => item.actor || item.source || '')
                .filter(Boolean),
        ),
    ]);
    const kinds = computed(() => {
        const values = [
            ...new Set(
                activities.value
                    .map((item) => item.eventType)
                    .filter((value): value is string => !!value),
            ),
        ];
        return values.length
            ? values.map((value) => ({ value, label: value }))
            : [
                  { label: 'Nowe aplikacje', value: 'applied' },
                  { label: 'Zmiany statusu', value: 'status' },
                  { label: 'Notatki', value: 'note' },
              ];
    });
    const rows = computed(() =>
        activities.value.filter(
            (item) =>
                (!filters.actor ||
                    (item.actor || item.source) === filters.actor) &&
                (!filters.kind ||
                    (item.eventType || item.kind) === filters.kind) &&
                matchesSearch(filters.search, [
                    item.actor,
                    item.message,
                    item.eventType ?? '',
                    item.source ?? '',
                    item.channel ?? '',
                    item.candidateName,
                    item.offerTitle,
                ]),
        ),
    );
    return {
        filters,
        actors,
        kinds,
        rows,
        clear: () =>
            Object.assign(filters, { search: '', actor: null, kind: null }),
    };
}
