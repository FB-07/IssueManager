import './bootstrap';
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import { createStore } from './store';
import { combo } from './combo';
import { initTooltips } from './tooltip';
import { initBrand } from './brand';

window.Alpine = Alpine;

Alpine.plugin(collapse);

// The last account used in this browser (name and email only, never tokens): offered on the login page next time
try {
    const boot = window.__BOOT__ ?? {};
    if (boot.rememberAccount && boot.user?.email) {
        localStorage.setItem('im.accounts', JSON.stringify([{ email: boot.user.email, name: boot.user.name }]));
    }
} catch (e) {}

document.addEventListener('alpine:init', () => {
    Alpine.data('combo', combo);
    const boot = window.__BOOT__ ?? {};
    Alpine.store('app', createStore(boot));
});

initTooltips();
Alpine.start();
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initBrand);
else initBrand();
