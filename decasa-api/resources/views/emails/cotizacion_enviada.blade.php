<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cotización Decasa {{ $cotizacion->cotizacion_ref }}</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background: #f3f4f6; color: #1f2937; }
    .wrapper { max-width: 600px; margin: 0 auto; padding: 24px 16px; }
    .card { background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
    .header { background: #6d28d9; padding: 28px 32px; }
    .header h1 { color: #fff; font-size: 22px; font-weight: 700; letter-spacing: -0.3px; }
    .header p { color: #ddd6fe; font-size: 14px; margin-top: 4px; }
    .body { padding: 28px 32px; }
    .greeting { font-size: 16px; color: #374151; margin-bottom: 20px; }
    .section-title { font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; border-bottom: 1px solid #e5e7eb; padding-bottom: 6px; }
    table.items { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    table.items th { text-align: left; font-size: 11px; color: #6b7280; padding: 6px 8px; background: #f9fafb; }
    table.items td { padding: 10px 8px; font-size: 13px; border-bottom: 1px solid #f3f4f6; }
    table.items tr:last-child td { border-bottom: none; }
    .total { text-align: right; font-size: 20px; font-weight: 700; color: #6d28d9; margin-bottom: 20px; }
    .aviso { background: #f5f3ff; border-left: 3px solid #8b5cf6; border-radius: 0 8px 8px 0; padding: 12px 16px; font-size: 13px; color: #5b21b6; margin-bottom: 20px; }
    .footer { padding: 20px 32px; background: #f9fafb; border-top: 1px solid #e5e7eb; font-size: 12px; color: #9ca3af; text-align: center; }
    .footer strong { color: #374151; }
    @media (max-width: 480px) {
      .wrapper { padding: 12px 8px; }
      .header { padding: 20px 18px; }
      .header h1 { font-size: 18px; }
      .body { padding: 20px 18px; }
      .footer { padding: 16px 18px; }
    }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="card">
      <div class="header">
        <h1>Cotización {{ $cotizacion->cotizacion_ref }}</h1>
        <p>{{ $cotizacion->tienda->nombre ?? 'Decasa' }}</p>
      </div>

      <div class="body">
        <p class="greeting">
          Hola{{ $cotizacion->contacto_display && $cotizacion->contacto_display !== 'Sin datos de contacto' ? ' ' . $cotizacion->contacto_display : '' }},
          aquí tienes la cotización que nos pediste. El detalle completo va en el PDF adjunto.
        </p>

        <p class="section-title">Lo cotizado</p>
        <table class="items">
          <thead>
            <tr>
              <th>Producto</th>
              <th style="text-align:center;">Cant.</th>
              <th style="text-align:right;">Valor</th>
            </tr>
          </thead>
          <tbody>
            @foreach($cotizacion->items as $item)
              <tr>
                <td>{{ $item->producto->nombre ?? $item->nombre_custom ?? 'Producto' }}</td>
                <td style="text-align:center;">{{ $item->cantidad }}</td>
                <td style="text-align:right;">$ {{ number_format($item->cantidad * $item->precio_unitario, 0, ',', '.') }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>

        <p class="total">Total: $ {{ number_format($cotizacion->valor_total, 0, ',', '.') }}</p>

        @if($cotizacion->cotizacion_valida_hasta)
          <div class="aviso">
            Esta cotización vale hasta el
            <strong>{{ \Carbon\Carbon::parse($cotizacion->cotizacion_valida_hasta)->format('d/m/Y') }}</strong>.
            Después de esa fecha los precios quedan sujetos a cambio.
          </div>
        @endif

        <p style="font-size:13px; color:#6b7280;">
          Cualquier duda respóndenos este correo o escríbenos
          @if($cotizacion->vendedor?->nombre)
            — te atiende <strong>{{ $cotizacion->vendedor->nombre }}</strong>.
          @else
            .
          @endif
        </p>
      </div>

      <div class="footer">
        <strong>Decasa</strong> — Muebles y decoración
      </div>
    </div>
  </div>
</body>
</html>
