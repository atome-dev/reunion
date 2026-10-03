const EMPTY = '0'.repeat(28);
const NEXT = { 0: 'p', p: 'd', d: '0' };

document.addEventListener('alpine:init', () => {
    window.Alpine.data('availabilityGrid', ({ weeks, cells, canEdit }) => ({
        weeks,
        cells: { ...cells },
        canEdit,
        week: Math.max(0, weeks.findIndex((week) => week.some((day) => day.inRange))),
        mobileDay: 0,
        painting: false,
        paintValue: '0',
        dirty: new Set(),
        status: 'idle',

        init() {
            this.mobileDay = this.firstDayIndex();
        },

        get currentWeek() {
            return this.weeks[this.week];
        },

        firstDayIndex() {
            return Math.max(0, this.currentWeek.findIndex((day) => day.inRange));
        },

        goToWeek(week) {
            this.week = Math.min(this.weeks.length - 1, Math.max(0, week));
            this.mobileDay = this.firstDayIndex();
        },

        value(date, index) {
            return (this.cells[date] ?? EMPTY)[index];
        },

        set(date, index, value) {
            const day = this.currentWeek.find((candidate) => candidate.date === date);

            if (!this.canEdit || !day?.inRange || this.value(date, index) === value) {
                return;
            }

            const current = this.cells[date] ?? EMPTY;
            this.cells[date] = current.slice(0, index) + value + current.slice(index + 1);
            this.dirty.add(date);
        },

        start(event) {
            const cell = event.target.closest('[data-cell]');

            if (!this.canEdit || !cell) {
                return;
            }

            event.preventDefault();
            this.painting = true;
            this.paintValue = NEXT[this.value(cell.dataset.date, Number(cell.dataset.index))];
            this.set(cell.dataset.date, Number(cell.dataset.index), this.paintValue);
        },

        move(event) {
            if (!this.painting) {
                return;
            }

            const cell = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-cell]');

            if (cell) {
                this.set(cell.dataset.date, Number(cell.dataset.index), this.paintValue);
            }
        },

        end() {
            if (this.painting) {
                this.painting = false;
                this.save();
            }
        },

        cycle(date, index) {
            this.set(date, index, NEXT[this.value(date, index)]);
            this.save();
        },

        copyToWeek(date) {
            this.currentWeek.filter((day) => day.inRange && day.date !== date).forEach((day) => {
                if ((this.cells[day.date] ?? EMPTY) !== (this.cells[date] ?? EMPTY)) {
                    this.cells[day.date] = this.cells[date] ?? EMPTY;
                    this.dirty.add(day.date);
                }
            });
            this.save();
        },

        save() {
            if (this.dirty.size === 0) {
                return;
            }

            const days = Object.fromEntries([...this.dirty].map((date) => [date, this.cells[date] ?? EMPTY]));
            this.dirty.clear();
            this.status = 'saving';

            this.$wire.saveDays(days)
                .then(() => { this.status = 'saved'; })
                .catch(() => {
                    Object.keys(days).forEach((date) => this.dirty.add(date));
                    this.status = 'error';
                });
        },
    }));
});
