<script setup lang="ts">
withDefaults(
    defineProps<{ title?: string; description?: string; icon?: string }>(),
    {
        title: 'Brak wyników',
        description: 'Zmień kryteria wyszukiwania lub wyczyść filtry.',
        icon: 'search_off',
    },
);
defineEmits<{ clear: [] }>();
</script>
<template>
    <div class="empty-state" role="status">
        <q-icon :name="icon" size="36px" />
        <h3>{{ title }}</h3>
        <p>{{ description }}</p>
        <slot
            ><q-btn
                flat
                color="primary"
                label="Wyczyść filtry"
                @click="$emit('clear')"
        /></slot>
    </div>
</template>
