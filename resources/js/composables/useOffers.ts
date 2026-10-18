import { computed, unref } from 'vue';
import { useRemember } from '@inertiajs/vue3';
import { useRecruitment } from './useRecruitment.ts';
import { emptyOfferFilters, filterOffers } from '../domain/offerFilters.ts';
import type { Offer } from '../types/recruitment.ts';
import { offerRows } from '../domain/offerInbox.ts';
export function useOffers() {
    const { offers, applications } = useRecruitment();
    const filters = unref(useRemember(emptyOfferFilters('pending'), 'offers.inbox.filters.v2'));
    const options = (key: keyof Offer) =>
        computed(
            () =>
                [
                    ...new Set(
                        offers.value
                            .map((o) => o[key])
                            .filter((v) => typeof v === 'string' && v),
                    ),
                ] as string[],
        );
    const rows = computed(() => offerRows(filterOffers(offers.value, filters, applications.value), applications.value));
    const counts = computed(() => {
        const visible = offerRows(offers.value.filter(offer => !offer.hidden && !offer.archived), applications.value);
        return { pending: visible.filter(offer => offer.applicationState !== 'applied').length,
            applied: visible.filter(offer => offer.applicationState === 'applied').length, all: visible.length };
    });
    return {
        filters,
        companies: options('company'),
        industries: options('industry'),
        categories: options('jobCategory'),
        seniorities: options('seniority'),
        workModes: options('workMode'),
        employments: options('employment'),
        rows,
        counts,
        total: computed(() => offers.value.length),
        clear: () => Object.assign(filters, emptyOfferFilters('pending')),
    };
}
