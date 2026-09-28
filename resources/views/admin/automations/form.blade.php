@php
    $editing = $automation->exists;
    $groups = collect($registry->triggers())->groupBy(fn ($trigger) => __($trigger->group));
    $preview = session('preview');
@endphp
<x-layouts.admin :title="$editing ? $automation->name : __('New automation')">
    <div class="page-head">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.automations.index') }}">{{ __('Automations') }}</a></p>
            <h1 style="margin-top:.2rem">{{ $editing ? $automation->name : __('New automation') }}</h1>
            @if ($editing)
                <p>@if ($automation->is_active)<x-pill tone="good">{{ __('On') }}</x-pill>@else<x-pill>{{ __('Off') }}</x-pill> {{ __('Nothing runs until you switch it on.') }}@endif</p>
            @else
                <p>{{ __('It stays off until you switch it on, so you can try it first.') }}</p>
            @endif
        </div>
    </div>

    @if ($errors->has('definition'))
        <div class="flash" data-tone="crit" role="alert">
            <ul style="margin:0;padding-inline-start:1.1rem">@foreach ($errors->get('definition') as $message)<li>{{ $message }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="automation-layout">
        <form method="POST" action="{{ $editing ? route('admin.automations.update', $automation) : route('admin.automations.store') }}" x-data="automationEditor(@js($editor))" style="display:grid;gap:14px;min-width:0">
            @csrf
            @if ($editing) @method('PUT') @endif
            <input type="hidden" name="definition" :value="definition()">
            <input type="hidden" name="template" value="{{ $automation->template }}">

            <section class="card">
                <x-input name="name" :label="__('Name')" :value="$automation->name" required maxlength="120" :help="__('Only staff see it, for example “Late fee after 7 days”.')" />
            </section>

            <div class="automation-label">{{ __('When') }}</div>
            <section class="card" style="display:grid;gap:.9rem">
                <div class="field">
                    <label for="f-trigger">{{ __('What starts it') }}</label>
                    <select id="f-trigger" class="select" x-model="trigger">
                        @foreach ($groups as $group => $triggers)
                            <optgroup label="{{ $group }}">
                                @foreach ($triggers as $trigger)
                                    <option value="{{ $trigger->key }}" @selected($trigger->key === $automation->trigger)>{{ __($trigger->label) }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <template x-if="timed">
                    <div class="field">
                        <label for="f-days">{{ __('When exactly') }}</label>
                        <div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
                            <input id="f-days" class="input num" type="number" min="0" max="365" style="max-width:110px" x-model="days">
                            <span x-text="unit"></span>
                        </div>
                        <p class="help">{{ __('Checked once a day. Nothing older than a week is picked up when you switch it on.') }}</p>
                    </div>
                </template>
            </section>

            <div class="automation-label">{{ __('Only if') }}</div>
            <section class="card" style="display:grid;gap:.7rem">
                <template x-for="(condition, index) in conditions" :key="condition.id">
                    <div style="display:flex;gap:.6rem;align-items:flex-start">
                        <div style="flex:1;min-width:0">@include('admin.automations.partials.condition', ['model' => 'condition'])</div>
                        <button type="button" class="btn btn-sm btn-ghost" @click="removeCondition(index)" aria-label="{{ __('Remove this condition') }}"><x-icon name="x" /></button>
                    </div>
                </template>
                <p class="muted" style="margin:0;font-size:.88rem" x-show="conditions.length === 0">{{ __('No conditions: it runs every time.') }}</p>
                <div><button type="button" class="btn btn-sm" @click="addCondition()"><x-icon name="plus" />{{ __('Add a condition') }}</button></div>
            </section>

            <div class="automation-label">{{ __('Do') }}</div>
            <div style="display:grid;gap:10px">
                <template x-for="(step, index) in steps" :key="step.id">
                    <article class="card automation-step" :class="open === step.id && 'is-open'">
                        <div class="automation-step-head">
                            <span class="automation-num" x-text="index + 1"></span>
                            <button type="button" class="automation-step-title" @click="open = open === step.id ? null : step.id" :aria-expanded="open === step.id">
                                <b x-text="stepDef(step).label"></b>
                                <span class="muted" x-text="preview(step)"></span>
                                <span class="pill" data-tone="warn" x-show="! fits(stepDef(step))">{{ __('Does not work with this trigger') }}</span>
                            </button>
                            <div style="display:flex;gap:4px">
                                <button type="button" class="btn btn-sm btn-ghost" @click="moveStep(index, -1)" :disabled="index === 0" aria-label="{{ __('Move up') }}">↑</button>
                                <button type="button" class="btn btn-sm btn-ghost" @click="moveStep(index, 1)" :disabled="index === steps.length - 1" aria-label="{{ __('Move down') }}">↓</button>
                                <button type="button" class="btn btn-sm btn-ghost" @click="removeStep(index)" aria-label="{{ __('Remove this step') }}"><x-icon name="trash" /></button>
                            </div>
                        </div>
                        <div class="form-grid" x-show="open === step.id" style="margin-top:1rem">
                            <template x-for="field in stepDef(step).fields" :key="field.name">
                                <div class="field" :class="['textarea', 'condition'].includes(field.type) && 'span-2'">
                                    <template x-if="field.type !== 'checkbox'">
                                        <label :for="'s' + step.id + '-' + field.name" x-text="field.label"></label>
                                    </template>
                                    <template x-if="field.type === 'text' || field.type === 'url'">
                                        <input class="input" :id="'s' + step.id + '-' + field.name" :type="field.type === 'url' ? 'url' : 'text'" :maxlength="field.max || 500" x-model="step.config[field.name]">
                                    </template>
                                    <template x-if="field.type === 'textarea'">
                                        <textarea class="textarea" rows="7" :id="'s' + step.id + '-' + field.name" :maxlength="field.max || 5000" x-model="step.config[field.name]"></textarea>
                                    </template>
                                    <template x-if="field.type === 'number' || field.type === 'money'">
                                        <input class="input num" type="number" :id="'s' + step.id + '-' + field.name" :min="field.min ?? 0" :max="field.max ?? null" step="any" x-model="step.config[field.name]">
                                    </template>
                                    <template x-if="field.type === 'select'">
                                        <select class="select" :id="'s' + step.id + '-' + field.name" x-model="step.config[field.name]">
                                            <template x-for="[value, label] in Object.entries(field.options ?? {})" :key="value">
                                                <option :value="value" x-text="label" :selected="value === String(step.config[field.name])"></option>
                                            </template>
                                        </select>
                                    </template>
                                    <template x-if="field.type === 'checkbox'">
                                        <label class="check"><input type="checkbox" x-model="step.config[field.name]"> <span x-text="field.label"></span></label>
                                    </template>
                                    <template x-if="field.type === 'condition'">
                                        <div>@include('admin.automations.partials.condition', ['model' => 'step.config[field.name]'])</div>
                                    </template>
                                    <p class="help" x-show="field.help" x-text="field.help"></p>
                                </div>
                            </template>
                            <p class="muted span-2" style="margin:0;font-size:.88rem" x-show="stepDef(step).fields.length === 0">{{ __('Nothing to set for this step.') }}</p>
                        </div>
                    </article>
                </template>
                <p class="muted" style="margin:0;font-size:.88rem" x-show="steps.length === 0">{{ __('Add the first step below.') }}</p>
                <div class="card" style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
                    <label for="f-add-step" style="font-weight:600">{{ __('Add a step') }}</label>
                    <select id="f-add-step" class="select" style="max-width:320px" x-model="adding">
                        <option value="">{{ __('Choose…') }}</option>
                        <template x-for="[group, items] in stepGroups()" :key="group">
                            <optgroup :label="group">
                                <template x-for="[key, item] in items" :key="key">
                                    <option :value="key" x-text="item.label"></option>
                                </template>
                            </optgroup>
                        </template>
                    </select>
                    <button type="button" class="btn" @click="addStep()" :disabled="! adding"><x-icon name="plus" />{{ __('Add') }}</button>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn" type="submit">{{ __('Save') }}</button>
                @unless ($editing && $automation->is_active)
                    <button class="btn btn-primary" type="submit" name="activate" value="1">{{ __('Save and switch on') }}</button>
                @endunless
                <a class="btn btn-ghost" href="{{ route('admin.automations.index') }}">{{ __('Cancel') }}</a>
            </div>
        </form>

        <div style="display:grid;gap:14px;align-content:start;min-width:0">
            @if ($editing)
                <form method="POST" action="{{ route('admin.automations.test', $automation) }}" class="card" style="display:grid;gap:.8rem">
                    @csrf
                    <div>
                        <h2 style="font-size:1.05rem">{{ __('Try it first') }}</h2>
                        <p class="muted" style="margin:.3rem 0 0;font-size:.88rem">{{ __('See what the saved automation would do. Nothing is changed and nothing is sent.') }}</p>
                    </div>
                    <x-input name="subject" :label="__('Test with')" :value="old('subject')" :help="$subjectHint" required />
                    <div><button class="btn" type="submit">{{ __('Test') }}</button></div>
                    @if (is_array($preview))
                        <div class="automation-preview">
                            <b>{{ $preview['subject'] }}</b>
                            <span class="muted">{{ $preview['trigger'] }}</span>
                            @foreach ($preview['conditions'] as $condition)
                                <span><x-pill :tone="$condition['passed'] ? 'good' : 'warn'">{{ $condition['passed'] ? __('Yes') : __('No') }}</x-pill> {{ $condition['text'] }}</span>
                            @endforeach
                            @if ($preview['matches'])
                                <x-pill tone="good">{{ __('Would run') }}</x-pill>
                                @foreach ($preview['lines'] as $line)<span>{{ $line }}</span>@endforeach
                            @else
                                <x-pill tone="warn">{{ __('Would not run: a condition is not met') }}</x-pill>
                            @endif
                        </div>
                    @endif
                </form>

                <section class="card card-flush">
                    <div class="card-header"><h2>{{ __('Runs') }}</h2><a href="{{ route('admin.automations.runs', ['automation' => $automation->id]) }}" style="font-size:.88rem">{{ __('All runs') }}</a></div>
                    @forelse ($runs as $run)
                        @include('admin.automations.partials.run', ['run' => $run, 'showAutomation' => false])
                    @empty
                        <p class="muted" style="margin:0;padding:0 1.1rem 1.1rem;font-size:.88rem">{{ __('No runs yet.') }}</p>
                    @endforelse
                </section>

                <form method="POST" action="{{ route('admin.automations.destroy', $automation) }}" data-confirm="{{ __('Delete this automation? Runs that are waiting stop.') }}">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-danger btn-sm" type="submit"><x-icon name="trash" />{{ __('Delete automation') }}</button>
                </form>
            @else
                <section class="card" style="display:grid;gap:.5rem">
                    <h2 style="font-size:1.05rem">{{ __('How it works') }}</h2>
                    <p class="muted" style="margin:0;font-size:.9rem">{{ __('Choose what starts it, add conditions if it should only run sometimes, then the steps. Save it, try it on a real invoice or client, and switch it on.') }}</p>
                    <p class="muted" style="margin:0;font-size:.9rem">{{ __('A wait pauses the run. When it goes on, the run stops by itself if what started it is no longer true, for example when the invoice was paid.') }}</p>
                </section>
            @endif
        </div>
    </div>
</x-layouts.admin>
