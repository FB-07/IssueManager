// The page of existing issues and the window with the details of one.
import { api } from '../api';

let issueTimer;
let issueSearchSeq = 0;

export function issuesSlice() {
    return {
        issuesOpen: false,
        issueProjectTotals: {},
        issueProjectRanges: {},
        issueFilters: { q: '', project: '', type: '', priority: '', state: '', assignee: '' },
        issuePage: 1,
        issuePerPage: 12,
        issueTotal: null,
        issueResults: [],
        issuesLoading: false,
        issueHasMore: false,
        issueEdit: null, // the issues attached lately in this browser ({ key, summary, url }); a copy of localStorage kept here so the screen follows it
        issuePickMode: false, // the issues modal is choosing the chat's context issue instead of editing
        issueMeta: null,
        issueSaving: false,
        async openIssues(pick = false, fresh = false) {
            this.issuePickMode = pick === true;
            this.editFromMine = false;
            this.issuesOpen = true;
            this.issueEdit = null;
            this.sidebarOpen = false;
            this.issuesLoading = true; // while the catalog loads the list is not empty, it is not here yet
            try {
                await this.ensureCatalog();
            } finally {
                await this.searchIssues(!fresh && this.issueResults.length ? this.issuePage : 1);
            }
        },
        /** Distinct filter choices; once a project is picked they come from that project only. */
        get issueFilterOptions() {
            return this.filterOptionsFor(this.issueFilters.project);
        },
        /** The values the filters offer: those of the chosen project, or of all of them. */
        filterOptionsFor(project) {
            const c = this.catalog ?? { projects: [], byProject: {} };
            const metas = project ? [c.byProject?.[project]].filter(Boolean) : Object.values(c.byProject ?? {});
            const uniq = (key) => [...new Set(metas.flatMap((m) => m[key] ?? []))];
            const people = new Map();
            metas.flatMap((m) => m.assignees ?? []).forEach((a) => people.set(a.value, { label: a.label, avatar: a.avatar ?? null }));

            return {
                projects: c.projects ?? [],
                types: uniq('types'),
                priorities: uniq('priorities'),
                states: uniq('states'),
                assignees: [...people].map(([value, a]) => ({ value, label: a.label, avatar: a.avatar })).sort((x, y) => x.label.localeCompare(y.label)),
            };
        },
        get issueFiltersActive() {
            return Object.values(this.issueFilters).some((v) => v !== '');
        },
        get issueTotalPages() {
            return this.issueTotal === null ? null : Math.max(1, Math.ceil(this.issueTotal / this.issuePerPage));
        },
        /** Page buttons: 1 … current-1 current current+1 … last (or just prev/next while the total is unknown). */
        get issuePageButtons() {
            const last = this.issueTotalPages;
            if (!last) return [];
            const set = new Set([1, last, this.issuePage - 1, this.issuePage, this.issuePage + 1]);
            const pages = [...set].filter((n) => n >= 1 && n <= last).sort((a, b) => a - b);

            return pages.flatMap((n, i) => (i > 0 && n - pages[i - 1] > 1 ? ['…', n] : [n]));
        },
        get issueRange() {
            if (!this.issueResults.length) return null;
            const from = (this.issuePage - 1) * this.issuePerPage + 1;

            return { from, to: from + this.issueResults.length - 1 };
        },
        async searchIssues(page = 1, quiet = false) {
            clearTimeout(issueTimer);
            const seq = ++issueSearchSeq; // only the latest search may fill the list (a slower, older one must not overwrite it)
            if (!quiet) this.issuesLoading = true;
            const params = new URLSearchParams({ page, per_page: this.issuePerPage });
            Object.entries(this.issueFilters).forEach(([k, v]) => v !== '' && params.set(k, v));
            try {
                const res = await api('GET', `/api/youtrack/issues?${params}`, undefined, { silent: quiet });
                if (seq !== issueSearchSeq) return;
                this.issueResults = res.issues;
                this.issueProjectTotals = res.project_totals ?? {};
                this.issueProjectRanges = res.project_ranges ?? {};
                this.issueHasMore = res.has_more;
                this.issueTotal = res.total;
                this.issuePage = res.page;
            } catch (e) {
                if (!quiet) this.notify(e.message, 'error');
            } finally {
                if (seq === issueSearchSeq) this.issuesLoading = false; // the latest search ends the loading, quiet or not
            }
        },
        /** Search as the user types (after a short pause), without needing Enter. */
        searchIssuesSoon() {
            clearTimeout(issueTimer);
            this.issuesLoading = true;
            issueTimer = setTimeout(() => this.searchIssues(1), 350);
        },
        applyIssueFilters() {
            return this.searchIssues(1);
        },
        resetIssueFilters() {
            this.issueFilters = { q: '', project: '', type: '', priority: '', state: '', assignee: '' };

            return this.searchIssues(1);
        },
        /** Changing the project can invalidate the other choices (their lists are per project). */
        onIssueProjectChange() {
            const o = this.issueFilterOptions;
            if (!o.types.includes(this.issueFilters.type)) this.issueFilters.type = '';
            if (!o.priorities.includes(this.issueFilters.priority)) this.issueFilters.priority = '';
            if (!o.states.includes(this.issueFilters.state)) this.issueFilters.state = '';
            if (!o.assignees.some((a) => a.value === this.issueFilters.assignee)) this.issueFilters.assignee = '';

            return this.searchIssues(1);
        },
        goToIssuePage(n) {
            if (n < 1 || (this.issueTotalPages && n > this.issueTotalPages) || (!this.issueTotalPages && n > this.issuePage && !this.issueHasMore)) return;

            return this.searchIssues(n);
        },
        setIssuePerPage(n) {
            this.issuePerPage = Number(n);

            return this.searchIssues(1);
        },
        /**
         * Keeps the list on screen up to date with what others change in YouTrack: every minute, and when the tab comes back to
         * the front. It is silent (no placeholders, no errors, never a trip to the login page) and leaves alone what the user is
         * in the middle of (editing an issue, ticking issues).
         */
        autoRefresh() {
            if (document.hidden || this.issuesOpen || this.issueEdit || this.mineSelectMode) return;
            if (this.page === 'mine') {
                api('GET', '/api/youtrack/mine/summary', undefined, { silent: true }).then((s) => (this.mineSummary = s)).catch(() => {});

                return this.searchMine(this.minePage, true);
            }
            if (this.page === 'all') return this.searchIssues(this.issuePage, true);
        },
        /** The refresh button: the same, in plain sight. */
        refreshList() {
            if (this.page === 'mine') {
                api('GET', '/api/youtrack/mine/summary').then((s) => (this.mineSummary = s)).catch(() => {});

                return this.searchMine(this.minePage);
            }

            return this.searchIssues(this.issuePage);
        },
        get issueGroups() {
            return this.groupByProject(this.issueResults, this.issueProjectTotals, this.issueProjectRanges);
        },
        groupByProject(list, totals = {}, ranges = {}) {
            const groups = new Map();
            for (const i of list) {
                const key = i.project ?? '';
                if (!groups.has(key)) {
                    const p = (this.catalog?.projects ?? []).find((x) => x.key === key);
                    // the project's own icon (initials underneath while it loads or when it has none)
                    groups.set(key, { key, name: i.project_name || key, icon: this.projectOption(p ?? { key, name: key }), items: [] });
                }
                groups.get(key).items.push(i);
            }

            // a count of its own for each project: which of ITS issues are on this page, and how many it has: "0-11 (28 issues)", next page "11-22 (28 issues)"
            return [...groups.values()].map((g) => {
                const r = ranges[g.key];
                const total = totals[g.key] != null ? `(${this.t('mine.total', { n: totals[g.key] })})` : '';

                return { ...g, count: [r ? `${r[0]}-${r[1]}` : '', total].filter(Boolean).join(' ') };
            });
        },
        /** "0/48 (123 issues)", then "48/96 (123 issues)": where the page starts and ends, and how many there are in all. */
        rangeLabel(page, perPage, shown, total) {
            const from = (page - 1) * perPage;
            const range = `${from}-${from + shown}`;

            return total === null ? range : `${range} (${this.t('mine.total', { n: total })})`;
        },
        get issuesRangeLabel() {
            return this.rangeLabel(this.issuePage, this.issuePerPage, this.issueResults.length, this.issueTotal);
        },
        /** The page of all the existing issues (/issues/all): the same kind of page as "my issues", without the account and the numbers. */
        async openIssuesPage({ push = true } = {}) {
            this.stopVoice(true);
            this.mineSelectMode = false;
            this.clearMineSelection();
            this.page = 'all';
            this.sidebarOpen = false;
            if (push && location.pathname !== '/issues/all') history.pushState({}, '', '/issues/all');
            this.ensureCatalog(); // the filters' values
            await this.searchIssues(this.issueResults.length ? this.issuePage : 1);
        },
        /** The other custom fields of the open issue that cannot be edited here (people, dates, texts, periods…), as [name, value]. */
        get issueEditExtraFields() {
            const editable = (this.issueMeta?.custom ?? []).map((c) => c.key);

            return Object.entries(this.issueEdit?.custom ?? {}).filter(([k]) => !editable.includes(k));
        },
        /** Closes the edit window (Back, or after saving); when it came from "your issues" the window goes with it. */
        closeIssueEdit() {
            this.issueEdit = null;
            if (this.editFromMine) {
                this.editFromMine = false;
                this.issuesOpen = false;
                if (this.page === 'mine') this.searchMine(this.minePage);
                else if (this.page === 'all') this.searchIssues(this.issuePage);
            }
        },
        async editIssue(issue) {
            this.issueEdit = { ...issue, custom: { ...(issue.custom ?? {}) }, original: { ...issue, custom: { ...(issue.custom ?? {}) } } };
            this.issueMeta = null;
            try {
                this.issueMeta = await api('GET', `/api/youtrack/projects/${encodeURIComponent(issue.project)}/meta`);
                // the issue carries the assignee's name; the form works with the login the list of people is keyed by
                const person = (this.issueMeta.assignees ?? []).find((a) => a.label === issue.assignee);
                this.issueEdit.assigneeValue = person?.value ?? null;
                this.issueEdit.originalAssignee = person?.value ?? null;
            } catch (e) {
                this.notify(e.message, 'error');
            }
        },
        async saveIssue() {
            const i = this.issueEdit;
            // the project's own enum fields that were changed (the others are shown, not edited)
            const changedCustom = Object.fromEntries((this.issueMeta?.custom ?? []).filter((c) => i.custom?.[c.key] && i.custom[c.key] !== i.original.custom?.[c.key]).map((c) => [c.key, i.custom[c.key]]));
            this.issueSaving = true;
            try {
                const updated = await api('PATCH', `/api/youtrack/issues/${encodeURIComponent(i.id)}`, {
                    summary: i.summary,
                    description: i.description ?? '',
                    type: i.type,
                    priority: i.priority,
                    // only what was changed: a field the user did not touch is left exactly as it is in YouTrack
                    ...(i.state && i.state !== i.original.state ? { state: i.state } : {}),
                    ...(i.assigneeValue && i.assigneeValue !== i.originalAssignee ? { assignee: i.assigneeValue } : {}),
                    ...(i.subsystem && i.subsystem !== i.original.subsystem ? { subsystem: i.subsystem } : {}),
                    ...(Object.keys(changedCustom).length ? { custom_fields: changedCustom } : {}),
                });
                this.issueResults = this.issueResults.map((r) => (r.id === updated.id ? updated : r));
                this.mineResults = this.mineResults.map((r) => (r.id === updated.id ? updated : r));
                this.closeIssueEdit();
                this.notify(this.t('issues.saved'), 'success');
            } catch (e) {
                this.notify(e.message, 'error');
            } finally {
                this.issueSaving = false;
            }
        },    };
}
