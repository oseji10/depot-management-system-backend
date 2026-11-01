@extends('emails.layout')
@section('content')
<p style="margin:0 0 14px 0;">Hi {{ $name }},</p>
<p style="margin:0 0 18px 0;">We got a request to reset your password. The link below works for {{ $minutes }} minutes.</p>
<a href="{{ $url }}" style="display:inline-block;background:{{ config('depot.brand_color') }};color:#ffffff;text-decoration:none;font-weight:bold;padding:11px 20px;border-radius:10px;">Choose a new password</a>
<p style="margin:18px 0 0 0;color:#6b5a62;font-size:13px;">If you did not ask for this, ignore this email. Your password stays the same.</p>
@endsection
@section('footer')
@endsection
