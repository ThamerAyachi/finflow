import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import ApexCharts from 'apexcharts';

const INK_2 = '#a1a1aa';
const INK_3 = '#71717a';
const GRID = 'rgba(255,255,255,0.06)';
const SURFACE = '#121215';
const MONO = "'JetBrains Mono Variable', ui-monospace, monospace";
const SANS = "'Inter Variable', ui-sans-serif, system-ui, sans-serif";

const compact = (v) => {
    const abs = Math.abs(v);
    if (abs >= 1_000_000) return (abs / 1_000_000).toFixed(1) + 'M';
    if (abs >= 1_000) return (abs / 1_000).toFixed(abs >= 10_000 ? 0 : 1) + 'k';
    return abs.toFixed(0);
};

// Base currency is printed by the layout; reports and charts are expressed in it.
const BASE = window.MONEY_BASE ?? { code: 'TND', decimals: 3 };

const money = (v, decimals = BASE.decimals, code = BASE.code) =>
    Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + ' ' + code;

const base = {
    chart: {
        background: 'transparent',
        fontFamily: SANS,
        toolbar: { show: false },
        zoom: { enabled: false },
        animations: { speed: 350 },
    },
    theme: { mode: 'dark' },
    dataLabels: { enabled: false },
    grid: { borderColor: GRID, strokeDashArray: 0, padding: { left: 8, right: 8 } },
    tooltip: { theme: 'dark' },
};

/**
 * Safe arithmetic evaluator for the calculator-style amount field.
 * Supports + - * / ( ) and decimals; never uses eval().
 */
export function evaluate(input) {
    const src = String(input ?? '').replace(/,/g, '.').replace(/[×x]/gi, '*').replace(/÷/g, '/').replace(/\s+/g, '');
    if (src === '' || !/^[\d.+\-*/()]+$/.test(src)) return null;

    let i = 0;
    const peek = () => src[i];

    const number = () => {
        const start = i;
        while (i < src.length && /[\d.]/.test(src[i])) i++;
        const text = src.slice(start, i);
        if (text === '' || text === '.' || (text.match(/\./g) || []).length > 1) throw new Error('number');
        return parseFloat(text);
    };
    const factor = () => {
        if (peek() === '-') { i++; return -factor(); }
        if (peek() === '+') { i++; return factor(); }
        if (peek() === '(') {
            i++;
            const v = expr();
            if (peek() !== ')') throw new Error('paren');
            i++;
            return v;
        }
        return number();
    };
    const term = () => {
        let v = factor();
        while (peek() === '*' || peek() === '/') {
            const op = src[i++];
            const r = factor();
            v = op === '*' ? v * r : v / r;
        }
        return v;
    };
    const expr = () => {
        let v = term();
        while (peek() === '+' || peek() === '-') {
            const op = src[i++];
            const r = term();
            v = op === '+' ? v + r : v - r;
        }
        return v;
    };

    try {
        const value = expr();
        return i === src.length && Number.isFinite(value) ? value : null;
    } catch {
        return null;
    }
}

Alpine.data('cashflowChart', (data) => ({
    chart: null,
    init() {
        this.chart = new ApexCharts(this.$refs.canvas, {
            ...base,
            chart: { ...base.chart, type: 'bar', height: 380, stacked: true },
            // Direction is encoded by position as well as colour: income rises
            // above the zero line, spending hangs below it.
            series: [
                { name: 'Income', data: data.income },
                { name: 'Expenses', data: data.expense.map((v) => -v) },
            ],
            colors: ['#22c55e', '#f43f5e'],
            plotOptions: { bar: { columnWidth: '62%', borderRadius: 2, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'all' } },
            stroke: { width: 0 },
            xaxis: {
                categories: data.labels,
                tickAmount: window.innerWidth < 640 ? 4 : 8,
                axisBorder: { show: false },
                axisTicks: { show: false },
                labels: { rotate: 0, hideOverlappingLabels: true, style: { colors: INK_3, fontFamily: MONO, fontSize: '11px' } },
                crosshairs: { show: false },
            },
            yaxis: {
                tickAmount: 4,
                labels: { formatter: (v) => (v < 0 ? '−' : '') + compact(v), style: { colors: INK_3, fontFamily: MONO, fontSize: '11px' } },
            },
            annotations: { yaxis: [{ y: 0, borderColor: 'rgba(255,255,255,0.2)', strokeDashArray: 0 }] },
            legend: {
                position: 'top', horizontalAlign: 'right', fontSize: '12px', labels: { colors: INK_2 },
                markers: { size: 5, shape: 'circle', strokeWidth: 0 }, itemMargin: { horizontal: 8 },
            },
            states: { hover: { filter: { type: 'lighten', value: 0.08 } }, active: { filter: { type: 'none' } } },
            tooltip: { ...base.tooltip, shared: true, intersect: false, y: { formatter: (v) => money(v) } },
        });
        this.chart.render();
    },
    destroy() {
        this.chart?.destroy();
    },
}));

