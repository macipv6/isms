import { createInertiaApp } from '@inertiajs/vue3';
import { createApp, h } from 'vue';
import '../css/app.css';

const appName = import.meta.env.VITE_APP_NAME || 'ISMS Builder';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.vue', { eager: true });
        return pages[`./pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
    progress: { color: '#4B5563' },
});
