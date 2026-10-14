<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ $referencia ? 'Recibo de entrega y pago ' . $referencia : 'Acta de entrega' }} — Decasa</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;color:#1f2937;">
  <div style="max-width:560px;margin:0 auto;padding:24px 16px;">
    <div style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
      <div style="background:#1f2937;padding:24px 28px;">
        <h1 style="margin:0;color:#fff;font-size:20px;">Decasa Muebles &amp; Decoración</h1>
        <p style="margin:6px 0 0;color:#d1d5db;font-size:14px;">
          {{ $referencia ? 'Recibo de entrega y pago ' . $referencia : 'Acta de entrega' }}
        </p>
      </div>
      <div style="padding:24px 28px;font-size:15px;line-height:1.55;">
        <p style="margin:0 0 14px;">Hola{{ $cliente ? ' ' . $cliente : '' }},</p>
        <p style="margin:0 0 14px;">
          Gracias por tu compra. Te enviamos el documento que firmaste al recibir tu pedido
          {{ $orden ? $orden : '' }}: lo que se entregó, en qué estado llegó
          @if ($pagadoHoy > 0) y lo que pagaste en la entrega @endif.
        </p>
        @if ($pagadoHoy > 0)
          <p style="margin:0 0 6px;color:#374151;">Pagado en la entrega: <strong>${{ number_format((float) $pagadoHoy, 0, ',', '.') }}</strong></p>
        @endif
        @if ($saldo !== null && $saldo > 0.01)
          <p style="margin:0 0 18px;color:#374151;">Saldo pendiente: <strong>${{ number_format((float) $saldo, 0, ',', '.') }}</strong></p>
        @elseif ($saldo !== null)
          <p style="margin:0 0 18px;color:#047857;font-weight:600;">Tu pedido quedó pagado en su totalidad.</p>
        @endif
        <p style="margin:0 0 14px;">Va adjunto en PDF.</p>
        @if ($enlace)
          <p style="margin:0 0 22px;text-align:center;">
            <a href="{{ $enlace }}" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;font-weight:600;padding:14px 26px;border-radius:10px;">
              Ver el documento
            </a>
          </p>
        @endif
      </div>
      <div style="padding:16px 28px;background:#f9fafb;border-top:1px solid #e5e7eb;font-size:12px;color:#9ca3af;text-align:center;">
        Este documento es el soporte de la entrega y del pago recibido. No reemplaza la factura electrónica de venta.
      </div>
    </div>
  </div>
</body>
</html>
