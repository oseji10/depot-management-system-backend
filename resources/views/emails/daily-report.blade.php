@extends('emails.layout')
@php use App\Modules\Sales\Reports\ReportFormat as F; $t = $r['today']; $y = $r['yesterday']; @endphp
@section('content')
  <p style="margin:0 0 4px;font-size:13px;color:#6b5a5a;">{{ $r['date_label'] }}</p>
  <h1 style="margin:0 0 16px;font-size:20px;">Daily sales report · {{ $r['store']['name'] }}</h1>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    @foreach ([
      ['Total sales', F::money($t['sales']), F::delta($t['sales'], $y['sales'])],
      ['Orders', F::number($t['orders']), F::delta($t['orders'], $y['orders'], false)],
      ['Money received', F::money($t['collected']), F::delta($t['collected'], $y['collected'])],
      ['Gross profit', F::money($t['profit']), F::delta($t['profit'], $y['profit'])],
      ['Sold on credit', F::money($t['credit']), F::delta($t['credit'], $y['credit'])],
      ['Customers who bought', F::number($r['customers']['buyers']), F::delta($r['customers']['buyers'], $y['customers'], false)],
    ] as [$label, $value, $d])
      <tr>
        <td style="padding:7px 0;border-bottom:1px solid #f1e8e8;color:#6b5a5a;">{{ $label }}</td>
        <td style="padding:7px 0;border-bottom:1px solid #f1e8e8;text-align:right;font-weight:bold;">{{ $value }}</td>
        <td style="padding:7px 0 7px 10px;border-bottom:1px solid #f1e8e8;text-align:right;font-size:12px;color:{{ $d['dir'] === 'down' ? '#c23a3a' : ($d['dir'] === 'up' ? '#127a3a' : '#6b5a5a') }};">{{ $d['text'] }}</td>
      </tr>
    @endforeach
  </table>
  @if (count($r['products']))
    <p style="margin:18px 0 6px;font-weight:bold;">Top products</p>
    @foreach (array_slice($r['products'], 0, 5) as $p)
      <p style="margin:0 0 3px;font-size:14px;">{{ F::number($p['qty']) }} × {{ $p['unit'] }} {{ $p['name'] }} <span style="color:#6b5a5a;">· {{ F::money($p['revenue']) }}</span></p>
    @endforeach
  @endif
  <p style="margin:18px 0 0;font-size:13px;color:#6b5a5a;">
    {{ $hasPdf ? 'The full report, with payments, products, staff, customers, expenses and low stock, is attached as a PDF.' : 'Open the app for the full breakdown.' }}
  </p>
@endsection
@section('footer')
  You get this because you are set to receive the daily sales report for {{ $r['store']['name'] }}.<br>
@endsection
