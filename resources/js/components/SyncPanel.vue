<script setup lang="ts">
import { ref } from 'vue';
import {
    management,
    scheduleSync,
    loadManagement,
    saveCommand,
} from '../composables/useManagement.ts';
import { commandId } from '../services/recruitment.ts';
import { formatDateTime } from '../domain/recruitment.ts';
import { Notify } from 'quasar';
const saving = ref(false);
const choices = ref<Record<string, Record<string, 'local' | 'remote'>>>({});
const labels: Record<string, string> = {
    local: 'Tryb lokalny',
    connected: 'Połączono z Google Sheets',
    syncing: 'Synchronizacja trwa',
    synced: 'Zsynchronizowano',
    pending: 'Oczekujące zmiany',
    conflict: 'Konflikt',
    error: 'Błąd synchronizacji',
    permission: 'Brak uprawnień',
};
async function action(callback: () => Promise<unknown>): Promise<void> {
    saving.value = true;
    try {
        await callback();
        await loadManagement();
    } catch (e) {
        Notify.create({
            type: 'negative',
            message: e instanceof Error ? e.message : 'Błąd operacji.',
        });
    } finally {
        saving.value = false;
    }
}
const display = (value: unknown): string =>
    typeof value === 'string' ? value : (JSON.stringify(value) ?? '—');
</script>
<template>
    <section class="panel detail-content">
        <h2>Synchronizacja i zapis lokalny</h2>
        <template v-if="management"
            ><q-badge
                :color="management.sync.conflicts ? 'orange' : 'primary'"
                >{{
                    labels[management.sync.status] ?? management.sync.status
                }}</q-badge
            >
            <dl class="detail-list q-mt-md">
                <dt>Oczekujące wysłanie</dt>
                <dd>{{ management.sync.pending }}</dd>
                <dt>Tylko lokalnie</dt>
                <dd>{{ management.sync.local }} operacji</dd>
                <dt>Konflikty / błędy</dt>
                <dd>
                    {{ management.sync.conflicts }} /
                    {{ management.sync.blocked }}
                </dd>
                <dt>Ostatni potwierdzony zapis w Google</dt>
                <dd>{{ formatDateTime(management.sync.last_synced_at) }}</dd>
                <dt>Ostatni odczyt Google</dt>
                <dd>
                    {{ formatDateTime(management.sync.read_last_synced_at) }}
                </dd>
            </dl>
            <p class="muted">
                SQLite zapisuje zmiany od razu. Wysyłanie wymaga aktywnego
                źródła głównego, uprawnień konta do edycji i wyłączonego trybu
                tylko do odczytu. Profile i niestandardowe atrybuty mogą
                pozostawać lokalnie.
            </p>
            <q-banner v-if="management.sync.last_error" class="error-banner">{{
                management.sync.last_error
            }}</q-banner
            ><q-btn
                class="q-mt-md"
                color="primary"
                outline
                label="Synchronizuj teraz"
                :loading="saving"
                @click="action(() => scheduleSync(true))"
            />
            <q-expansion-item
                v-for="conflict in management.conflicts"
                :key="conflict.id"
                :label="`Konflikt: ${conflict.entity_type} · ${conflict.entity_id}`"
                class="q-mt-md"
                ><div class="q-pa-md">
                    <div
                        v-for="(versions, field) in conflict.fields"
                        :key="field"
                        class="q-mb-lg"
                    >
                        <strong>{{
                            management.offerFields.find((f) => f.key === field)
                                ?.label ?? field
                        }}</strong>
                        <div class="form-grid q-mt-sm">
                            <div>
                                <small class="muted">Lokalnie</small>
                                <p class="text-preserve">
                                    {{ display(versions.local) }}
                                </p>
                            </div>
                            <div>
                                <small class="muted">Google Sheets</small>
                                <p class="text-preserve">
                                    {{ display(versions.remote) }}
                                </p>
                            </div>
                        </div>
                        <q-btn-toggle
                            :model-value="choices[conflict.id]?.[field]"
                            @update:model-value="
                                (v) => {
                                    choices[conflict.id] ??= {};
                                    choices[conflict.id]![field] = v;
                                }
                            "
                            :options="[
                                { label: 'Zachowaj lokalne', value: 'local' },
                                { label: 'Zachowaj Google', value: 'remote' },
                            ]"
                            toggle-color="primary"
                        />
                    </div>
                    <q-btn
                        color="primary"
                        label="Rozstrzygnij wybrane pola"
                        :disable="
                            Object.keys(choices[conflict.id] ?? {}).length !==
                            Object.keys(conflict.fields).length
                        "
                        :loading="saving"
                        @click="
                            action(() =>
                                saveCommand(
                                    `/api/recruitment/conflicts/${conflict.id}/resolve`,
                                    {
                                        operationId: commandId(),
                                        fields: choices[conflict.id],
                                    },
                                ),
                            )
                        "
                    /></div
            ></q-expansion-item>
            <q-expansion-item
                v-if="management.duplicates.length"
                label="Możliwe duplikaty po pierwszym podłączeniu"
                ><div
                    v-for="duplicate in management.duplicates"
                    :key="duplicate.local.id + duplicate.remote.id"
                    class="q-pa-md"
                >
                    <p>
                        {{ duplicate.local.company }} ·
                        {{ duplicate.local.title }} ↔
                        {{ duplicate.remote.company }} ·
                        {{ duplicate.remote.title }}
                    </p>
                    <p class="muted">
                        Połączenie zachowa ręczne decyzje i historię. Sprawdź,
                        czy to to samo ogłoszenie.
                    </p>
                    <q-btn
                        outline
                        label="Połącz te rekordy"
                        :loading="saving"
                        @click="
                            action(() =>
                                saveCommand('/api/recruitment/merge', {
                                    operationId: commandId(),
                                    localId: duplicate.local.id,
                                    remoteId: duplicate.remote.id,
                                }),
                            )
                        "
                    /></div
            ></q-expansion-item>
            <q-expansion-item label="Kolejka operacji"
                ><q-list
                    ><q-item
                        v-for="operation in management.operations"
                        :key="operation.id"
                        ><q-item-section
                            ><q-item-label
                                >{{ operation.entity_type }} ·
                                {{ operation.status }} · prób:
                                {{ operation.attempts }}</q-item-label
                            ><q-item-label caption>{{
                                operation.last_error
                            }}</q-item-label
                            ><q-item-label
                                v-if="operation.unsupported_fields?.length"
                                caption
                                >Tylko lokalne pola:
                                {{
                                    operation.unsupported_fields.join(', ')
                                }}</q-item-label
                            ></q-item-section
                        ><q-item-section side
                            ><q-btn
                                v-if="
                                    ['blocked', 'retry'].includes(
                                        operation.status,
                                    )
                                "
                                flat
                                label="Ponów"
                                @click="
                                    action(() =>
                                        saveCommand(
                                            `/api/recruitment/operations/${operation.id}/retry`,
                                            {},
                                        ),
                                    )
                                " /></q-item-section></q-item></q-list
            ></q-expansion-item>
        </template>
    </section>
</template>
