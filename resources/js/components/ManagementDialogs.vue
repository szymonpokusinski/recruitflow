<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import {
    dialog,
    management,
    saveCommand,
    fieldErrors,
} from '../composables/useManagement.ts';
import { preferences } from '../composables/usePreferences.ts';
import { api } from '../services/http.ts';
import { mergeImportedFields } from '../domain/importOffer.ts';
import { applicationStages } from '../domain/recruitment.ts';
import type { FieldValue, OfferField } from '../types/management.ts';
const form = reactive<Record<string, FieldValue>>({});
const original = ref<Record<string, FieldValue>>({});
const errors = ref<Record<string, string[]>>({});
const error = ref('');
const saving = ref(false);
const full = ref(false);
const attributes = ref<{ label: string; value: string }[]>([]);
interface ImportedDraft {
    providerOfferId: string;
    canonicalUrl: string;
    status: 'success' | 'partial';
    form: Record<string, FieldValue>;
    details: {
        salaryVariants: {
            contract: string | null;
            min: number | null;
            max: number | null;
            currency: string | null;
            basis: string | null;
            period: string | null;
        }[];
        locations: { city?: string; region?: string; country?: string; address?: string }[];
        employmentTypes: string[];
        [key: string]: unknown;
    };
    warnings: string[];
}
interface ImportPreview {
    status: string;
    draft: ImportedDraft | null;
    duplicate: { id: string; title: string; company: string } | null;
}
const importing = ref(false);
const imported = ref<ImportedDraft | null>(null);
const importDuplicate = ref<ImportPreview['duplicate']>(null);
const importSkipped = ref<string[]>([]);
const today = (): string => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};
watch(
    () => dialog.operationId,
    () => {
        for (const key of Object.keys(form)) delete form[key];
        errors.value = {};
        error.value = '';
        imported.value = null;
        importDuplicate.value = null;
        importSkipped.value = [];
        full.value = !!dialog.offer && dialog.kind === 'offer';
        if (dialog.kind === 'offer') {
            for (const field of management.value?.offerFields ?? []) {
                const value = (
                    dialog.offer as unknown as Record<string, FieldValue> | null
                )?.[field.key];
                form[field.key] =
                    value ??
                    (field.type === 'tags'
                        ? []
                        : field.type === 'boolean' ||
                            field.type === 'number' ||
                            field.type === 'date' ||
                            field.type === 'datetime'
                          ? null
                          : '');
                if (
                    (field.type === 'date' || field.type === 'datetime') &&
                    typeof value === 'string' &&
                    value
                ) {
                    const d = new Date(value);
                    if (!Number.isNaN(d.getTime()))
                        form[field.key] =
                            field.type === 'date'
                                ? value.slice(0, 10)
                                : `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}T${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
                }
            }
            attributes.value = structuredClone(
                dialog.offer?.customAttributes ?? [],
            );
        }
        if (dialog.kind === 'cv')
            Object.assign(form, {
                sent: true,
                date: dialog.offer?.cvSentAt?.slice(0, 10) ?? today(),
                time: '',
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                channel: dialog.application?.source ?? 'Formularz',
                cvVersion: dialog.application?.cvVersion ?? '',
                coverLetterVersion:
                    dialog.application?.coverLetterVersion ?? '',
                reference: dialog.application?.reference ?? '',
                note: '',
            });
        if (dialog.kind === 'stage')
            Object.assign(form, {
                stage:
                    applicationStages.find(
                        (s) => s.label === dialog.offer?.applicationStage,
                    )?.value ?? 'not_applied',
                note: '',
            });
        if (dialog.kind === 'event')
            Object.assign(form, {
                eventType: 'Notatka',
                occurredAt: `${today()}T${String(new Date().getHours()).padStart(2, '0')}:${String(new Date().getMinutes()).padStart(2, '0')}`,
                message: '',
                channel: 'Inny',
                contactName: '',
                contactEmail: '',
                actionDeadlineAt: null,
                interviewAt: null,
            });
        if (dialog.kind === 'application')
            for (const key of applicationFields)
                form[key] =
                    (
                        dialog.application as unknown as Record<
                            string,
                            FieldValue
                        > | null
                    )?.[key] ?? '';
        original.value = JSON.parse(JSON.stringify(form)) as Record<
            string,
            FieldValue
        >;
    },
);
watch(
    () => form.offerUrl,
    (url) => {
        if (imported.value && url !== imported.value.canonicalUrl) {
            imported.value = null;
            importSkipped.value = [];
            error.value = 'Link zmienił się po imporcie. Kliknij „Importuj ofertę” ponownie, aby odświeżyć dane.';
        }
        if (importDuplicate.value) importDuplicate.value = null;
    },
);
const applicationFields = [
    'source',
    'cvVersion',
    'coverLetterVersion',
    'reference',
    'recruiterName',
    'recruiterEmail',
    'recruiterPhone',
    'feedbackNotes',
    'interviewLink',
    'salaryExpectation',
    'nextStep',
    'taskDeadlineAt',
    'confirmationAt',
    'firstReplyAt',
    'lastReplyAt',
    'rejectionAt',
    'rejectionReason',
];
const appLabels: Record<string, string> = {
    source: 'Kanał aplikowania',
    cvVersion: 'Wersja CV',
    coverLetterVersion: 'Wersja listu motywacyjnego',
    reference: 'Numer referencyjny',
    recruiterName: 'Kontakt HR',
    recruiterEmail: 'E-mail HR',
    recruiterPhone: 'Telefon HR',
    feedbackNotes: 'Notatki / feedback',
    interviewLink: 'Link do rozmowy',
    salaryExpectation: 'Oczekiwania finansowe',
    nextStep: 'Następny termin',
    taskDeadlineAt: 'Termin zadania',
    confirmationAt: 'Potwierdzenie przyjęcia',
    firstReplyAt: 'Pierwsza odpowiedź',
    lastReplyAt: 'Ostatnia odpowiedź',
    rejectionAt: 'Data odmowy',
    rejectionReason: 'Powód odmowy',
};
const sections = computed(() => [
    ...new Set((management.value?.offerFields ?? []).map((f) => f.section)),
]);
const fields = (section: string): OfferField[] =>
    (management.value?.offerFields ?? []).filter((f) => f.section === section);
const quickKeys = ['offerUrl', 'company', 'title', 'myNotes'];
const titles = computed(() => ({
    offer: dialog.offer ? 'Edytuj ofertę' : 'Dodaj ofertę',
    cv: dialog.offer?.cvSent ? 'Wysłanie CV / korekta' : 'Wysłałem CV',
    stage: 'Zmień etap rekrutacji',
    event: 'Dodaj zdarzenie HR',
    application: 'Edytuj dane aplikacji',
}));
function applyImportedFields(overwrite: boolean): void {
    if (!imported.value) return;
    const result = mergeImportedFields(form, imported.value.form, overwrite);
    Object.assign(form, result.values);
    importSkipped.value = result.skipped;
    full.value = true;
}
async function importOffer(): Promise<void> {
    if (importing.value || !form.offerUrl) return;
    importing.value = true;
    error.value = '';
    imported.value = null;
    importDuplicate.value = null;
    try {
        const result = await api<ImportPreview>('/api/offers/import/preview', 'POST', { url: form.offerUrl });
        importDuplicate.value = result.duplicate;
        if (result.duplicate) return;
        imported.value = result.draft;
        applyImportedFields(false);
    } catch (exception) {
        error.value = exception instanceof Error ? exception.message : 'Nie udało się pobrać ogłoszenia.';
    } finally {
        importing.value = false;
    }
}
function chooseSalary(index: number): void {
    const salary = imported.value?.details.salaryVariants[index];
    if (!salary) return;
    form.salaryMin = salary.min;
    form.salaryMax = salary.max;
    form.currency = salary.currency ?? '';
    form.salaryBasis = salary.basis ?? '';
    form.salaryPeriod = salary.period ?? '';
}
function tags(key: string, value: string): void {
    form[key] = value
        .split(/[,;\n]/)
        .map((v) => v.trim())
        .filter(Boolean);
}
function inputType(
    field: OfferField,
): 'number' | 'date' | 'datetime-local' | 'textarea' | 'url' | 'text' {
    return field.type === 'datetime'
        ? 'datetime-local'
        : field.type === 'tags'
          ? 'textarea'
          : field.type === 'select' || field.type === 'boolean'
            ? 'text'
            : field.type;
}
function closeDialog(value: boolean): void {
    if (!value && !saving.value) dialog.kind = null;
}
async function submit(): Promise<void> {
    saving.value = true;
    errors.value = {};
    error.value = '';
    try {
        const values: Record<string, unknown> = {
            operationId: dialog.operationId,
            actor: preferences.displayName,
        };
        let path = '';
        let method = 'POST';
        if (dialog.kind === 'offer') {
            for (const field of management.value?.offerFields ?? []) {
                if (!full.value && !quickKeys.includes(field.key)) continue;
                const v = form[field.key];
                if (
                    !dialog.offer ||
                    JSON.stringify(v) !==
                        JSON.stringify(original.value[field.key])
                )
                    values[field.key] =
                        field.type === 'datetime' && v
                            ? new Date(String(v)).toISOString()
                            : v;
            }
            if (
                full.value &&
                JSON.stringify(attributes.value) !==
                    JSON.stringify(dialog.offer?.customAttributes ?? [])
            )
                values.customAttributes = attributes.value;
            if (imported.value && !dialog.offer) values.importDetails = imported.value.details;
            if (dialog.offer) {
                values.expectedVersion = dialog.offer.version ?? 0;
                path = `/api/recruitment/offers/${dialog.offer.id}`;
                method = 'PUT';
            } else path = '/api/recruitment/offers';
        } else {
            Object.assign(values, form);
            if (dialog.kind === 'cv')
                path = `/api/recruitment/offers/${dialog.offer?.id}/cv`;
            if (dialog.kind === 'stage')
                path = `/api/recruitment/offers/${dialog.offer?.id}/stage`;
            if (dialog.kind === 'event') {
                path = `/api/recruitment/applications/${dialog.application?.id}/events`;
                for (const key of [
                    'occurredAt',
                    'actionDeadlineAt',
                    'interviewAt',
                ])
                    if (values[key])
                        values[key] = new Date(
                            String(values[key]),
                        ).toISOString();
            }
            if (dialog.kind === 'application') {
                path = `/api/recruitment/applications/${dialog.application?.id}`;
                method = 'PUT';
                for (const key of applicationFields) {
                    if (
                        form[key] === '' &&
                        [
                            'salaryExpectation',
                            'nextStep',
                            'taskDeadlineAt',
                            'confirmationAt',
                            'firstReplyAt',
                            'lastReplyAt',
                            'rejectionAt',
                        ].includes(key)
                    )
                        values[key] = null;
                    else if (
                        JSON.stringify(form[key]) ===
                        JSON.stringify(original.value[key])
                    )
                        delete values[key];
                }
            }
        }
        await saveCommand(path, values, method);
        dialog.kind = null;
    } catch (e) {
        errors.value = fieldErrors(e);
        error.value = e instanceof Error ? e.message : 'Nie udało się zapisać.';
    } finally {
        saving.value = false;
    }
}
</script>
<template>
    <q-dialog
        :model-value="dialog.kind !== null"
        persistent
        @update:model-value="closeDialog"
        ><q-card
            class="management-dialog"
            style="width: 820px; max-width: 96vw; max-height: 92vh"
        >
            <q-form @submit="submit"
                ><q-card-section class="row items-center"
                    ><h2 class="q-ma-none">
                        {{ dialog.kind ? titles[dialog.kind] : '' }}
                    </h2>
                    <q-space /><q-btn
                        flat
                        round
                        icon="close"
                        aria-label="Zamknij formularz"
                        :disable="saving"
                        @click="dialog.kind = null"
                /></q-card-section>
                <q-separator /><q-card-section
                    style="max-height: 65vh; overflow: auto"
                >
                    <q-banner v-if="error" class="error-banner q-mb-md">{{
                        error
                    }}</q-banner>
                    <template v-if="dialog.kind === 'offer'"
                        ><div v-if="!dialog.offer" class="q-mb-md">
                            <div class="row items-center q-gutter-sm">
                                <q-input :model-value="String(form.offerUrl ?? '')" @update:model-value="(v) => (form.offerUrl = v)" outlined dense type="url" label="Link do ogłoszenia Pracuj.pl" class="col" :error="!!errors.offerUrl" :error-message="errors.offerUrl?.[0]" />
                                <q-btn color="primary" outline icon="download" label="Importuj ofertę" :loading="importing" :disable="!form.offerUrl || importing" @click="importOffer" />
                            </div>
                            <p class="muted q-mt-sm">Pobierz podgląd z linku, popraw dane w formularzu i zapisz, gdy będą gotowe.</p>
                            <q-banner v-if="importDuplicate" class="warning-banner q-mt-sm">
                                Ta oferta już istnieje: {{ importDuplicate.company }} — {{ importDuplicate.title }}.
                                <a :href="`/offers/${importDuplicate.id}`">Otwórz istniejącą ofertę</a>
                            </q-banner>
                            <template v-if="imported">
                                <q-banner class="positive-banner q-mt-sm">Odczytano {{ imported.form.title }} — {{ imported.form.company }}. Dane są podglądem; zapis nastąpi dopiero po kliknięciu „Zapisz”.</q-banner>
                                <q-banner v-if="importSkipped.length" class="warning-banner q-mt-sm">Zachowano {{ importSkipped.length }} wcześniej wypełnionych pól. <q-btn flat dense label="Zastąp je danymi z ogłoszenia" @click="applyImportedFields(true)" /></q-banner>
                                <div v-if="imported.details.locations.length > 1" class="q-mt-sm"><strong>Lokalizacje:</strong> {{ imported.details.locations.map((x) => x.city || x.address || x.region).join('; ') }}</div>
                                <div v-if="imported.details.salaryVariants.length > 1" class="q-mt-sm"><strong>Stawki dla umów:</strong><q-btn v-for="(salary, index) in imported.details.salaryVariants" :key="index" flat dense no-caps :label="`${salary.contract || 'Umowa'}: ${salary.min ?? '—'}–${salary.max ?? '—'} ${salary.currency ?? ''} / ${salary.period ?? 'okres nieznany'}`" @click="chooseSalary(index)" /></div>
                                <p v-for="warning in imported.warnings" :key="warning" class="muted q-mt-sm q-mb-none">{{ warning }}</p>
                            </template>
                        </div><q-btn-toggle
                            v-if="!dialog.offer"
                            v-model="full"
                            :options="[
                                { label: 'Szybkie dodawanie', value: false },
                                { label: 'Pełny formularz', value: true },
                            ]"
                            unelevated
                            toggle-color="primary"
                            class="q-mb-lg"
                        />
                        <p v-if="!full" class="muted">
                            Wklej sam link lub podaj firmę i stanowisko. Oferta
                            bez nazwy będzie szkicem „Do uzupełnienia”.
                        </p>
                        <template v-for="section in sections" :key="section"
                            ><q-expansion-item
                                v-if="full"
                                :label="section"
                                :default-opened="section === 'Podstawowe'"
                                group="offer-form"
                                ><div class="form-grid q-pa-md">
                                    <template
                                        v-for="field in fields(section)"
                                        :key="field.key"
                                    >
                                        <q-toggle
                                            v-if="field.type === 'boolean'"
                                            :model-value="
                                                form[field.key] === true
                                            "
                                            @update:model-value="
                                                (v) => (form[field.key] = v)
                                            "
                                            :label="field.label"
                                        />
                                        <q-select
                                            v-else-if="field.type === 'select'"
                                            v-model="form[field.key]"
                                            :options="field.options"
                                            outlined
                                            dense
                                            clearable
                                            :label="field.label"
                                            :error="!!errors[field.key]"
                                            :error-message="
                                                errors[field.key]?.[0]
                                            "
                                        />
                                        <q-input
                                            v-else
                                            :model-value="
                                                field.type === 'tags'
                                                    ? (
                                                          form[
                                                              field.key
                                                          ] as string[]
                                                      ).join('; ')
                                                    : (form[field.key] as
                                                          | string
                                                          | number
                                                          | null)
                                            "
                                            @update:model-value="
                                                (v) =>
                                                    field.type === 'tags'
                                                        ? tags(
                                                              field.key,
                                                              String(v ?? ''),
                                                          )
                                                        : (form[field.key] = v)
                                            "
                                            outlined
                                            dense
                                            :type="inputType(field)"
                                            :label="field.label"
                                            :hint="
                                                field.type === 'tags'
                                                    ? 'Oddziel wartości średnikami'
                                                    : undefined
                                            "
                                            :error="!!errors[field.key]"
                                            :error-message="
                                                errors[field.key]?.[0]
                                            "
                                            :class="{
                                                'full-width-field': [
                                                    'textarea',
                                                    'tags',
                                                ].includes(field.type),
                                            }"
                                        />
                                    </template></div></q-expansion-item
                        ></template>
                        <div v-if="!full" class="form-grid">
                            <q-input
                                v-for="key in quickKeys"
                                :key="key"
                                :model-value="
                                    form[key] as string | number | null
                                "
                                @update:model-value="(v) => (form[key] = v)"
                                outlined
                                :type="key === 'myNotes' ? 'textarea' : 'text'"
                                :label="
                                    management?.offerFields.find(
                                        (f) => f.key === key,
                                    )?.label
                                "
                                :error="!!errors[key]"
                                :error-message="errors[key]?.[0]"
                            />
                        </div>
                        <q-expansion-item
                            v-if="full"
                            label="Własne atrybuty zawodowe"
                            ><div class="q-pa-md">
                                <div
                                    v-for="(attribute, index) in attributes"
                                    :key="index"
                                    class="row q-gutter-sm q-mb-sm"
                                >
                                    <q-input
                                        v-model="attribute.label"
                                        outlined
                                        dense
                                        label="Etykieta"
                                    /><q-input
                                        v-model="attribute.value"
                                        outlined
                                        dense
                                        label="Wartość"
                                    /><q-btn
                                        flat
                                        icon="delete_outline"
                                        @click="attributes.splice(index, 1)"
                                    />
                                </div>
                                <q-btn
                                    outline
                                    label="Dodaj atrybut"
                                    @click="
                                        attributes.push({
                                            label: '',
                                            value: '',
                                        })
                                    "
                                />
                                <p class="muted">
                                    Niestandardowe pola pozostają lokalnie, gdy
                                    arkusz nie ma odpowiedniej kolumny.
                                </p>
                            </div></q-expansion-item
                        >
                    </template>
                    <template v-else-if="dialog.kind === 'cv'"
                        ><q-toggle
                            v-model="form.sent"
                            label="CV zostało wysłane"
                        />
                        <div class="form-grid">
                            <q-input
                                :model-value="
                                    form.date as string | number | null
                                "
                                @update:model-value="(v) => (form.date = v)"
                                type="date"
                                outlined
                                label="Data wysłania CV"
                                :disable="!form.sent"
                                :error="!!errors.date"
                                :error-message="errors.date?.[0]"
                            /><q-input
                                :model-value="
                                    form.time as string | number | null
                                "
                                @update:model-value="(v) => (form.time = v)"
                                type="time"
                                outlined
                                label="Godzina (opcjonalnie)"
                                :disable="!form.sent"
                            /><q-select
                                v-model="form.channel"
                                outlined
                                label="Kanał aplikowania"
                                :options="[
                                    'Formularz',
                                    'Portal',
                                    'Gmail',
                                    'LinkedIn',
                                    'Telefon',
                                    'Inny',
                                ]"
                            /><q-input
                                v-for="key in [
                                    'cvVersion',
                                    'coverLetterVersion',
                                    'reference',
                                ]"
                                :key="key"
                                :model-value="
                                    form[key] as string | number | null
                                "
                                @update:model-value="(v) => (form[key] = v)"
                                outlined
                                :label="appLabels[key]"
                            /><q-input
                                :model-value="
                                    form.note as string | number | null
                                "
                                @update:model-value="(v) => (form.note = v)"
                                outlined
                                type="textarea"
                                label="Notatka"
                            />
                        </div>
                        <p class="muted">
                            Korekta zachowuje wcześniejszą historię. Bardziej
                            zaawansowany etap rekrutacji pozostanie bez zmian.
                        </p></template
                    >
                    <template v-else-if="dialog.kind === 'stage'"
                        ><q-select
                            v-model="form.stage"
                            outlined
                            :options="management?.stages"
                            emit-value
                            map-options
                            label="Etap rekrutacji" /><q-input
                            :model-value="form.note as string | number | null"
                            @update:model-value="(v) => (form.note = v)"
                            outlined
                            type="textarea"
                            label="Komentarz do zmiany"
                            class="q-mt-md"
                    /></template>
                    <template v-else-if="dialog.kind === 'event'"
                        ><div class="form-grid">
                            <q-select
                                v-model="form.eventType"
                                outlined
                                label="Typ zdarzenia"
                                :options="[
                                    'Notatka',
                                    'Rozmowa HR',
                                    'Rozmowa techniczna',
                                    'Wiadomość HR',
                                    'Zaproszenie',
                                    'Zadanie',
                                    'Test kompetencyjny',
                                    'Assessment Center',
                                    'Spotkanie z kierownikiem',
                                    'Dzień próbny',
                                    'Badania kwalifikacyjne',
                                    'Weryfikacja uprawnień',
                                    'Odmowa',
                                    'Oferta pracy',
                                    'Zmiana terminu',
                                    'Inne',
                                ]"
                            /><q-input
                                :model-value="
                                    form.occurredAt as string | number | null
                                "
                                @update:model-value="
                                    (v) => (form.occurredAt = v)
                                "
                                outlined
                                type="datetime-local"
                                label="Data zdarzenia"
                                :error="!!errors.occurredAt"
                                :error-message="errors.occurredAt?.[0]"
                            /><q-input
                                :model-value="
                                    form.message as string | number | null
                                "
                                @update:model-value="(v) => (form.message = v)"
                                outlined
                                type="textarea"
                                label="Treść wydarzenia"
                                class="full-width-field"
                                :error="!!errors.message"
                                :error-message="errors.message?.[0]"
                            /><q-input
                                :model-value="
                                    form.contactName as string | number | null
                                "
                                @update:model-value="
                                    (v) => (form.contactName = v)
                                "
                                outlined
                                label="Osoba kontaktowa"
                            /><q-input
                                :model-value="
                                    form.contactEmail as string | number | null
                                "
                                @update:model-value="
                                    (v) => (form.contactEmail = v)
                                "
                                outlined
                                label="E-mail kontaktu"
                            /><q-input
                                :model-value="
                                    form.actionDeadlineAt as
                                        | string
                                        | number
                                        | null
                                "
                                @update:model-value="
                                    (v) => (form.actionDeadlineAt = v)
                                "
                                outlined
                                type="datetime-local"
                                label="Termin działania"
                            /><q-input
                                :model-value="
                                    form.interviewAt as string | number | null
                                "
                                @update:model-value="
                                    (v) => (form.interviewAt = v)
                                "
                                outlined
                                type="datetime-local"
                                label="Termin spotkania"
                            /></div
                    ></template>
                    <template v-else-if="dialog.kind === 'application'"
                        ><div class="form-grid">
                            <q-input
                                v-for="key in applicationFields"
                                :key="key"
                                :model-value="
                                    form[key] as string | number | null
                                "
                                @update:model-value="(v) => (form[key] = v)"
                                outlined
                                :label="appLabels[key]"
                                :type="
                                    key === 'salaryExpectation'
                                        ? 'number'
                                        : key === 'feedbackNotes'
                                          ? 'textarea'
                                          : 'text'
                                "
                                :error="!!errors[key]"
                                :error-message="errors[key]?.[0]"
                                :hint="
                                    [
                                        'nextStep',
                                        'taskDeadlineAt',
                                        'confirmationAt',
                                        'firstReplyAt',
                                        'lastReplyAt',
                                        'rejectionAt',
                                    ].includes(key)
                                        ? 'Data: RRRR-MM-DD GG:MM'
                                        : undefined
                                "
                            /></div
                    ></template> </q-card-section
                ><q-separator /><q-card-actions align="right"
                    ><span class="muted q-mr-md"
                        >Zapis lokalny • Google Sheets opcjonalnie</span
                    ><q-btn
                        flat
                        label="Anuluj"
                        :disable="saving"
                        @click="dialog.kind = null" /><q-btn
                        type="submit"
                        color="primary"
                        unelevated
                        label="Zapisz"
                        :loading="saving" /></q-card-actions></q-form></q-card
    ></q-dialog>
</template>