Alpine.data('donutChart', (data) => ({
    chart: null,
    init() {
        if (!data.series.length) return;
        this.chart = new ApexCharts(this.$refs.canvas, {
            ...base,
            chart: { ...base.chart, type: 'donut', height: 210 },
            series: data.series,
            labels: data.labels,
            colors: data.colors,
            stroke: { width: 2, colors: [SURFACE] },
            legend: { show: false },
            plotOptions: {
                pie: {
                    expandOnClick: false,
                    donut: {
                        size: '74%',
                        labels: {
                            show: true,
                            name: { color: INK_3, fontSize: '11px', fontFamily: SANS, offsetY: 18 },
                            value: { color: '#fafafa', fontSize: '20px', fontFamily: MONO, fontWeight: 600, offsetY: -14, formatter: (v) => compact(Number(v)) },
                            total: {
                                show: true, showAlways: true, label: 'spent · ' + BASE.code, color: INK_3, fontSize: '11px', fontFamily: SANS,
                                formatter: (w) => compact(w.globals.seriesTotals.reduce((a, b) => a + b, 0)),
                            },
                        },
                    },
                },
            },
            states: { hover: { filter: { type: 'lighten', value: 0.06 } }, active: { filter: { type: 'none' } } },
            tooltip: { ...base.tooltip, fillSeriesColor: false, y: { formatter: (v) => money(v) } },
        });
        this.chart.render();
    },
    destroy() {
        this.chart?.destroy();
    },
}));

/** Quick-entry modal: all state lives here; Livewire is only called to suggest and save. */
Alpine.data('quickEntry', (config) => ({
    open: false,
    expr: '',
    type: 'expense',
    walletId: config.defaultWalletId,
    destinationWalletId: '',
    expenseCategoryId: '',
    incomeCategoryId: '',
    description: '',
    date: config.today,
    hint: '',
    error: '',
    saving: false,

    get value() {
        return evaluate(this.expr);
    },
    get isExpression() {
        return /[+\-*/()]/.test(this.expr.replace(/^\s*-/, ''));
    },
    get currency() {
        return config.wallets[this.walletId] ?? BASE;
    },
    get preview() {
        const v = this.value;
        return v === null || v <= 0 ? null : money(v, this.currency.decimals, this.currency.code);
    },

    show(type = null) {
        this.open = true;
        if (type) this.type = type;
        this.$nextTick(() => this.$refs.amount.focus());
    },
    hide() {
        this.open = false;
        this.error = '';
    },
    /** In the amount field, E / I / T switch the type instead of typing a letter. */
    amountKey(event) {
        const map = { e: 'expense', i: 'income', t: 'transfer' };
        const key = event.key.toLowerCase();
        if (map[key] && !event.ctrlKey && !event.metaKey && !event.altKey) {
            event.preventDefault();
            this.type = map[key];
        }
    },
    /** Ask the rule engine whether this description maps to a category / wallet. */
    async suggest() {
        const asked = this.description;
        const match = asked.trim() === '' ? null : await this.$wire.suggest(asked);
        if (asked !== this.description) return; // a newer keystroke superseded this lookup

        this.hint = '';
        if (!match) return;

        if (match.categoryId) {
            if (match.categoryType === 'income') this.incomeCategoryId = String(match.categoryId);
            else this.expenseCategoryId = String(match.categoryId);
            this.hint = 'Rule “' + match.keyword + '” → ' + match.categoryName;
        }
        if (match.walletId && config.wallets[match.walletId]) this.walletId = match.walletId;
    },
    async submit() {
        const v = this.value;
        if (v === null || v <= 0) {
            this.error = 'Enter an amount greater than zero.';
            this.$refs.amount.focus();
            return;
        }
        this.saving = true;
        this.error = '';
        let result;
        try {
            result = await this.$wire.save({
                type: this.type,
                amount: v.toFixed(this.currency.decimals),
                walletId: this.walletId,
                destinationWalletId: this.type === 'transfer' ? this.destinationWalletId || null : null,
                categoryId: (this.type === 'income' ? this.incomeCategoryId : this.type === 'expense' ? this.expenseCategoryId : '') || null,
                description: this.description,
                date: this.date,
            });
        } catch {
            result = { ok: false, error: 'Could not reach the server.' };
        }
        this.saving = false;

        if (!result?.ok) {
            this.error = result?.error ?? 'Could not save.';
            return;
        }
        Object.assign(this, { expr: '', description: '', hint: '', expenseCategoryId: '', incomeCategoryId: '', date: config.today });
        this.hide();
    },
}));

/** Global shortcuts + toast stack, mounted on <body>. */
Alpine.data('shell', () => ({
    nav: false,
    toasts: [],
    hotkey(event) {
        const target = event.target;
        const typing = target instanceof HTMLElement
            && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));

        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            window.dispatchEvent(new CustomEvent('quick-entry'));
        } else if (!typing && !event.ctrlKey && !event.metaKey && !event.altKey && event.key.toLowerCase() === 'n') {
            event.preventDefault();
            window.dispatchEvent(new CustomEvent('quick-entry'));
        }
    },
    toast(detail) {
        const id = Date.now() + Math.random();
        this.toasts.push({ id, message: detail.message, tone: detail.tone ?? 'success' });
        setTimeout(() => (this.toasts = this.toasts.filter((t) => t.id !== id)), 3200);
    },
}));

Livewire.start();
