<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Invoice') }} {{ $invoice->displayNumber() }}</title>
    <style>
        @page { margin: 36px 42px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #0e1a1c; line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; }
        .top td { vertical-align: top; }
        .company { font-size: 18px; font-weight: bold; }
        .title { font-size: 26px; font-weight: bold; color: {{ $accent }}; text-align: right; }
        .muted { color: #56676a; }
        .status { display: inline-block; padding: 3px 10px; border-radius: 10px; font-weight: bold; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; }
        .status-paid { background: #dff3e8; color: #1e8e55; }
        .status-unpaid { background: #fbefd5; color: #9a640a; }
        .status-other { background: #eaf0f0; color: #56676a; }
        .items { margin-top: 26px; }
        .items th { text-align: left; font-size: 9px; letter-spacing: 1px; text-transform: uppercase; color: #7d8f90; border-bottom: 1.5px solid #0e1a1c; padding: 6px 4px; }
        .items td { padding: 9px 4px; border-bottom: 1px solid #d9e2e2; }
        .right { text-align: right; }
        .totals { width: 45%; margin-left: 55%; margin-top: 14px; }
        .totals td { padding: 4px; }
        .grand td { font-size: 14px; font-weight: bold; border-top: 1.5px solid #0e1a1c; padding-top: 8px; }
        .footer { position: fixed; bottom: -10px; left: 0; right: 0; font-size: 9px; color: #7d8f90; text-align: center; }
    </style>
</head>
<body>
    <table class="top">
        <tr>
            <td>
                <div class="company">{{ $company['name'] }}</div>
                @if ($company['address'])<div class="muted" style="white-space: pre-line">{{ $company['address'] }}</div>@endif
                <div class="muted">{{ $company['email'] }}@if ($company['phone']) · {{ $company['phone'] }}@endif</div>
                @if ($company['tax_id'])<div class="muted">{{ setting('tax.id_label') }}: {{ $company['tax_id'] }}</div>@endif
            </td>
            <td class="right">
                <div class="title">{{ __('INVOICE') }}</div>
                <div><b>{{ $invoice->displayNumber() }}</b></div>
                @php $statusClass = match ($invoice->status->value) { 'paid' => 'status-paid', 'unpaid' => 'status-unpaid', default => 'status-other' }; @endphp
                <div style="margin-top:6px"><span class="status {{ $statusClass }}">{{ $invoice->status->label() }}</span></div>
            </td>
        </tr>
    </table>

    <table class="top" style="margin-top: 28px">
        <tr>
            <td style="width: 55%">
                <div class="muted" style="font-size: 9px; letter-spacing: 1px; text-transform: uppercase">{{ __('Billed to') }}</div>
                <div><b>{{ $invoice->client->company_name ?: $invoice->client->name }}</b></div>
                @if ($invoice->client->company_name)<div>{{ $invoice->client->name }}</div>@endif
                <div>{{ collect([$invoice->client->address_1, $invoice->client->address_2])->filter()->implode(', ') }}</div>
                <div>{{ collect([trim($invoice->client->postcode.' '.$invoice->client->city), $invoice->client->state, \App\Support\Countries::name($invoice->client->country)])->filter()->implode(', ') }}</div>
                <div class="muted">{{ $invoice->client->email }}</div>
                @if ($invoice->client->tax_id)<div class="muted">{{ setting('tax.id_label') }}: {{ $invoice->client->tax_id }}</div>@endif
            </td>
            <td class="right">
                <table>
                    <tr><td class="muted right">{{ __('Invoice date') }}</td><td class="right" style="width: 45%">{{ $invoice->issued_at->format('d M Y') }}</td></tr>
                    <tr><td class="muted right">{{ __('Due date') }}</td><td class="right">{{ $invoice->due_at->format('d M Y') }}</td></tr>
                    @if ($invoice->paid_at)
                        <tr><td class="muted right">{{ __('Paid on') }}</td><td class="right">{{ $invoice->paid_at->format('d M Y') }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead><tr><th>{{ __('Description') }}</th><th class="right" style="width: 22%">{{ __('Amount') }}</th></tr></thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr><td>{{ $item->description }}</td><td class="right">{{ money($item->amount, $invoice->currency) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td class="muted">{{ __('Subtotal') }}</td><td class="right">{{ money($invoice->subtotal, $invoice->currency) }}</td></tr>
        @if ($invoice->tax > 0)
            <tr><td class="muted">{{ $invoice->taxLabel() }}</td><td class="right">{{ money($invoice->tax, $invoice->currency) }}</td></tr>
        @endif
        <tr class="grand"><td>{{ __('Total') }}</td><td class="right">{{ money($invoice->total, $invoice->currency) }}</td></tr>
        @if ($invoice->amount_paid > 0)
            <tr><td class="muted">{{ __('Paid') }}</td><td class="right">{{ money($invoice->amount_paid, $invoice->currency) }}</td></tr>
            <tr><td><b>{{ __('Balance due') }}</b></td><td class="right"><b>{{ money($invoice->balance(), $invoice->currency) }}</b></td></tr>
        @endif
    </table>

    @if ($invoice->notes)
        <p style="margin-top: 30px; white-space: pre-line">{{ $invoice->notes }}</p>
    @endif

    @if ($showPoweredBy)
        <div class="footer">{{ __('Invoice generated by :product', ['product' => \App\Support\Branding::PRODUCT_NAME]) }} · {{ parse_url(\App\Support\Branding::PRODUCT_URL, PHP_URL_HOST) }}</div>
    @endif
</body>
</html>
