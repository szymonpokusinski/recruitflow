<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Offer } from '../types/recruitment.ts';
import AiMatchScore from './AiMatchScore.vue';
defineProps<{ offers: Offer[] }>();
</script>

<template>
    <section class="panel q-mt-lg">
        <div class="section-heading">
            <div>
                <h2>Najlepiej dopasowane oferty</h2>
                <p>Do pięciu najwyższych ocen AI zapisanych w arkuszu.</p>
            </div>
            <Link href="/offers" class="text-link"
                >Wszystkie oferty<q-icon name="arrow_forward"
            /></Link>
        </div>
        <q-markup-table v-if="offers.length" flat class="data-table">
            <thead>
                <tr>
                    <th class="text-left">Stanowisko / firma</th>
                    <th class="text-left">Dopasowanie AI</th>
                    <th class="text-left">Tryb pracy</th>
                    <th class="text-left">Status aplikowania</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="offer in offers" :key="offer.id">
                    <td>
                        <Link
                            :href="`/offers/${offer.id}#ai-analysis`"
                            class="table-main-link"
                            >{{ offer.title }}</Link
                        ><span class="table-secondary">{{
                            offer.company
                        }}</span>
                    </td>
                    <td>
                        <AiMatchScore :score="offer.aiMatchScore" compact />
                    </td>
                    <td>{{ offer.workMode || '—' }}</td>
                    <td>
                        {{
                            offer.applicationStage ||
                            (offer.cvSent === true
                                ? 'Wysłano CV'
                                : offer.cvSent === false
                                  ? 'Nie wysłano CV'
                                  : 'Brak danych')
                        }}
                    </td>
                </tr>
            </tbody>
        </q-markup-table>
        <p v-else class="detail-content muted">
            Brak ocen AI w bieżącym zbiorze. Oferty bez oceny nie są
            uwzględniane w rankingu.
        </p>
    </section>
</template>
