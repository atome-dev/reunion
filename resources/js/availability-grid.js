const EMPTY = '0'.repeat(28);
const NEXT = { 0: 'p', p: 'd', d: '0' };
const LONG_PRESS_MS = 300;
const MOVE_TOLERANCE = 8;

document.addEventListener('alpine:init', () => {
    // Saved cells come through data-cells, read once: keeping them out of x-data stops a Livewire
    // re-render from re-initialising the grid (which sent the user back to the first week).
    window.Alpine.data('availabilityGrid', ({ weeks, canEdit, labels, times }) => ({
        weeks,
        cells: {},
        busy: {},
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
        lastPainted: null,
        dirty: new Set(),
        status: 'idle',

        init() {
            this.cells = { ...JSON.parse(this.$el.dataset.cells || '{}') };
            this.busy = JSON.parse(this.$el.dataset.busy || '{}');
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

        isBusy(date, index) {
            return Boolean(this.busy[date]?.[index]);
        },

        busyTitle(date, index) {
            const meeting = this.busy[date]?.[index];

            return meeting ? this.labels.busyTitle.replace(/:(title|group)/g, (_, key) => meeting[key]) : null;
        },

        label(day, index) {
            if (this.isBusy(day.date, index)) {
                return `${day.label} ${this.times[index]} — ${this.busyTitle(day.date, index)}`;
            }

            return `${day.label} ${this.times[index]} — ${this.labels[this.value(day.date, index)]}`;
        },

        set(date, index, value) {
            const day = this.currentWeek.find((candidate) => candidate.date === date);

            if (!this.canEdit || !day?.inRange || this.isBusy(date, index) || this.value(date, index) === value) {
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
            this.lastPainted = { date, index };
        },

        // Pointer events are sparse when the pointer moves fast: paint every cell on the way from the last one.
        paintTo(date, index) {
            const days = this.currentWeek.map((day) => day.date);
            const from = this.lastPainted ?? { date, index };
            const fromDay = days.indexOf(from.date);
            const toDay = days.indexOf(date);

            if (fromDay === -1 || toDay === -1) {
                this.set(date, index, this.paintValue);
            } else {
                const steps = Math.max(Math.abs(toDay - fromDay), Math.abs(index - from.index), 1);

                for (let step = 1; step <= steps; step++) {
                    const day = Math.round(fromDay + ((toDay - fromDay) * step) / steps);
                    const cell = Math.round(from.index + ((index - from.index) * step) / steps);
                    this.set(days[day], cell, this.paintValue);
                }
            }

            this.lastPainted = { date, index };
        },

        start(event) {
            const cell = event.target.closest('[data-cell]');

            if (!this.canEdit || !cell) {
                return;
            }

            const { date } = cell.dataset;
            const index = Number(cell.dataset.index);

            if (this.isBusy(date, index)) {
                return;
            }

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
                this.paintTo(cell.dataset.date, Number(cell.dataset.index));
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

        // Source values copied onto the target, except where either day has a meeting (the target keeps its own).
        mergeInto(sourceDate, targetDate) {
            const source = this.cells[sourceDate] ?? EMPTY;
            const target = this.cells[targetDate] ?? EMPTY;

            return [...target].map((value, index) => (this.isBusy(targetDate, index) || this.isBusy(sourceDate, index) ? value : source[index])).join('');
        },

        copyToWeek(date) {
            this.currentWeek.filter((day) => day.inRange && day.date !== date).forEach((day) => {
                const merged = this.mergeInto(date, day.date);

                if ((this.cells[day.date] ?? EMPTY) !== merged) {
                    this.cells[day.date] = merged;
                    this.dirty.add(day.date);
                }
            });
            this.save();
        },

        // Copy the shown week onto the next one (editable days only), then show it.
        copyWeekToNext() {
            const next = this.weeks[this.week + 1];

            if (!this.canEdit || !next) {
                return;
            }

            this.currentWeek.forEach((day, index) => {
                const target = next[index];

                if (!target.inRange || (!day.inRange && !(day.date in this.cells))) {
                    return;
                }

                const merged = this.mergeInto(day.date, target.date);

                if ((this.cells[target.date] ?? EMPTY) !== merged) {
                    this.cells[target.date] = merged;
                    this.dirty.add(target.date);
                }
            });

            this.save();
            this.goToWeek(this.week + 1);
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

            // The server keeps the stored value under a meeting: pick up meetings confirmed since the page opened.
            const refreshBusy = () => this.$nextTick(() => {
                this.busy = JSON.parse(this.$el.dataset.busy || '{}');
                const stored = JSON.parse(this.$el.dataset.cells || '{}');

                Object.keys(days).filter((date) => !this.dirty.has(date)).forEach((date) => {
                    const current = this.cells[date] ?? EMPTY;
                    const kept = stored[date] ?? EMPTY;
                    const merged = [...current].map((value, index) => (this.isBusy(date, index) ? kept[index] : value)).join('');

                    if (merged !== current) {
                        this.cells[date] = merged;
                    }
                });
            });

            this.$wire.saveDays(days)
                .then((saved) => {
                    if (saved !== true) {
                        return fail();
                    }

                    this.status = 'saved';
                    refreshBusy();
                })
                .catch(fail);
        },
    }));
});
