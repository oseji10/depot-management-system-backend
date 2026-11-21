@php
  use App\Modules\Sales\Reports\ReportFormat as F;
  $t = $r['today']; $y = $r['yesterday']; $w = $r['last_week'];
  $max = max(1, collect($r['trend'])->max('total'));
  $kpis = [
    ['Total sales', F::money($t['sales']), F::delta($t['sales'], $y['sales']), 'Last '.\Carbon\Carbon::parse($r['date'])->subWeek()->format('l').': '.F::money($w['sales'])],
    ['Orders', F::number($t['orders']), F::delta($t['orders'], $y['orders'], false), 'Last week: '.F::number($w['orders'])],
    ['Average order', F::money($t['average']), F::delta($t['average'], $y['average']), 'Last week: '.F::money($w['average'])],
    ['Gross profit', F::money($t['profit']), F::delta($t['profit'], $y['profit']), 'Margin '.F::number($t['margin']).'%'],
    ['Money received', F::money($t['collected']), F::delta($t['collected'], $y['collected']), 'Incl. '.F::money($r['payments']['debt_collected']).' old debts'],
    ['Sold on credit', F::money($t['credit']), F::delta($t['credit'], $y['credit']), 'Still owed today'],
    ['Items sold', F::number($t['items']), F::delta($t['items'], $y['items'], false), 'Counted in single units'],
    ['Discounts given', F::money($t['discount']), F::delta($t['discount'], $y['discount']), $t['deposits'] ? 'Deposits: '.F::money($t['deposits']) : 'Below list price'],
  ];
