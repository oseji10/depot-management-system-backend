@extends('emails.layout')
@section('content')
<p style="margin:0 0 14px 0;">Hi {{ $name }},</p>
<p style="margin:0 0 18px 0;">{{ count($items) === 1 ? 'Here is something new at' : 'Here is what is new at' }} <strong>{{ $storeName }}</strong>:</p>
@foreach ($items as $item)
  @php
    $bar = ['success' => '#16a34a', 'info' => '#0284c7', 'warning' => '#d97706', 'alert' => '#dc2626'][$item['severity']] ?? '#BC0F5B';
  @endphp
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 12px 0;border-left:4px solid {{ $bar }};background:#fdf8fa;border-radius:6px;">
    <tr><td style="padding:10px 14px;">
      <div style="font-weight:bold;">{{ $item['title'] }}</div>
      @if (! empty($item['body']))<div style="color:#6b5a62;margin-top:2px;">{{ $item['body'] }}</div>@endif
      @if (! empty($item['url']))<div style="margin-top:6px;"><a href="{{ $item['url'] }}" style="color:{{ config('depot.brand_color') }};font-weight:bold;text-decoration:none;">Open in {{ config('depot.app_name') }} &rarr;</a></div>@endif
    </td></tr>
  </table>
@endforeach
@endsection
@section('footer')
You get these because email alerts are on for your account. <a href="{{ $settingsUrl }}" style="color:#6b5a62;">Change what you receive</a>.<br>
@endsection
