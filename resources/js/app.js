import './bootstrap';
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import { createStore } from './store';

window.Alpine = Alpine;

Alpine.plugin(collapse);

document.addEventListener('alpine:init', () => {
    const boot = window.__BOOT__ ?? {};
    Alpine.store('app', createStore(boot));
});

Alpine.start();