@endphp
<!doctype html>
<html><head><meta charset="utf-8"><title>Daily sales report {{ $r['date'] }}</title>
<style>
  @page { margin: 26px 28px 40px 28px; }
  * { font-family: "DejaVu Sans", sans-serif; }
  body { color: #261919; font-size: 10px; line-height: 1.35; }
  h2 { font-size: 12px; margin: 18px 0 6px; color: #261919; border-bottom: 2px solid #f40009; padding-bottom: 3px; }
  table { border-collapse: collapse; width: 100%; }
  .muted { color: #7a6868; }
  .up { color: #127a3a; } .down { color: #c23a3a; } .same { color: #7a6868; }
  .head td { vertical-align: middle; }
  .brand { font-size: 17px; font-weight: bold; }
  .title { background: #f40009; color: #fff; padding: 10px 12px; margin-top: 10px; }
  .title .big { font-size: 15px; font-weight: bold; }
  .kpi td.card { width: 25%; padding: 4px; vertical-align: top; }
  .card .box { border: 1px solid #eadfdf; border-radius: 6px; padding: 8px; height: 62px; }
  .card .label { font-size: 8.5px; text-transform: uppercase; letter-spacing: .4px; color: #7a6868; }
  .card .value { font-size: 15px; font-weight: bold; margin: 3px 0 2px; }
  .card .delta { font-size: 8.5px; }
  .card .note { font-size: 8px; color: #7a6868; }
  .grid th { text-align: left; font-size: 8.5px; text-transform: uppercase; letter-spacing: .3px; color: #7a6868; background: #fff3f3; padding: 5px 6px; border-bottom: 1px solid #eadfdf; }
  .grid td { padding: 5px 6px; border-bottom: 1px solid #f1e8e8; }
  .grid tr.total td { font-weight: bold; border-top: 1px solid #261919; border-bottom: 0; }
  .r { text-align: right; }
  .bar { background: #f40009; }
  .barbg { background: #f6eded; }
  .two td.col { width: 50%; vertical-align: top; }
  .two td.col.left { padding-right: 8px; } .two td.col.right { padding-left: 8px; }
  .pill { display: inline-block; padding: 1px 5px; border-radius: 3px; background: #261919; color: #fff; font-size: 7.5px; }
  .foot { position: fixed; bottom: -26px; left: 0; right: 0; font-size: 8px; color: #7a6868; text-align: center; }
</style></head>
<body>
<div class="foot">{{ $r['store']['name'] }} · Daily sales report for {{ $r['date_label'] }} · Generated {{ $r['generated_at'] }} · {{ config('depot.app_name', 'DepotPro') }}</div>

<table class="head"><tr>
  <td style="width:60%">
    @if ($r['store']['logo'])<img src="{{ $r['store']['logo'] }}" style="height:42px; margin-bottom:4px"><br>@endif
    <span class="brand">{{ $r['store']['name'] }}</span><br>
    <span class="muted">{{ collect([$r['store']['address'], $r['store']['phone'], $r['store']['email']])->filter()->implode(' · ') }}</span>
  </td>
  <td class="r muted">Generated {{ $r['generated_at'] }}</td>
</tr></table>

<div class="title"><span class="big">Daily sales report</span> &nbsp; {{ $r['date_label'] }}</div>

<table class="kpi" style="margin-top:6px">
  @foreach (array_chunk($kpis, 4) as $row)
    <tr>
      @foreach ($row as [$label, $value, $delta, $note])
        <td class="card"><div class="box">
          <div class="label">{{ $label }}</div>
          <div class="value">{{ $value }}</div>
          <div class="delta {{ $delta['dir'] }}">{{ $delta['text'] }} vs yesterday</div>
          <div class="note">{{ $note }}</div>
        </div></td>
      @endforeach
    </tr>
  @endforeach
</table>

<h2>Last 7 days</h2>
<table>
  <tr>
    @foreach ($r['trend'] as $d)
      @php $h = (int) round($d['total'] / $max * 70); @endphp
      <td style="vertical-align:bottom; text-align:center; width:14.28%; padding:0 6px">
        <div style="font-size:8px" class="muted">{{ F::money($d['total']) }}</div>
        <div class="{{ $loop->last ? 'bar' : 'barbg' }}" style="height:{{ max(2, $h) }}px; {{ $loop->last ? '' : 'background:#e9b3b3' }}"></div>
        <div style="font-size:8.5px; margin-top:2px"><b>{{ $d['label'] }}</b> {{ $d['date'] }}</div>
      </td>
    @endforeach
  </tr>
</table>

<table class="two"><tr>
  <td class="col left">
    <h2>Money received by method</h2>
    <table class="grid">
      <tr><th>Method</th><th class="r">Payments</th><th class="r">Amount</th><th class="r">vs yesterday</th></tr>
      @foreach ($r['payments']['methods'] as $m)
        @php $d = F::delta($m['amount'], $m['previous']); @endphp
        <tr><td>{{ $m['label'] }}</td><td class="r">{{ $m['count'] }}</td><td class="r">{{ F::money($m['amount']) }}</td><td class="r {{ $d['dir'] }}">{{ $d['text'] }}</td></tr>
      @endforeach
      <tr class="total"><td>Total received</td><td></td><td class="r">{{ F::money($t['collected']) }}</td><td></td></tr>
    </table>
    <p class="muted" style="margin:4px 0 0">Of this, {{ F::money($r['payments']['debt_collected']) }} paid off sales from earlier days.</p>
  </td>
  <td class="col right">
    <h2>Where sales were made</h2>
    <table class="grid">
      <tr><th>Shop or van</th><th class="r">Orders</th><th class="r">Sales</th><th class="r">Share</th></tr>
      @forelse ($r['origins'] as $o)
        <tr><td>{{ $o['name'] }} @if ($o['van'])<span class="pill">VAN</span>@endif</td><td class="r">{{ $o['orders'] }}</td><td class="r">{{ F::money($o['total']) }}</td><td class="r">{{ F::number($o['share']) }}%</td></tr>
      @empty
        <tr><td colspan="4" class="muted">No sales today.</td></tr>
      @endforelse
    </table>
    @if (count($r['branches']) > 1)
      <h2>By branch</h2>
      <table class="grid">
        <tr><th>Branch</th><th class="r">Orders</th><th class="r">Sales</th><th class="r">Gross profit</th></tr>
        @foreach ($r['branches'] as $b)
          <tr><td>{{ $b['name'] }}</td><td class="r">{{ $b['orders'] }}</td><td class="r">{{ F::money($b['total']) }}</td><td class="r">{{ F::money($b['profit']) }}</td></tr>
        @endforeach
      </table>
    @endif
  </td>
</tr></table>

<h2>Products sold ({{ count($r['products']) }})</h2>
<table class="grid">
  <tr><th>Product</th><th>Sold as</th><th class="r">Quantity</th><th class="r">Single units</th><th class="r">Sales</th><th class="r">Gross profit</th><th style="width:16%">Share of sales</th></tr>
  @forelse ($r['products'] as $p)
    <tr>
      <td>{{ $p['name'] }}</td><td>{{ $p['unit'] }}</td><td class="r">{{ F::number($p['qty']) }}</td><td class="r muted">{{ F::number($p['base']) }}</td>
      <td class="r">{{ F::money($p['revenue']) }}</td><td class="r">{{ F::money($p['profit']) }}</td>
      <td><div class="barbg" style="height:7px"><div class="bar" style="height:7px; width:{{ max(1, min(100, $p['share'])) }}%"></div></div><span class="muted" style="font-size:8px">{{ F::number($p['share']) }}%</span></td>
    </tr>
  @empty
    <tr><td colspan="7" class="muted">Nothing sold today.</td></tr>
  @endforelse
  @if (count($r['products']))
    <tr class="total"><td colspan="3">Total</td><td class="r">{{ F::number($t['items']) }}</td><td class="r">{{ F::money($t['sales']) }}</td><td class="r">{{ F::money($t['profit']) }}</td><td></td></tr>
  @endif
</table>

<table class="two"><tr>
  <td class="col left">
    <h2>Sales by staff</h2>
    <table class="grid">
      <tr><th>Staff</th><th class="r">Orders</th><th class="r">Sales</th><th class="r">On credit</th></tr>
      @forelse ($r['staff'] as $s)
        <tr><td>{{ $s['name'] }}</td><td class="r">{{ $s['orders'] }}</td><td class="r">{{ F::money($s['total']) }}</td><td class="r">{{ $s['credit'] ? F::money($s['credit']) : '–' }}</td></tr>
      @empty
        <tr><td colspan="4" class="muted">No sales today.</td></tr>
      @endforelse
    </table>
  </td>
  <td class="col right">
    <h2>Customers</h2>
    <table class="grid">
      <tr><td>Customers who bought</td><td class="r"><b>{{ $r['customers']['buyers'] }}</b></td></tr>
      <tr><td>Returning customers</td><td class="r">{{ $r['customers']['returning'] }}</td></tr>
      <tr><td>New customers added</td><td class="r">{{ $r['customers']['new'] }}</td></tr>
      <tr><td>Walk-in sales (no customer)</td><td class="r">{{ $r['customers']['walk_in'] }}</td></tr>
    </table>
    @if (count($r['customers']['top']))
      <h2>Top customers</h2>
      <table class="grid">
        <tr><th>Customer</th><th class="r">Orders</th><th class="r">Bought</th><th class="r">Owes</th></tr>
        @foreach ($r['customers']['top'] as $c)
          <tr><td>{{ $c['name'] }}</td><td class="r">{{ $c['orders'] }}</td><td class="r">{{ F::money($c['total']) }}</td><td class="r">{{ $c['owing'] ? F::money($c['owing']) : '–' }}</td></tr>
        @endforeach
      </table>
    @endif
  </td>
</tr></table>

<table class="two"><tr>
  <td class="col left">
    <h2>Returns, voids and expenses</h2>
    <table class="grid">
      <tr><td>Returns ({{ $r['returns']['count'] }})</td><td class="r">{{ F::money($r['returns']['total']) }}</td></tr>
      <tr><td>Refunded in money</td><td class="r">{{ F::money($r['returns']['refunded']) }}</td></tr>
      <tr><td>Voided sales ({{ $r['voids']['count'] }})</td><td class="r">{{ F::money($r['voids']['total']) }}</td></tr>
      <tr><td>Expenses</td><td class="r">{{ F::money($r['expenses']['total']) }}</td></tr>
      @foreach ($r['expenses']['items'] as $e)
        <tr><td class="muted">&nbsp;&nbsp;{{ $e['name'] }} ({{ $e['count'] }})</td><td class="r muted">{{ F::money($e['amount']) }}</td></tr>
      @endforeach
    </table>
    @php $cashIn = collect($r['payments']['methods'])->firstWhere('label', 'Cash')['amount'] ?? 0; @endphp
    <p style="margin:5px 0 0">Cash in hand from today: <b>{{ F::money($cashIn - $r['expenses']['cash']) }}</b> <span class="muted">(cash received {{ F::money($cashIn) }} − cash expenses {{ F::money($r['expenses']['cash']) }})</span></p>
  </td>
  <td class="col right">
    <h2>Money owed and stock</h2>
    <table class="grid">
      <tr><td>All customers owe (as of now)</td><td class="r"><b>{{ F::money($r['receivables']) }}</b></td></tr>
    </table>
    <h2>Low stock ({{ count($r['low_stock']) }})</h2>
    <table class="grid">
      @forelse ($r['low_stock'] as $l)
        <tr><td>{{ $l['name'] }}<br><span class="muted">{{ $l['warehouse'] }}</span></td><td class="r"><span class="down">{{ F::number($l['qty']) }}</span> <span class="muted">/ min {{ F::number($l['min']) }}</span></td></tr>
      @empty
        <tr><td class="muted">Nothing below its reorder level.</td></tr>
      @endforelse
    </table>
  </td>
</tr></table>
</body></html>
