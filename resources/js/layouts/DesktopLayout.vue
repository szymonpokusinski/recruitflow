<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useQuasar } from 'quasar';
import {
    loadRecruitment,
    useRecruitment,
} from '../composables/useRecruitment.ts';
import { useTheme } from '../composables/useTheme.ts';
import { preferences } from '../composables/usePreferences.ts';
import type { SharedProps } from '../types/recruitment.ts';
import ManagementDialogs from '../components/ManagementDialogs.vue';
import { loadManagement, scheduleSync } from '../composables/useManagement.ts';
const page = usePage<SharedProps>();
const drawer = ref(false);
const quasar = useQuasar();
function closeNavigation(): void {
    if (quasar.screen.lt.md) drawer.value = false;
}
const { dark, toggleTheme } = useTheme();
const { state } = useRecruitment();
const nav = [
    { label: 'Dashboard', href: '/', icon: 'space_dashboard' },
    { label: 'Oferty', href: '/offers', icon: 'work_outline' },
    { label: 'Aplikacje', href: '/applications', icon: 'people_outline' },
    { label: 'Poczta rekrutacyjna', href: '/mail', icon: 'mark_email_unread' },
    { label: 'Aktywność HR', href: '/activity', icon: 'history' },
    { label: 'Profile wyszukiwania', href: '/profiles', icon: 'tune' },
    { label: 'Ustawienia', href: '/settings', icon: 'settings' },
];

const path = computed(() => page.url.split('?')[0] ?? '/');
const active = (href: string): boolean =>
    href === '/' ? path.value === '/' : path.value.startsWith(href);
let timer: ReturnType<typeof setInterval> | null = null;
let polling = false;
const refresh = async () => {
    if (polling) return;
    polling = true;
    try {
        await Promise.all([loadRecruitment(true), loadManagement()]);
    } finally {
        polling = false;
    }
};
const online = () => {
    void scheduleSync().catch(() => {});
};
onMounted(() => {
    void refresh();
    online();
    window.addEventListener('online', online);
    timer = setInterval(() => {
        if (!document.hidden) {
            online();
            void refresh();
        }
    }, 60000);
});
onUnmounted(() => {
    window.removeEventListener('online', online);
    if (timer) clearInterval(timer);
});
</script>

<template>
    <q-layout view="hHh Lpr fFf" class="desktop-shell">
        <q-header class="workspace-toolbar">
            <q-toolbar>
                <q-btn
                    flat
                    round
                    dense
                    icon="menu"
                    class="lt-md"
                    aria-label="Otwórz nawigację"
                    @click="drawer = !drawer"
                />
                <span class="toolbar-product">RecruitFlow Desktop</span>
                <q-space />
                <q-btn
                    flat
                    round
                    dense
                    icon="refresh"
                    aria-label="Odśwież lokalny cache"
                    :loading="state.loading"
                    @click="loadRecruitment(true)"
                />
                <span class="runtime-label gt-xs">{{
                    page.props.runtime === 'native'
                        ? 'Aplikacja macOS'
                        : 'Podgląd Herd'
                }}</span>
                <q-badge v-if="state.loaded" class="demo-badge q-mx-md">{{
                    state.mode === 'local'
                        ? 'TRYB LOKALNY'
                        : 'SQLITE · GOOGLE SHEETS'
                }}</q-badge>
                <q-btn
                    flat
                    round
                    dense
                    :icon="dark ? 'light_mode' : 'dark_mode'"
                    :aria-label="
                        dark ? 'Włącz jasny motyw' : 'Włącz ciemny motyw'
                    "
                    @click="toggleTheme"
                >
                    <q-tooltip>{{
                        dark ? 'Jasny motyw' : 'Ciemny motyw'
                    }}</q-tooltip>
                </q-btn>
            </q-toolbar>
            <q-linear-progress
                v-if="state.loading"
                indeterminate
                color="primary"
                size="2px"
            />
        </q-header>

        <q-drawer
            v-model="drawer"
            show-if-above
            :width="232"
            :breakpoint="1023"
            bordered
            class="desktop-sidebar"
        >
            <div class="sidebar-content">
                <Link href="/" class="sidebar-brand"
                    ><span class="brand-mark">RF</span
                    ><span>RecruitFlow<small>Desktop</small></span></Link
                >
                <div class="nav-caption">PRZESTRZEŃ ROBOCZA</div>
                <nav aria-label="Nawigacja główna">
                    <Link
                        v-for="item in nav"
                        :key="item.href"
                        :href="item.href"
                        class="nav-link"
                        :class="{ active: active(item.href) }"
                        :aria-current="active(item.href) ? 'page' : undefined"
                        @click="closeNavigation"
                    >
                        <q-icon :name="item.icon" size="20px" /><span>{{
                            item.label
                        }}</span>
                    </Link>
                </nav>
                <q-space />
                <div class="sidebar-demo">
                    <q-icon name="storage" size="19px" />
                    <div>
                        {{
                            state.mode === 'local'
                                ? 'Dane lokalne SQLite'
                                : 'Lokalny cache SQLite'
                        }}<small>{{
                            state.mode === 'local'
                                ? 'Brak aktywnych źródeł.'
                                : `${state.sources.length} aktywne źródła Google Sheets`
                        }}</small>
                    </div>
                </div>
                <div class="sidebar-user">
                    <q-avatar size="32px" color="grey-4" text-color="grey-9"
                        >D</q-avatar
                    >
                    <div>
                        {{ preferences.displayName
                        }}<small>{{
                            state.mode === 'local'
                                ? 'Zapis lokalny SQLite'
                                : 'Opcjonalna synchronizacja'
                        }}</small>
                    </div>
                </div>
            </div>
        </q-drawer>

        <q-page-container>
            <main class="workspace" id="workspace">
                <q-banner
                    v-if="state.error"
                    rounded
                    class="error-banner q-mb-lg"
                >
                    {{ state.error }}
                    <template #action
                        ><q-btn
                            flat
                            label="Spróbuj ponownie"
                            @click="loadRecruitment(true)"
                    /></template>
                </q-banner>
                <q-banner
                    v-if="
                        state.mode === 'connected' &&
                        state.sources.some((source) => source.last_error)
                    "
                    rounded
                    class="q-mb-lg"
                >
                    Ostatnia synchronizacja jednego ze źródeł nie powiodła się.
                    Wyświetlamy poprzedni lokalny cache.
                    <template #action
                        ><Link href="/settings/integrations" class="text-link"
                            >Sprawdź integracje</Link
                        ></template
                    >
                </q-banner>
                <slot />
                <ManagementDialogs />
            </main>
        </q-page-container>
    </q-layout>
</template>
