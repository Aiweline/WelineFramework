function cssColor(name, fallback) {
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return value || fallback;
}

function finite(value) {
    const number = Number(value);
    return Number.isFinite(number) ? number : 0;
}

function resolvePaint(value, fallback = 'currentColor') {
    if (typeof value !== 'string' || value.trim() === '') return fallback;
    const paint = value.trim();
    const token = paint.match(/^var\((--[a-z0-9-]+)\)$/i)?.[1];
    return token ? cssColor(token, fallback) : paint;
}

function datasetColor(dataset, index, fill = false) {
    const value = fill ? dataset.backgroundColor : dataset.borderColor;
    if (Array.isArray(value)) return resolvePaint(value[index % value.length]);
    if (typeof value === 'string' && value !== '') return resolvePaint(value);
    const palette = [
        '--weline-theme-primary',
        '--weline-theme-success',
        '--weline-theme-warning',
        '--weline-theme-info',
        '--weline-theme-danger',
    ];
    return cssColor(palette[index % palette.length], 'currentColor');
}

export class NativeChart {
    constructor(canvas, config = {}) {
        if (!(canvas instanceof HTMLCanvasElement)) {
            throw new TypeError('NativeChart requires a canvas element.');
        }
        this.canvas = canvas;
        this.context = canvas.getContext('2d');
        this.type = config.type || 'line';
        this.data = config.data || {labels: [], datasets: []};
        this.options = config.options || {};
        this.resizeObserver = new ResizeObserver(() => this.render());
        this.resizeObserver.observe(canvas.parentElement || canvas);
        this.render();
    }

    update() {
        this.render();
        return this;
    }

    destroy() {
        this.resizeObserver.disconnect();
        this.context?.clearRect(0, 0, this.canvas.width, this.canvas.height);
    }

    render() {
        if (!this.context) return;
        const rect = this.canvas.getBoundingClientRect();
        const width = Math.max(240, Math.round(rect.width || this.canvas.parentElement?.clientWidth || 640));
        const height = Math.max(180, Math.round(rect.height || this.canvas.parentElement?.clientHeight || 280));
        const ratio = Math.max(1, window.devicePixelRatio || 1);
        this.canvas.width = Math.round(width * ratio);
        this.canvas.height = Math.round(height * ratio);
        this.context.setTransform(ratio, 0, 0, ratio, 0, 0);
        this.context.clearRect(0, 0, width, height);
        if (this.type === 'doughnut' || this.type === 'pie') {
            this.drawDoughnut(width, height);
            return;
        }
        this.drawCartesian(width, height);
    }

    drawCartesian(width, height) {
        const context = this.context;
        const labels = Array.isArray(this.data.labels) ? this.data.labels : [];
        const datasets = Array.isArray(this.data.datasets) ? this.data.datasets : [];
        const padding = {top: 24, right: 20, bottom: 42, left: 52};
        const plotWidth = Math.max(1, width - padding.left - padding.right);
        const plotHeight = Math.max(1, height - padding.top - padding.bottom);
        const values = datasets.flatMap((dataset) => (dataset.data || []).map(finite));
        const maximum = Math.max(1, ...values);
        const minimum = Math.min(0, ...values);
        const range = Math.max(1, maximum - minimum);
        const text = cssColor('--weline-theme-text-muted', 'currentColor');
        const border = cssColor('--weline-theme-border', 'currentColor');

        context.strokeStyle = border;
        context.fillStyle = text;
        context.lineWidth = 1;
        context.font = '12px system-ui, sans-serif';
        context.textAlign = 'right';
        context.textBaseline = 'middle';
        for (let step = 0; step <= 4; step++) {
            const y = padding.top + (plotHeight * step / 4);
            const value = maximum - (range * step / 4);
            context.beginPath();
            context.moveTo(padding.left, y);
            context.lineTo(width - padding.right, y);
            context.globalAlpha = 0.45;
            context.stroke();
            context.globalAlpha = 1;
            context.fillText(new Intl.NumberFormat().format(Math.round(value * 100) / 100), padding.left - 8, y);
        }

        const xAt = (index) => padding.left + (labels.length <= 1 ? plotWidth / 2 : plotWidth * index / (labels.length - 1));
        const yAt = (value) => padding.top + (maximum - finite(value)) / range * plotHeight;
        const labelStep = Math.max(1, Math.ceil(labels.length / Math.max(2, Math.floor(plotWidth / 90))));
        context.textAlign = 'center';
        context.textBaseline = 'top';
        labels.forEach((label, index) => {
            if (index % labelStep === 0 || index === labels.length - 1) {
                context.fillText(String(label).slice(0, 18), xAt(index), height - padding.bottom + 10);
            }
        });

        datasets.forEach((dataset, datasetIndex) => {
            const data = Array.isArray(dataset.data) ? dataset.data : [];
            if (this.type === 'bar') {
                const groupWidth = plotWidth / Math.max(1, labels.length);
                const barWidth = Math.max(2, groupWidth * 0.72 / Math.max(1, datasets.length));
                data.forEach((value, index) => {
                    const x = padding.left + groupWidth * index + groupWidth * 0.14 + barWidth * datasetIndex;
                    const y = yAt(value);
                    context.fillStyle = datasetColor(dataset, datasetIndex, true);
                    context.fillRect(x, y, barWidth, padding.top + plotHeight - y);
                });
                return;
            }
            context.beginPath();
            data.forEach((value, index) => {
                const x = xAt(index);
                const y = yAt(value);
                if (index === 0) context.moveTo(x, y);
                else context.lineTo(x, y);
            });
            context.strokeStyle = datasetColor(dataset, datasetIndex);
            context.lineWidth = finite(dataset.borderWidth) || 2;
            context.stroke();
            data.forEach((value, index) => {
                context.beginPath();
                context.arc(xAt(index), yAt(value), 2.5, 0, Math.PI * 2);
                context.fillStyle = datasetColor(dataset, datasetIndex);
                context.fill();
            });
        });
    }

    drawDoughnut(width, height) {
        const context = this.context;
        const dataset = this.data.datasets?.[0] || {data: []};
        const values = (dataset.data || []).map((value) => Math.max(0, finite(value)));
        const total = values.reduce((sum, value) => sum + value, 0) || 1;
        const centerX = width / 2;
        const centerY = height / 2;
        const radius = Math.max(30, Math.min(width, height) * 0.34);
        let angle = -Math.PI / 2;
        values.forEach((value, index) => {
            const next = angle + value / total * Math.PI * 2;
            context.beginPath();
            context.arc(centerX, centerY, radius, angle, next);
            context.arc(centerX, centerY, radius * 0.56, next, angle, true);
            context.closePath();
            context.fillStyle = datasetColor(dataset, index, true);
            context.fill();
            angle = next;
        });
    }
}
