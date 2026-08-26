<!doctype html>
<html lang="en">
<body style="margin:0;background:#f4f7f4;font-family:Arial,sans-serif;color:#203128">
    <div style="max-width:560px;margin:32px auto;background:#fff;border-radius:16px;padding:32px;border-top:6px solid #116a38">
        <p style="margin:0 0 8px;color:#116a38;font-weight:700">COLEGIO DE MONTALBAN</p>
        <h1 style="font-size:24px;margin:0 0 16px">Verify your email address</h1>
        <p>Use this one-time code to continue creating your CDM Portal account:</p>
        <p style="font-size:32px;letter-spacing:8px;font-weight:700;color:#116a38">{{ $code }}</p>
        <p>This code expires in {{ \App\Services\RegistrationEmailVerificationService::CODE_TTL_MINUTES }} minutes. If you did not request it, you can ignore this message.</p>
    </div>
</body>
</html>
