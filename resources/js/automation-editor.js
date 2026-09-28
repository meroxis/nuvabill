/**
 * The automation builder: one trigger, a list of conditions and a list of steps, sent to the server
 * as JSON in a hidden "definition" field. The server checks everything again (App\Automations\Registry::clean).
 *
 * Usage: <form x-data="automationEditor(@js($editor))">, where $editor is {defs, value}.
 */
let nextId = 1;

function defaultFor(field) {
    if (field.type === 'condition') {
        return { field: '', operator: '', value: '' };
    }

    if (field.type === 'checkbox') {
        return field.default ?? false;
    }

    return field.default ?? '';
}

export default function automationEditor({ defs, value }) {
    return {
        defs,
        trigger: value.trigger || '',
        days: value.days ?? 7,
        conditions: (value.conditions || []).map((condition) => ({ ...condition, id: nextId++ })),
        steps: (value.steps || []).map((step) => ({ type: step.type, config: { ...step.config }, id: nextId++ })),
        adding: '',
        open: null,

        init() {
            this.open = this.steps.length > 0 ? this.steps[0].id : null;
        },

        get subject() {
            return this.defs.triggers[this.trigger]?.subject ?? null;
        },

        get timed() {
            return Boolean(this.defs.triggers[this.trigger]?.timed);
        },

        get unit() {
            return this.defs.triggers[this.trigger]?.unit ?? '';
        },

        fits(item) {
            return !item.subjects || item.subjects.length === 0 || item.subjects.includes(this.subject);
        },

        /** Condition fields that can be checked for the chosen trigger, as [key, definition]. */
        conditionFields() {
            return Object.entries(this.defs.conditions).filter(([, field]) => this.fits(field));
        },

        /** Step types for the chosen trigger, grouped: [[group, [[key, definition], …]], …]. */
        stepGroups() {
            const groups = {};

            Object.entries(this.defs.steps).forEach(([key, step]) => {
                if (this.fits(step)) {
                    (groups[step.group] ??= []).push([key, step]);
                }
            });

            return Object.entries(groups);
        },

        fieldOf(condition) {
            return this.defs.conditions[condition.field] ?? null;
        },

        choose(condition, key) {
            const field = this.defs.conditions[key];
            condition.field = key;
            condition.operator = field ? Object.keys(field.operators)[0] : '';
            condition.value = field && field.type === 'choice' ? (Object.keys(field.options)[0] ?? '') : '';
        },

        addCondition(list = this.conditions) {
            const first = this.conditionFields()[0];
            const condition = { field: '', operator: '', value: '', id: nextId++ };

            if (first) {
                this.choose(condition, first[0]);
            }

            list.push(condition);
        },

        removeCondition(index) {
            this.conditions.splice(index, 1);
        },

        stepDef(step) {
            return this.defs.steps[step.type] ?? { label: step.type, fields: [] };
        },

        addStep() {
            const def = this.defs.steps[this.adding];

            if (!def) {
                return;
            }

            const config = {};
            def.fields.forEach((field) => {
                config[field.name] = defaultFor(field);
            });

            if (def.fields.some((field) => field.type === 'condition')) {
                const first = this.conditionFields()[0];

                if (first) {
                    this.choose(config.condition, first[0]);
                }
            }

            const step = { type: this.adding, config, id: nextId++ };
            this.steps.push(step);
            this.open = step.id;
            this.adding = '';
        },

        removeStep(index) {
            this.steps.splice(index, 1);
        },

        moveStep(index, by) {
            const target = index + by;

            if (target < 0 || target >= this.steps.length) {
                return;
            }

            const [step] = this.steps.splice(index, 1);
            this.steps.splice(target, 0, step);
        },

        /** A short line under each step's name, from its first filled text setting. */
        preview(step) {
            const def = this.stepDef(step);
            const field = def.fields.find((item) => ['text', 'url'].includes(item.type) && step.config[item.name]);

            if (step.type === 'only_if') {
                const condition = step.config.condition ?? {};
                const check = this.defs.conditions[condition.field];

                if (!check) {
                    return '';
                }

                const shown = check.type === 'choice' ? (check.options[condition.value] ?? condition.value) : condition.value;

                return `${check.label} ${check.operators[condition.operator] ?? ''} ${shown}`;
            }

            if (step.type === 'wait') {
                const unit = def.fields.find((item) => item.name === 'unit');

                return `${step.config.amount} ${unit?.options?.[step.config.unit] ?? step.config.unit}`;
            }

            return field ? String(step.config[field.name]) : '';
        },

        definition() {
            return JSON.stringify({
                trigger: this.trigger,
                days: this.timed ? Number(this.days) : null,
                conditions: this.conditions.map(({ field, operator, value }) => ({ field, operator, value })),
                steps: this.steps.map(({ type, config }) => ({ type, config })),
            });
        },
    };
}
