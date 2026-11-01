@extends('emails.layout')
@section('content')
<p style="margin:0 0 14px 0;">Hi {{ $name }},</p>
<p style="margin:0 0 14px 0;">An account has been created for you at <strong>{{ $storeName }}</strong> as <strong>{{ $role }}</strong>.</p>
<p style="margin:0 0 18px 0;">Sign in with <strong>{{ $email }}</strong>. Your manager will give you the password. If you would rather choose your own, use <a href="{{ $forgotUrl }}" style="color:{{ config('depot.brand_color') }};">Forgot password</a> once.</p>
<a href="{{ $loginUrl }}" style="display:inline-block;background:{{ config('depot.brand_color') }};color:#ffffff;text-decoration:none;font-weight:bold;padding:11px 20px;border-radius:10px;">Sign in</a>
@endsection
@section('footer')
@endsection
