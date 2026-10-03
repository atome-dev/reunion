const EMPTY = '0'.repeat(28);
const NEXT = { 0: 'p', p: 'd', d: '0' };
const LONG_PRESS_MS = 300;
const MOVE_TOLERANCE = 8;

document.addEventListener('alpine:init', () => {
    window.Alpine.data('availabilityGrid', ({ weeks, cells, canEdit, labels, times }) => ({
        weeks,
        cells: { ...cells },
        canEdit,
        labels,
        times,
        week: Math.max(0, weeks.findIndex((week) => week.some((day) => day.inRange))),
        mobileDay: 0,
        focusDate: null,
        focusIndex: 0,
        painting: false,
        paintValue: '0',
        pressTimer: null,
        pressCell: null,
        pressOrigin: null,
        dirty: new Set(),
        status: 'idle',

        init() {
            this.mobileDay = this.firstDayIndex();
            this.focusDate = this.currentWeek[this.mobileDay]?.date ?? null;
            // Only a non-passive listener can stop the page from scrolling while a long-press paint is under way.
            this.$el.addEventListener('touchmove', (event) => {
                if (this.painting) {
                    event.preventDefault();
                }
            }, { passive: false });
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
            this.focusDate = this.currentWeek[this.mobileDay]?.date ?? null;
        },

        value(date, index) {
            return (this.cells[date] ?? EMPTY)[index];
        },

        label(day, index) {
            return `${day.label} ${this.times[index]} — ${this.labels[this.value(day.date, index)]}`;
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

        beginPaint(date, index) {
            this.painting = true;
            this.paintValue = NEXT[this.value(date, index)];
            this.set(date, index, this.paintValue);
        },

        start(event) {
            const cell = event.target.closest('[data-cell]');

            if (!this.canEdit || !cell) {
                return;
            }

            const { date } = cell.dataset;
            const index = Number(cell.dataset.index);

            if (event.pointerType === 'touch') {
                // A touch may be a scroll: wait for a long press before painting, a quick tap cycles the cell.
                this.pressCell = { date, index };
                this.pressOrigin = { x: event.clientX, y: event.clientY };
                this.pressTimer = setTimeout(() => {
                    this.pressTimer = null;
                    this.beginPaint(date, index);
                    navigator.vibrate?.(10);
                }, LONG_PRESS_MS);

                return;
            }

            event.preventDefault();
            this.beginPaint(date, index);
        },

        move(event) {
            if (this.pressTimer) {
                const moved = Math.hypot(event.clientX - this.pressOrigin.x, event.clientY - this.pressOrigin.y);

                if (moved > MOVE_TOLERANCE) {
                    this.clearPress();
                }

                return;
            }

            if (!this.painting) {
                return;
            }

            const cell = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-cell]');

            if (cell) {
                this.set(cell.dataset.date, Number(cell.dataset.index), this.paintValue);
            }
        },

        clearPress() {
            clearTimeout(this.pressTimer);
            this.pressTimer = null;
            this.pressCell = null;
        },

        end() {
            if (this.pressTimer && this.pressCell) {
                const { date, index } = this.pressCell;

                this.clearPress();
                this.cycle(date, index);

                return;
            }

            if (this.painting) {
                this.painting = false;
                this.save();
            }
        },

        cancel() {
            this.clearPress();
            this.end();
        },

        cycle(date, index) {
            this.set(date, index, NEXT[this.value(date, index)]);
            this.save();
        },

        key(event, date, index) {
            const moves = { ArrowUp: [0, -1], ArrowDown: [0, 1], ArrowLeft: [-1, 0], ArrowRight: [1, 0] };

            if (event.key === ' ' || event.key === 'Enter') {
                event.preventDefault();
                this.cycle(date, index);

                return;
            }

            if (!moves[event.key]) {
                return;
            }

            event.preventDefault();

            // Start from the tracked position, not the event target: focus lands one tick after a fast key repeat.
            const [dayStep, cellStep] = moves[event.key];
            let nextDay = this.currentWeek.findIndex((day) => day.date === this.focusDate);
            const current = this.focusIndex;

            do {
                nextDay += dayStep;
            } while (dayStep !== 0 && this.currentWeek[nextDay] && !this.currentWeek[nextDay].inRange);

            const nextIndex = current + cellStep;

            if (!this.currentWeek[nextDay]?.inRange || nextIndex < 0 || nextIndex >= EMPTY.length) {
                return;
            }

            this.mobileDay = nextDay;
            this.focusDate = this.currentWeek[nextDay].date;
            this.focusIndex = nextIndex;
            this.$nextTick(() => {
                this.$root.querySelector(`[data-cell][data-date="${this.focusDate}"][data-index="${nextIndex}"]`)?.focus();
            });
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
            if (this.dirty.size === 0 && this.status !== 'error') {
                return;
            }

            const days = Object.fromEntries([...this.dirty].map((date) => [date, this.cells[date] ?? EMPTY]));
            this.dirty.clear();
            this.status = 'saving';

            const fail = () => {
                Object.keys(days).forEach((date) => this.dirty.add(date));
                this.status = 'error';
            };

            this.$wire.saveDays(days)
                .then((saved) => (saved === true ? (this.status = 'saved') : fail()))
                .catch(fail);
        },
    }));
});
