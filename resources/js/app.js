import './bootstrap';
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import { createStore } from './store';
import { combo } from './combo';

window.Alpine = Alpine;

Alpine.plugin(collapse);

document.addEventListener('alpine:init', () => {
    Alpine.data('combo', combo);
    const boot = window.__BOOT__ ?? {};
    Alpine.store('app', createStore(boot));
});

Alpine.start();
