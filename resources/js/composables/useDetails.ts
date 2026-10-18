import { computed, ref, watch, type Ref } from 'vue';
import { useRecruitment } from './useRecruitment.ts';
import type { ApplicationStage } from '../types/recruitment.ts';
export function useOfferDetails(id: Ref<string>) {
    const { offers, applications, activities } = useRecruitment();
    return {
        activities: computed(() =>
            activities.value.filter(
                (item) =>
                    item.offerId === id.value ||
                    applications.value.some(
                        (application) =>
                            application.offerId === id.value &&
                            application.id === item.applicationId,
                    ),
            ),
        ),
        offer: computed(() =>
            offers.value.find((item) => item.id === id.value),
        ),
        applications: computed(() =>
            applications.value.filter((item) => item.offerId === id.value),
        ),
    };
}
export function useApplicationDetails(id: Ref<string>) {
    const store = useRecruitment();
    const application = computed(() =>
        store.applications.value.find((item) => item.id === id.value),
    );
    const offer = computed(() =>
        store.offers.value.find(
            (item) => item.id === application.value?.offerId,
        ),
    );
    const activities = computed(() =>
        store.activities.value.filter(
            (item) => item.applicationId === id.value,
        ),
    );
    const stage = ref<ApplicationStage>('new');
    const note = ref('');
    const saving = ref(false);
    watch(
        application,
        (item) => {
            if (item) stage.value = item.status;
        },
        { immediate: true },
    );
    async function saveStatus(): Promise<void> {
        saving.value = true;
        try {
            await store.changeStatus(id.value, stage.value);
        } finally {
            saving.value = false;
        }
    }
    async function saveNote(): Promise<void> {
        saving.value = true;
        try {
            if (await store.addNote(id.value, note.value)) note.value = '';
        } finally {
            saving.value = false;
        }
    }
    return {
        application,
        offer,
        activities,
        stage,
        note,
        saving,
        saveStatus,
        saveNote,
    };
}
