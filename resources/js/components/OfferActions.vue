<script setup lang="ts">
import type { Offer } from '../types/recruitment.ts';
import { openDialog, updateDecision } from '../composables/useManagement.ts';
defineProps<{ offer: Offer; compact?: boolean }>();
const interests = [
    'Interesuje',
    'Bardzo interesuje',
    'Na później',
    'Nie interesuje',
    'Do sprawdzenia',
];
</script>
<template>
    <div class="row no-wrap items-center q-gutter-xs" @click.stop>
        <q-btn
            :dense="compact"
            :flat="compact"
            :outline="!compact"
            color="primary"
            label="Wysłałem CV"
            @click="openDialog('cv', offer)"
        />
        <q-btn flat round dense icon="more_horiz" aria-label="Akcje oferty"
            ><q-menu
                ><q-list style="min-width: 220px">
                    <q-item
                        clickable
                        v-close-popup
                        @click="openDialog('offer', offer)"
                        ><q-item-section>Edytuj ofertę</q-item-section></q-item
                    >
                    <q-item
                        v-if="offer.offerUrl"
                        clickable
                        :href="offer.offerUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        ><q-item-section
                            >Otwórz ogłoszenie</q-item-section
                        ></q-item
                    >
                    <q-item
                        clickable
                        v-close-popup
                        @click="openDialog('stage', offer)"
                        ><q-item-section
                            >Zmień etap rekrutacji</q-item-section
                        ></q-item
                    >
                    <q-item
                        clickable
                        v-close-popup
                        @click="
                            updateDecision(offer, { favorite: !offer.favorite })
                        "
                        ><q-item-section>{{
                            offer.favorite
                                ? 'Usuń z ulubionych'
                                : 'Dodaj do ulubionych'
                        }}</q-item-section></q-item
                    >
                    <q-item
                        v-for="interest in interests"
                        :key="interest"
                        clickable
                        v-close-popup
                        @click="updateDecision(offer, { myInterest: interest })"
                        ><q-item-section>{{ interest }}</q-item-section></q-item
                    >
                    <q-item
                        clickable
                        v-close-popup
                        @click="
                            updateDecision(offer, { hidden: !offer.hidden })
                        "
                        ><q-item-section>{{
                            offer.hidden ? 'Pokaż' : 'Ukryj'
                        }}</q-item-section></q-item
                    >
                    <q-item
                        clickable
                        v-close-popup
                        @click="
                            updateDecision(offer, { archived: !offer.archived })
                        "
                        ><q-item-section>{{
                            offer.archived ? 'Przywróć' : 'Archiwizuj'
                        }}</q-item-section></q-item
                    >
                    <q-item
                        clickable
                        v-close-popup
                        @click="openDialog('offer', offer)"
                        ><q-item-section
                            >Priorytet, notatki i przypomnienie</q-item-section
                        ></q-item
                    >
                </q-list></q-menu
            ></q-btn
        >
    </div>
</template>
