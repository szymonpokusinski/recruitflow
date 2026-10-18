import { computed, unref } from 'vue';
import { useRemember } from '@inertiajs/vue3';
import { useRecruitment } from './useRecruitment.ts';
import { matchesSearch } from '../domain/recruitment.ts';
import type { ApplicationStage } from '../types/recruitment.ts';
export function useApplications() {
    const { applications, offers } = useRecruitment();
    const filters = unref(
        useRemember(
            {
                search: '',
                status: null as ApplicationStage | null,
                offerId: null as string | null,
            },
            'applications.filters',
        ),
    );
    const offerOptions = computed(() =>
        offers.value.map((item) => ({ label: item.title, value: item.id })),
    );
    const rows = computed(() =>
        applications.value.filter(
            (item) =>
                (!filters.status || item.status === filters.status) &&
                (!filters.offerId || item.offerId === filters.offerId) &&
                matchesSearch(filters.search, [
                    item.candidateName,
                    item.id,
                    item.offerTitle,
                    item.company,
                    item.owner,
                    item.candidateEmail,
                ]),
        ),
    );
    return {
        filters,
        offerOptions,
        rows,
        total: computed(() => applications.value.length),
        clear: () =>
            Object.assign(filters, { search: '', status: null, offerId: null }),
    };
}
