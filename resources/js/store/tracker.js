// YouTrack projects and their fields.
import { api } from '../api';

export function trackerSlice() {
    return {
        catalog: null,
        projects: null,
        metaByProject: {},
        /** Projects + per-project metadata (types, priorities, people, custom fields) for Claude and the card. */
        async ensureCatalog() {
            if (this.catalog) return this.catalog;
            try {
                const c = await api('GET', '/api/youtrack/catalog');
                this.catalog = c;
                this.projects = this.projects ?? c.projects;
                this.metaByProject = { ...c.byProject, ...this.metaByProject };
            } catch (e) {
                this.notify(e.message, 'error');

                return {};
            }

            return this.catalog;
        },
        /** Dropdown option with the project icon (initials underneath while it loads or if it is missing). */
        projectOption(p) {
            return { value: p.key, label: `${p.key} · ${p.name}`, image: p.icon ?? null, shape: 'square', fallback: String(p.key).slice(0, 2).toUpperCase() };
        },
        /** Dropdown option with the person's YouTrack avatar (round, initials as fallback). */
        personOption(a) {
            const initials = String(a.label ?? a.value).split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();

            return { value: a.value, label: a.label, image: a.avatar ?? null, shape: 'round', fallback: initials };
        },
        /** The catalogue Claude receives has no image URLs (they only cost tokens). */
        catalogForApi(c) {
            if (!c?.projects) return c;

            return {
                projects: c.projects.map(({ key, name }) => ({ key, name })),
                byProject: Object.fromEntries(Object.entries(c.byProject ?? {}).map(([k, m]) => [k, { ...m, assignees: (m.assignees ?? []).map(({ value, label }) => ({ value, label })) }])),
            };
        },
        peopleFor(catalog, project) {
            return catalog?.byProject?.[project]?.assignees ?? [];
        },
        async loadProjects() {
            if (this.projects) return;
            try {
                this.projects = (await api('GET', '/api/youtrack/projects')).projects;
            } catch (e) {
                this.projects = [];
                this.notify(e.message, 'error');
            }
        },
        async loadMeta(project) {
            if (!project || this.metaByProject[project]) return;
            try {
                // the answer is merged into what is there when it arrives: another project's data that came in meanwhile must not be lost
                const meta = await api('GET', `/api/youtrack/projects/${encodeURIComponent(project)}/meta`);
                this.metaByProject = { ...this.metaByProject, [project]: meta };
            } catch (e) {
                this.notify(e.message, 'error');
            }
        },
        /**
         * The lists of the draft's project: its own fields and nothing of another project's. While the project's data is still
         * loading (or no project is chosen yet) the lists are empty, only the first project's types/priorities stand in before a choice.
         */
        get meta() {
            const empty = { types: [], priorities: [], subsystems: [], sprints: [], assignees: [], custom: [] };
            const own = this.metaByProject[this.draft?.project];
            if (own) return own;
            if (this.draft?.project) return empty;

            return { ...(this.metaByProject[this.projects?.[0]?.key] ?? empty), custom: [] };
        },
        issueUrl(key) {
            return `${String(this.trackerUrl ?? '').replace(/\/$/, '')}/issue/${key}`;
        },    };
}
