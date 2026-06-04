<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cotización Decasa #{{ $orden->id }}</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background: #f3f4f6; color: #1f2937; }
    .wrapper { max-width: 600px; margin: 0 auto; padding: 24px 16px; }
    .card { background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
    .header { background: #1d4ed8; padding: 28px 32px; }
    .header h1 { color: #fff; font-size: 22px; font-weight: 700; letter-spacing: -0.3px; }
    .header p { color: #bfdbfe; font-size: 14px; margin-top: 4px; }
    .body { padding: 28px 32px; }
    .greeting { font-size: 16px; color: #374151; margin-bottom: 20px; }
    .kpi-row { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 24px; }
    .kpi { flex: 1 1 120px; background: #f9fafb; border-radius: 8px; padding: 14px 16px; border: 1px solid #e5e7eb; min-width: 0; }
    .kpi .label { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
    .kpi .value { font-size: 17px; font-weight: 700; color: #111827; word-break: break-word; }
    .kpi.highlight .value { color: #1d4ed8; }
    .kpi.danger .value { color: #dc2626; }
    .section-title { font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; border-bottom: 1px solid #e5e7eb; padding-bottom: 6px; }
    table.items { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
    table.items th { text-align: left; font-size: 11px; color: #6b7280; padding: 6px 8px; background: #f9fafb; }
    table.items td { padding: 10px 8px; font-size: 13px; border-bottom: 1px solid #f3f4f6; }
    table.items tr:last-child td { border-bottom: none; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #dbeafe; color: #1d4ed8; }
    .badge.custom { background: #ede9fe; color: #7c3aed; }
    .note-box { background: #eff6ff; border-left: 3px solid #3b82f6; border-radius: 0 8px 8px 0; padding: 12px 16px; font-size: 13px; color: #1e40af; margin-bottom: 24px; }
    .footer { padding: 20px 32px; background: #f9fafb; border-top: 1px solid #e5e7eb; font-size: 12px; color: #9ca3af; text-align: center; }
    .footer strong { color: #374151; }
    @media (max-width: 480px) {
      .wrapper { padding: 12px 8px; }
      .header { padding: 20px 18px; }
      .header h1 { font-size: 18px; }
      .body { padding: 20px 18px; }
      .footer { padding: 16px 18px; }
      .kpi-row { gap: 8px; }
      .kpi { flex: 1 1 calc(50% - 4px); padding: 12px; }
      .kpi .value { font-size: 15px; }
      table.items th, table.items td { padding: 8px 6px; font-size: 12px; }
      .greeting { font-size: 15px; }
    }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="card">

      <!-- Header -->
      <div class="header">
        <h1>Decasa — Tu Cotización</h1>
        <p>Orden #{{ $orden->id }} · {{ now()->locale('es')->isoFormat('D [de] MMMM [de] YYYY') }}</p>
      </div>

      <!-- Body -->
      <div class="body">
        <p class="greeting">
          Hola <strong>{{ $orden->cliente->nombre }}</strong>,<br>
          gracias por tu compra en <strong>Decasa</strong>. Adjunto encontrarás la cotización completa de tu pedido en PDF.
        </p>

        <!-- KPIs -->
        <div class="kpi-row">
          <div class="kpi highlight">
            <div class="label">Total pedido</div>
            <div class="value">${{ number_format($orden->valor_total, 0, ',', '.') }}</div>
          </div>
          <div class="kpi">
            <div class="label">Anticipo pagado</div>
            <div class="value">${{ number_format($orden->total_pagado, 0, ',', '.') }}</div>
          </div>
          @if ($orden->saldo_pendiente > 0)
          <div class="kpi danger">
            <div class="label">Saldo pendiente</div>
            <div class="value">${{ number_format($orden->saldo_pendiente, 0, ',', '.') }}</div>
          </div>
          @endif
        </div>

        <!-- Ítems -->
        <p class="section-title">Productos del pedido</p>
        <table class="items">
          <thead>
            <tr>
              <th>Producto</th>
              <th style="text-align:center">Cant.</th>
              <th style="text-align:right">Valor</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($orden->items as $item)
            <tr>
              <td>
                {{ $item->producto?->nombre ?? '—' }}
                @if ($item->es_personalizado)
                  <span class="badge custom">Personalizado</span>
                @endif
                @if ($item->fecha_entrega_prom)
                  <br><small style="color:#6b7280">Entrega: {{ \Carbon\Carbon::parse($item->fecha_entrega_prom)->locale('es')->isoFormat('D [de] MMMM') }}</small>
                @endif
              </td>
              <td style="text-align:center">{{ $item->cantidad }}</td>
              <td style="text-align:right; font-weight:600">${{ number_format($item->cantidad * $item->precio_unitario, 0, ',', '.') }}</td>
            </tr>
            @endforeach
          </tbody>
        </table>

        <!-- Nota -->
        <div class="note-box">
          📎 El PDF adjunto contiene todos los detalles de la cotización incluyendo especificaciones, fechas de entrega y firma del acuerdo.
        </div>

        <p style="font-size:13px; color:#6b7280;">
          Si tienes alguna pregunta, comunícate con tu asesor: <strong>{{ $orden->vendedor?->nombre }}</strong>
          en la tienda <strong>{{ $orden->tienda?->nombre }}</strong>.
        </p>
      </div>

      <!-- Footer -->
      <div class="footer">
        <strong>Decasa</strong> · {{ $orden->tienda?->nombre }}<br>
        Este es un correo automático, por favor no respondas a este mensaje.
      </div>
    </div>
  </div>
</body>
</html>
