<!doctype html>
<html lang="en">
<body style="margin:0;background:#f4f7f4;font-family:Arial,sans-serif;color:#203128">
    <div style="max-width:560px;margin:32px auto;background:#fff;border-radius:16px;padding:32px;border-top:6px solid #116a38">
        <p style="margin:0 0 8px;color:#116a38;font-weight:700">COLEGIO DE MONTALBAN</p>
        <h1 style="font-size:24px;margin:0 0 16px">Verify your CDM Portal login</h1>
        <p>Enter this one-time code to finish signing in:</p>
        <p style="font-size:32px;letter-spacing:8px;font-weight:700;color:#116a38">{{ $code }}</p>
        <p>This code expires in {{ $expiresInMinutes }} minutes and can be used once.</p>
        <p>If you did not try to sign in, do not share this code. You can safely ignore this message and contact the portal administrator if the attempts continue.</p>
    </div>
</body>
</html>
