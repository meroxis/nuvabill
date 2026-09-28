{{-- One condition row. $model is the Alpine expression of the condition object, for example "condition". --}}
<div class="automation-condition">
    <select class="select" aria-label="{{ __('What to check') }}" @change="choose({{ $model }}, $event.target.value)">
        <template x-for="[key, field] in conditionFields()" :key="key">
            <option :value="key" x-text="field.label" :selected="key === {{ $model }}.field"></option>
        </template>
    </select>
    <select class="select" aria-label="{{ __('How to compare') }}" x-model="{{ $model }}.operator">
        <template x-for="[operator, label] in Object.entries(fieldOf({{ $model }})?.operators ?? {})" :key="operator">
            <option :value="operator" x-text="label" :selected="operator === {{ $model }}.operator"></option>
        </template>
    </select>
    <template x-if="fieldOf({{ $model }})?.type === 'choice'">
        <select class="select" aria-label="{{ __('Value') }}" x-model="{{ $model }}.value">
            <template x-for="[value, label] in Object.entries(fieldOf({{ $model }}).options)" :key="value">
                <option :value="value" x-text="label" :selected="value === String({{ $model }}.value)"></option>
            </template>
        </select>
    </template>
    <template x-if="['money', 'number'].includes(fieldOf({{ $model }})?.type)">
        <input class="input num" type="number" min="0" :step="fieldOf({{ $model }}).type === 'money' ? '0.01' : '1'" aria-label="{{ __('Value') }}" x-model="{{ $model }}.value">
    </template>
    <template x-if="fieldOf({{ $model }})?.type === 'tag'">
        <input class="input" type="text" maxlength="30" placeholder="VIP" aria-label="{{ __('Tag') }}" x-model="{{ $model }}.value">
    </template>
</div>
