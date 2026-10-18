import { computed } from 'vue';
import { useRecruitment } from './useRecruitment.ts';
import { bestMatchedOffers } from '../domain/aiMatching.ts';
import { emptyOfferFilters, filterOffers } from '../domain/offerFilters.ts';
export function useDashboard() {
    const { stats, applications, activities, offers } = useRecruitment();
    return {
        stats,
        pendingOffersCount: computed(() => filterOffers(offers.value, emptyOfferFilters('pending'), applications.value).length),
        bestOffers: computed(() => bestMatchedOffers(offers.value)),
        recentApplications: computed(() =>
            [...applications.value]
                .sort((a, b) =>
                    (b.appliedAt ?? '').localeCompare(a.appliedAt ?? ''),
                )
                .slice(0, 5),
        ),
        recentActivities: computed(() => activities.value.slice(0, 5)),
    };
}
