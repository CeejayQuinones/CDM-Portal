<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>CDM Portal Google Sign-In</title></head>
<body><p>Completing Google sign-in…</p>
<script>
const payload = { type: 'cdm-google-auth', code: {{ Illuminate\Support\Js::from($code) }}, error: {{ Illuminate\Support\Js::from($error) }} };
if (window.opener) window.opener.postMessage(payload, {{ Illuminate\Support\Js::from($origin) }});
window.close();
</script></body></html>
