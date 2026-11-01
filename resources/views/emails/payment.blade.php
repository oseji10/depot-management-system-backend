@extends('emails.layout')
@section('content')
<p style="margin:0 0 14px 0;">Hi {{ $name }},</p>
<p style="margin:0 0 14px 0;">We have received your payment for <strong>{{ $storeName }}</strong>. Thank you.</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px 0;font-size:14px;">
  <tr><td style="padding:6px 0;color:#6b5a62;">Plan</td><td style="padding:6px 0;text-align:right;font-weight:bold;">{{ $plan }}</td></tr>
  <tr><td style="padding:6px 0;color:#6b5a62;border-top:1px solid #eadfe4;">Amount</td><td style="padding:6px 0;text-align:right;font-weight:bold;border-top:1px solid #eadfe4;">{{ $amount }}</td></tr>
  <tr><td style="padding:6px 0;color:#6b5a62;border-top:1px solid #eadfe4;">Paid up to</td><td style="padding:6px 0;text-align:right;font-weight:bold;border-top:1px solid #eadfe4;">{{ $validUntil }}</td></tr>
  <tr><td style="padding:6px 0;color:#6b5a62;border-top:1px solid #eadfe4;">Reference</td><td style="padding:6px 0;text-align:right;border-top:1px solid #eadfe4;">{{ $reference }}</td></tr>
</table>
<a href="{{ $billingUrl }}" style="display:inline-block;background:{{ config('depot.brand_color') }};color:#ffffff;text-decoration:none;font-weight:bold;padding:11px 20px;border-radius:10px;">View billing</a>
@endsection
@section('footer')
@endsection
