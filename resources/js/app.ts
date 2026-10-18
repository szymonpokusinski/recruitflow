import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { Quasar, Notify, Dialog } from 'quasar';
import pl from 'quasar/lang/pl';
import '@quasar/extras/material-icons/material-icons.css';
import 'quasar/src/css/index.sass';
import '../css/desktop-app.css';
import DesktopLayout from './layouts/DesktopLayout.vue';
import { initializeTheme } from './composables/useTheme.ts';

const pages = import.meta.glob<{
    default: DefineComponent & { layout?: typeof DesktopLayout };
}>('./Pages/*.vue');

void createInertiaApp({
    title: (title) => `${title} · RecruitFlow`,
    resolve: async (name) => {
        const load = pages[`./Pages/${name}.vue`];
        if (!load) throw new Error(`Nieznany widok: ${name}`);
        const module = await load();
        module.default.layout = DesktopLayout;
        return module.default;
    },
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) });
        app.use(plugin).use(Quasar, { lang: pl, plugins: { Notify, Dialog } });
        initializeTheme();
        app.mount(el);
    },
    progress: { color: '#2563eb', showSpinner: false },
});
