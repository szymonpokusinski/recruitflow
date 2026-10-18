<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
defineProps<{
    label: string;
    value: number;
    caption: string;
    icon: string;
    href: string;
}>();
</script>
<template>
    <Link :href="href" class="stat-card panel"
        ><div class="stat-label">
            {{ label }}<q-icon :name="icon" size="19px" />
        </div>
        <div class="stat-value">{{ value }}</div>
        <div class="stat-caption">
            {{ caption }}<q-icon name="arrow_forward" /></div
    ></Link>
</template>
