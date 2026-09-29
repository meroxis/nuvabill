<x-layouts.admin :title="__('Exports')">
    <div class="page-head">
        <div>
            <h1>{{ __('Exports') }}</h1>
            <p>{{ __('CSV files for your accountant or spreadsheet. They open in Excel, LibreOffice and Google Sheets.') }}</p>
        </div>
    </div>

    @include('admin.invoices.nav')

    <form method="GET" action="{{ route('admin.exports.download') }}" class="card" style="display:grid;gap:1.1rem;max-width:720px">
        <div class="card-header" style="margin:0"><h2>{{ __('Download a CSV file') }}</h2></div>
        <fieldset class="field" style="border:0;padding:0;margin:0">
            <legend style="font-weight:600;font-size:.9rem;margin-bottom:6px">{{ __('What to export') }}</legend>
            <div style="display:grid;gap:6px">
                @foreach ($types as $key => $label)
                    <label class="check"><input type="radio" name="type" value="{{ $key }}" @checked(request('type', 'invoices') === $key)> {{ $label }}</label>
                @endforeach
            </div>
        </fieldset>
        <div class="form-grid">
            <x-input name="from" type="date" :label="__('From')" :value="$from" />
            <x-input name="to" type="date" :label="__('To')" :value="$to" />
        </div>
        <p class="help" style="margin:0">{{ __('Invoices by invoice date, payments by the day they were paid, credit notes by their date and clients by sign-up date. Leave both dates empty for everything. Drafts are left out.') }}</p>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><x-icon name="download" />{{ __('Download CSV') }}</button>
        </div>
    </form>
</x-layouts.admin>
