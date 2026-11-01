<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ config('depot.app_name') }}</title></head>
<body style="margin:0;padding:0;background:#f8f4f6;font-family:Arial,Helvetica,sans-serif;color:#26191f;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f4f6;padding:24px 12px;">
  <tr><td align="center">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
      <tr><td style="padding:0 0 14px 4px;font-size:22px;font-weight:bold;color:{{ config('depot.brand_color') }};">{{ config('depot.app_name') }}</td></tr>
      <tr><td style="background:#ffffff;border:1px solid #eadfe4;border-radius:14px;padding:26px;font-size:15px;line-height:1.55;">
        @yield('content')
      </td></tr>
      <tr><td style="padding:16px 4px 0 4px;font-size:12px;color:#6b5a62;">
        @yield('footer')
        Sent by {{ config('depot.app_name') }}, Depot Management System.
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
