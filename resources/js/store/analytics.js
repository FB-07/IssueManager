// The analysis page and its charts.
import { api } from '../api';

let analyticsSeq = 0;
const CHART = { w: 720, h: 260, l: 44, r: 12, t: 12, b: 30 };
const niceMax = (v) => {
    if (v <= 4) return 4;
    const pow = 10 ** Math.floor(Math.log10(v));
    const step = [1, 2, 2.5, 5, 10].map((m) => m * pow).find((m) => m * 4 >= v) ?? 10 * pow;

    return Math.ceil(v / step) * step;
};
const esc = (v) => String(v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const axesSvg = (g) => [
    ...g.ticks.map((tk) => `<line x1="${g.l}" x2="${g.w - g.r}" y1="${tk.y}" y2="${tk.y}" stroke="var(--line)" stroke-width="1"/><text x="${g.l - 8}" y="${tk.y + 4}" text-anchor="end" font-size="11" fill="var(--faint)">${esc(tk.label)}</text>`),
    ...g.names.map((n) => `<text x="${n.x}" y="${g.h - 10}" text-anchor="middle" font-size="11" fill="var(--muted)">${esc(n.label)}</text>`),
].join('');
const hitSvg = (x, w, h, title) => `<rect x="${x}" y="0" width="${w}" height="${h}" fill="var(--fg)" fill-opacity="0" class="analytics-hit" title="${esc(title)}" data-tip-delay="0"/>`;
const compact = (n) => (n >= 1000 ? `${Math.round(n / 100) / 10}K` : String(n));
const ticksFor = (max, y) => [0, 1, 2, 3, 4].map((i) => ({ value: (max / 4) * i, label: compact((max / 4) * i), y: y((max / 4) * i) }));
const roundedTop = (x, y, w, h, r = 4) => {
    if (h <= 0) return '';
    const k = Math.min(r, h, w / 2);

    return `M${x},${y + h}V${y + k}Q${x},${y} ${x + k},${y}H${x + w - k}Q${x + w},${y} ${x + w},${y + k}V${y + h}Z`;
};
function chartBars(series, label, title, tip) {
    const { w, h, l, r, t, b } = CHART;
    const innerH = h - t - b;
    const max = niceMax(Math.max(0, ...series.map((p) => Math.max(p.created, p.resolved))));
    const y = (v) => t + innerH - (v / max) * innerH;
    const band = (w - l - r) / Math.max(series.length, 1);
    const bar = Math.min(24, band * 0.32);

    const bars = series.map((p, i) => {
        const x0 = l + band * i;
        const gx = x0 + (band - (2 * bar + 2)) / 2;

        return {
            label: label(p), cx: x0 + band / 2, x0, band,
            created: roundedTop(gx, y(p.created), bar, y(0) - y(p.created)),
            resolved: roundedTop(gx + bar + 2, y(p.resolved), bar, y(0) - y(p.resolved)),
            title: `${title(p)}: ${tip(p.created, p.resolved)}`,
        };
    });
    const g = { w, h, l, r, baseline: y(0), ticks: ticksFor(max, y), names: bars.map((b) => ({ x: b.cx, label: b.label })), bars };
    g.svg = axesSvg(g) + bars.map((b) => `<path d="${b.created}" style="fill: var(--viz-1)"/><path d="${b.resolved}" style="fill: var(--viz-3)"/>${hitSvg(b.x0, b.band, g.baseline, b.title)}`).join('');

    return g;
}
function chartLine(series, label, title, tip) {
    const { w, h, l, r, t, b } = CHART;
    const innerH = h - t - b;
    const max = niceMax(Math.max(0, ...series.map((p) => p.open)));
    const y = (v) => t + innerH - (v / max) * innerH;
    const band = (w - l - r) / Math.max(series.length, 1);
    const pts = series.map((p, i) => ({ x: l + band * i + band / 2, y: y(p.open), p }));
    const line = pts.map((p, i) => `${i ? 'L' : 'M'}${p.x.toFixed(1)},${p.y.toFixed(1)}`).join('');
    const last = pts.at(-1);

    const points = pts.map((p, i) => ({ label: label(p.p), cx: p.x, x0: l + band * i, band, title: `${title(p.p)}: ${tip(p.p.open)}` }));
    const area = pts.length ? `${line}L${last.x.toFixed(1)},${y(0)}L${pts[0].x.toFixed(1)},${y(0)}Z` : '';
    const end = last ? { x: last.x, y: last.y, value: last.p.open } : null;
    const g = { w, h, l, r, baseline: y(0), ticks: ticksFor(max, y), line, area, end, points, names: points.map((p) => ({ x: p.cx, label: p.label })) };
    g.svg = axesSvg(g)
        + (pts.length ? `<path d="${area}" style="fill: var(--viz-2); fill-opacity: 0.1"/><path d="${line}" fill="none" style="stroke: var(--viz-2)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>` : '')
        + (end ? `<circle cx="${end.x}" cy="${end.y}" r="6" style="fill: var(--surface)"/><circle cx="${end.x}" cy="${end.y}" r="4" style="fill: var(--viz-2)"/><text x="${end.x}" y="${end.y - 12}" text-anchor="middle" font-size="12" font-weight="600" fill="var(--fg)">${end.value}</text>` : '')
        + points.map((p) => hitSvg(p.x0, p.band, g.baseline, p.title)).join('');

    return g;
}

export function analyticsSlice() {
    return {
 // the tick boxes are only there while the user is selecting
        analytics: null, // the numbers of the analysis page
        analyticsLoading: false,
        analyticsGranularity: 'week',
        analyticsProject: '',
        analyticsMine: false, // only the issues assigned to the user
        async openAnalytics({ push = true } = {}) {
            this.stopVoice(true);
            this.page = 'analytics';
            this.sidebarOpen = false;
            if (push && location.pathname !== '/analytics') history.pushState({}, '', '/analytics');
            this.ensureCatalog(); // the project filter
            await this.loadAnalytics();
        },
        async loadAnalytics() {
            const seq = ++analyticsSeq;
            this.analyticsLoading = true;
            const params = new URLSearchParams({ granularity: this.analyticsGranularity });
            if (this.analyticsProject) params.set('project', this.analyticsProject);
            if (this.analyticsMine) params.set('mine', '1');
            try {
                const res = await api('GET', `/api/youtrack/analytics?${params}`);
                if (seq === analyticsSeq) this.analytics = res;
            } catch (e) {
                if (seq === analyticsSeq) this.notify(e.message, 'error');
            } finally {
                if (seq === analyticsSeq) this.analyticsLoading = false;
            }
        },
        setAnalyticsGranularity(g) {
            if (g === this.analyticsGranularity) return;
            this.analyticsGranularity = g;

            return this.loadAnalytics();
        },
        setAnalyticsMine(v) {
            if (v === this.analyticsMine) return;
            this.analyticsMine = v;

            return this.loadAnalytics();
        },
        setAnalyticsProject(p) {
            this.analyticsProject = p || '';

            return this.loadAnalytics();
        },
        /** "12/10", "out", "2026": the name of a period under the charts. */
        periodLabel(p) {
            const d = new Date(`${p.start}T12:00:00`);
            const loc = this.locale === 'pt' ? 'pt-PT' : 'en-GB';
            const g = this.analytics?.granularity;
            if (g === 'year') return String(d.getFullYear());
            if (g === 'month') return d.toLocaleDateString(loc, { month: 'short' }).replace('.', '');

            return d.toLocaleDateString(loc, { day: '2-digit', month: '2-digit' });
        },
        /** "Week of 12 Oct 2026", "October 2026", "2026": the full name, for the tooltip. */
        periodTitle(p) {
            const d = new Date(`${p.start}T12:00:00`);
            const loc = this.locale === 'pt' ? 'pt-PT' : 'en-GB';
            const g = this.analytics?.granularity;
            if (g === 'year') return String(d.getFullYear());
            if (g === 'month') return d.toLocaleDateString(loc, { month: 'long', year: 'numeric' });

            return this.t('analytics.week_of', { date: d.toLocaleDateString(loc, { day: '2-digit', month: 'short', year: 'numeric' }) });
        },
        /** The geometry of the "created vs resolved" columns (an SVG of 720 x 260). */
        get analyticsBars() {
            const series = this.analytics?.series ?? [];

            return chartBars(series, (p) => this.periodLabel(p), (p) => this.periodTitle(p), (c, r) => this.t('analytics.tip_bars', { created: c, resolved: r }));
        },
        /** The geometry of the backlog line. */
        get analyticsBacklog() {
            const series = this.analytics?.series ?? [];

            return chartLine(series, (p) => this.periodLabel(p), (p) => this.periodTitle(p), (n) => this.t('analytics.tip_backlog', { n }));
        },
        /** The widest value of a list of {count}: what its bars are measured against. */
        barMax(list) {
            return Math.max(1, ...(list ?? []).map((i) => i.count));
        },
        /** The open issues of the past that are older than N days, for the age chart's labels. */
        agingLabel(a, i) {
            const list = this.analytics?.aging ?? [];
            if (a.max === null) return this.t('analytics.age_over', { n: list[i - 1]?.max ?? 0 });

            return this.t('analytics.age_upto', { n: a.max });
        },    };
}
