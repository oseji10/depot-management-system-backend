@extends('emails.layout')
@section('content')
<p style="margin:0 0 14px 0;">Hi {{ $name }},</p>
<p style="margin:0 0 14px 0;"><strong>{{ $storeName }}</strong> is ready. You are the owner, so you can add branches, warehouses, products and staff, and start selling.</p>
@if ($trialDays)<p style="margin:0 0 14px 0;">Your free trial runs for {{ $trialDays }} days.</p>@endif
<p style="margin:0 0 18px 0;">Sign in with <strong>{{ $email }}</strong> and the password you chose.</p>
<a href="{{ $loginUrl }}" style="display:inline-block;background:{{ config('depot.brand_color') }};color:#ffffff;text-decoration:none;font-weight:bold;padding:11px 20px;border-radius:10px;">Sign in</a>
@endsection
@section('footer')
@endsection
