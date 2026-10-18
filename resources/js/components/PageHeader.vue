<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
defineProps<{
    title: string;
    subtitle?: string;
    backHref?: string;
    backLabel?: string;
}>();
</script>
<template>
    <header class="page-heading">
        <div>
            <Link v-if="backHref" :href="backHref" class="back-link"
                ><q-icon name="arrow_back" />{{ backLabel ?? 'Wróć' }}</Link
            >
            <h1>{{ title }}</h1>
            <p v-if="subtitle">{{ subtitle }}</p>
        </div>
        <div class="page-actions"><slot /></div>
    </header>
</template>
