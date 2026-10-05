<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Revisa y firma tu pedido — Decasa</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;color:#1f2937;">
  <div style="max-width:560px;margin:0 auto;padding:24px 16px;">
    <div style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
      <div style="background:#1f2937;padding:24px 28px;">
        <h1 style="margin:0;color:#fff;font-size:20px;">Decasa Muebles &amp; Decoración</h1>
        <p style="margin:6px 0 0;color:#d1d5db;font-size:14px;">Revisa y firma tu pedido</p>
      </div>
      <div style="padding:24px 28px;font-size:15px;line-height:1.55;">
        <p style="margin:0 0 14px;">Hola{{ $cliente ? ' ' . $cliente : '' }},</p>
        <p style="margin:0 0 14px;">
          {{ $vendedor ? $vendedor . ' te' : 'Te' }} envió el resumen de tu pedido y el documento de garantías de Decasa.
          Ábrelo, revisa que todo esté bien, marca lo que leíste y firma con el dedo.
        </p>
        @if ($total)
          <p style="margin:0 0 18px;color:#374151;">Total del pedido: <strong>${{ number_format((float) $total, 0, ',', '.') }}</strong></p>
        @endif
        <p style="margin:0 0 22px;text-align:center;">
          <a href="{{ $enlace }}" style="display:inline-block;background:#16a34a;color:#fff;text-decoration:none;font-weight:600;padding:14px 26px;border-radius:10px;">
            Revisar y firmar
          </a>
        </p>
        <p style="margin:0;font-size:12px;color:#6b7280;">
          Si el botón no abre, copia este enlace en el navegador:<br>
          <span style="word-break:break-all;">{{ $enlace }}</span>
        </p>
      </div>
      <div style="padding:16px 28px;background:#f9fafb;border-top:1px solid #e5e7eb;font-size:12px;color:#9ca3af;text-align:center;">
        El enlace vence en 7 días. Si no reconoces este pedido, ignora este correo.
      </div>
    </div>
  </div>
</body>
</html>
