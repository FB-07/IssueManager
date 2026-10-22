// The page of the user's own issues.
import { api } from '../api';
import { MAX_ISSUES } from './shared';

let mineTimer;
let mineSeq = 0;

export function mineSlice() {
    return {
 // 'chat'; 'mine': the user's own issues (/issues); 'all': the existing issues (/issues/all); 'analytics': the analysis (/analytics)
        editFromMine: false, // the edit window was opened from that page: closing it returns there
        mineScope: 'assigned',
        mineStatus: 'open',
        mineQ: '',
        mineFilters: { project: '', type: '', priority: '', state: '' },
        minePerPage: 12,
        mineSelectMode: false,
        mineProjectTotals: {},
        mineProjectRanges: {},
        mineSelected: [], // issues ticked on the page (up to the number that can go with one message)
        mineText: '', // the message written in the bar of the selection
        mineResults: [],
        mineLoading: false,
        minePage: 1,
        mineHasMore: false,
        mineTotal: null,
        mineSummary: null,
        async openMine({ push = true } = {}) {
            this.stopVoice(true);
            this.mineSelectMode = false;
            this.clearMineSelection();
            this.page = 'mine';
            this.sidebarOpen = false;
            if (push && location.pathname !== '/issues') history.pushState({}, '', '/issues');
            this.mineLoading = true;
            this.ensureCatalog(); // the filters' values
            api('GET', '/api/youtrack/mine/summary').then((s) => (this.mineSummary = s)).catch(() => {});
            await this.searchMine(1);
        },
        async searchMine(page = 1, quiet = false) {
            clearTimeout(mineTimer);
            const seq = ++mineSeq;
            if (!quiet) this.mineLoading = true;
            const params = new URLSearchParams({ scope: this.mineScope, status: this.mineStatus, page, per_page: this.minePerPage });
            Object.entries(this.mineFilters).forEach(([k, v]) => v !== '' && params.set(k, v));
            if (this.mineQ.trim()) params.set('q', this.mineQ.trim());
            try {
                const res = await api('GET', `/api/youtrack/mine?${params}`, undefined, { silent: quiet });
                if (seq !== mineSeq) return;
                this.mineResults = res.issues;
                this.mineProjectTotals = res.project_totals ?? {};
                this.mineProjectRanges = res.project_ranges ?? {};
                this.mineHasMore = res.has_more;
                this.mineTotal = res.total;
                this.minePage = res.page;
            } catch (e) {
                if (seq === mineSeq && !quiet) {
                    this.mineResults = [];
                    this.notify(e.message, 'error');
                }
            } finally {
                if (seq === mineSeq) this.mineLoading = false;
            }
        },
        /** The issues of the page, one group per project (the server sorts by project, so each project is in one place). */
        get mineGroups() {
            return this.groupByProject(this.mineResults, this.mineProjectTotals, this.mineProjectRanges);
        },
        get mineFilterOptions() {
            return this.filterOptionsFor(this.mineFilters.project);
        },
        get mineFiltersActive() {
            return Object.values(this.mineFilters).some((v) => v !== '') || this.mineQ.trim() !== '';
        },
        get mineRangeLabel() {
            return this.rangeLabel(this.minePage, this.minePerPage, this.mineResults.length, this.mineTotal);
        },
        /** Page buttons: 1 … current-1 current current+1 … last. */
        get minePageButtons() {
            const last = this.mineTotalPages;
            if (!last) return [];
            const set = new Set([1, last, this.minePage - 1, this.minePage, this.minePage + 1]);
            const pages = [...set].filter((n) => n >= 1 && n <= last).sort((a, b) => a - b);

            return pages.flatMap((n, i) => (i > 0 && n - pages[i - 1] > 1 ? ['…', n] : [n]));
        },
        goToMinePage(n) {
            if (n < 1 || n === this.minePage || (this.mineTotalPages && n > this.mineTotalPages) || (!this.mineTotalPages && n > this.minePage && !this.mineHasMore)) return;

            return this.searchMine(n);
        },
        get mineTotalPages() {
            return this.mineTotal === null ? null : Math.max(1, Math.ceil(this.mineTotal / this.minePerPage));
        },
        setMineFilter(key, value) {
            this.mineFilters[key] = value || '';
            if (key === 'project') {
                const o = this.mineFilterOptions;
                if (!o.types.includes(this.mineFilters.type)) this.mineFilters.type = '';
                if (!o.priorities.includes(this.mineFilters.priority)) this.mineFilters.priority = '';
                if (!o.states.includes(this.mineFilters.state)) this.mineFilters.state = '';
            }

            return this.searchMine(1);
        },
        resetMineFilters() {
            this.mineFilters = { project: '', type: '', priority: '', state: '' };
            this.mineQ = '';

            return this.searchMine(1);
        },
        setMinePerPage(n) {
            this.minePerPage = Number(n);

            return this.searchMine(1);
        },
        searchMineSoon() {
            clearTimeout(mineTimer);
            this.mineLoading = true;
            mineTimer = setTimeout(() => this.searchMine(1), 350);
        },
        isMineSelected(id) {
            return this.mineSelected.some((i) => i.id === id);
        },
        toggleMineSelect(issue) {
            if (this.isMineSelected(issue.id)) {
                this.mineSelected = this.mineSelected.filter((i) => i.id !== issue.id);
            } else if (this.mineSelected.length >= MAX_ISSUES) {
                this.notify(this.t('errors.too_many_issues', { max: MAX_ISSUES }), 'error');
            } else {
                this.mineSelected = [...this.mineSelected, issue];
            }
        },
        toggleMineSelectMode() {
            this.mineSelectMode = !this.mineSelectMode;
            if (!this.mineSelectMode) this.clearMineSelection();
        },
        clearMineSelection() {
            this.mineSelected = [];
            this.mineText = '';
        },
        /** A new chat with the ticked issues attached; with a message, it is sent right away. */
        startChatFromMine() {
            const issues = [...this.mineSelected];
            const text = this.mineText.trim();
            this.mineSelectMode = false;
            this.clearMineSelection();
            this.newChat(); // goes to the chat, empty
            issues.forEach((i) => this.attachIssue(i));
            if (text) {
                this.composer.text = text;
                this.send();
            }
        },
        /** One of the number tiles at the top: shows exactly those issues. */
        showMine(scope, status) {
            this.mineScope = scope;
            this.mineStatus = status;

            return this.searchMine(1);
        },
        /** Opens an issue of the list in the editing window. */
        /** Opens an issue of a page's list in the editing window (closing it returns to the page). */
        editMine(issue) {
            this.editFromMine = true;
            this.issuePickMode = false;
            this.issuesOpen = true;

            return this.editIssue(issue);
        },    };
}
