<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Cotización {{ $cotizacion->cotizacion_ref ?? $cotizacion->id }}</title>
</head>
<body style="font-family: 'Helvetica', Arial, sans-serif; font-size: 12px; color: #333; margin: 0; padding: 20px;">

    <!-- Header -->
    <table style="width: 100%; border-bottom: 2px solid #7c3aed; padding-bottom: 10px; margin-bottom: 20px;">
        <tr>
            <td style="vertical-align: middle;">
                @if(!empty($logoBase64))
                    <img src="{{ $logoBase64 }}" style="height: 50px; max-width: 180px; object-fit: contain;" alt="SODEGE">
                @else
                    <h1 style="font-size: 24px; font-weight: bold; color: #7c3aed; margin: 0;">SODEGE</h1>
                @endif
                <p style="font-size: 10px; color: #666; margin: 2px 0 0 0;">Cotización — no constituye orden de compra</p>
            </td>
            <td style="text-align: right; vertical-align: middle;">
                <h2 style="font-size: 18px; font-weight: bold; margin: 0; color: #7c3aed;">
                    COTIZACIÓN {{ $cotizacion->cotizacion_ref ?? '#' . $cotizacion->id }}
                </h2>
                @if($cotizacion->esta_vencida)
                    <span style="display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: bold; color: white; background-color: #ef4444; margin-top: 5px;">
                        VENCIDA
                    </span>
                @elseif($cotizacion->cotizacion_valida_hasta)
                    <span style="display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: bold; color: white; background-color: #7c3aed; margin-top: 5px;">
                        Válida hasta {{ $cotizacion->cotizacion_valida_hasta->format('d/m/Y') }}
                    </span>
                @endif
            </td>
        </tr>
    </table>

    <!-- Info general -->
    <table style="width: 100%; margin-bottom: 20px; border-collapse: collapse;">
        <tr>
            <td style="width: 50%; vertical-align: top; padding-right: 10px;">
                <div style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px;">
                    <p style="font-size: 10px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin: 0 0 8px 0;">Dirigida a</p>
                    <p style="margin: 4px 0; font-size: 11px;">
                        <strong>Cliente:</strong>
                        {{ $cotizacion->cliente->nombre ?? $cotizacion->contacto_nombre ?? '—' }}
                    </p>
                    @php
                        $tel   = $cotizacion->cliente->telefono ?? $cotizacion->contacto_telefono ?? null;
                        $email = $cotizacion->cliente->email    ?? $cotizacion->contacto_email    ?? null;
                    @endphp
                    @if($tel)
                        <p style="margin: 4px 0; font-size: 11px;"><strong>Teléfono:</strong> {{ $tel }}</p>
                    @endif
                    @if($email)
                        <p style="margin: 4px 0; font-size: 11px;"><strong>Correo:</strong> {{ $email }}</p>
                    @endif
                    @if($cotizacion->notas)
                        <p style="margin: 4px 0; font-size: 11px;"><strong>Notas:</strong> {{ $cotizacion->notas }}</p>
                    @endif
                </div>
            </td>
            <td style="width: 50%; vertical-align: top; padding-left: 10px;">
                <div style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px;">
                    <p style="font-size: 10px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin: 0 0 8px 0;">Datos de la cotización</p>
                    <p style="margin: 4px 0; font-size: 11px;"><strong>Tienda:</strong> {{ $cotizacion->tienda->nombre ?? '—' }}</p>
                    <p style="margin: 4px 0; font-size: 11px;"><strong>Asesor:</strong> {{ $cotizacion->vendedor->nombre ?? '—' }}</p>
                    <p style="margin: 4px 0; font-size: 11px;"><strong>Fecha:</strong> {{ \Carbon\Carbon::parse($cotizacion->created_at)->format('d/m/Y') }}</p>
                    @if($cotizacion->cotizacion_valida_hasta)
                        <p style="margin: 4px 0; font-size: 11px;">
                            <strong>Válida hasta:</strong> {{ $cotizacion->cotizacion_valida_hasta->format('d/m/Y') }}
                        </p>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <!-- Ítems -->
    <div style="margin-bottom: 20px;">
        <p style="font-size: 10px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin: 0 0 8px 0;">Ítems ({{ count($cotizacion->items) }})</p>
        <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
            <thead>
                <tr style="background-color: #7c3aed; color: white;">
                    <th style="padding: 8px; text-align: center; width: 30px;">#</th>
                    <th style="padding: 8px; text-align: left;">Producto</th>
                    <th style="padding: 8px; text-align: center;">Cant.</th>
                    <th style="padding: 8px; text-align: right;">P. Unit.</th>
                    <th style="padding: 8px; text-align: right;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @foreach($cotizacion->items as $idx => $item)
                    <tr style="border-bottom: 1px solid #e5e7eb; {{ $loop->even ? 'background-color:#f9fafb;' : '' }}">
                        <td style="padding: 8px; text-align: center; color: #6b7280; font-size: 10px;">{{ $idx + 1 }}</td>
                        <td style="padding: 8px;">
                            {{ $item->producto->nombre ?? $item->nombre_custom ?? 'Producto personalizado' }}
                            @php
                                $tipoBadge = match($item->tipo_item) {
                                    'personalizado'   => ['Personalizado',   '#ede9fe', '#7c3aed'],
                                    'restauracion'    => ['Restauración',    '#e0e7ff', '#4338ca'],
                                    'diseno_especial' => ['Diseño especial', '#e0e7ff', '#4f46e5'],
                                    'fabricar'        => ['Para fabricar',    '#fef3c7', '#d97706'],
                                    default           => null,
                                };
                            @endphp
                            @if($tipoBadge)
                                <span style="display: inline-block; padding: 1px 6px; background-color: {{ $tipoBadge[1] }}; color: {{ $tipoBadge[2] }}; font-size: 9px; border-radius: 8px; margin-left: 4px;">{{ $tipoBadge[0] }}</span>
                            @endif
                            {{-- Qué variante se está cotizando. El cliente compara
                                 precios: una cama de 1.40 y una de 1.80 no valen
                                 lo mismo y el papel tiene que decir cuál es. --}}
                            @if($item->variante_texto)
                                <br><span style="font-size: 9px; font-weight: bold; color: #dc2626;">{{ $item->variante_texto }}</span>
                            @endif
                            @php
                                $specs = $item->specs_personalizacion ?? [];
                                $marca = $specs['variante_marca'] ?? null;
                                $color = $specs['variante_color'] ?? null;
                            @endphp
                            @if($marca || $color)
                                <br><span style="font-size: 9px; color: #7c3aed;">{{ implode(' · ', array_filter([$marca, $color])) }}</span>
                            @endif
                            @if(!empty($specs['descripcion']))
                                <br><span style="font-size: 9px; color: #6b7280;">{{ $specs['descripcion'] }}</span>
                            @endif
                        </td>
                        <td style="padding: 8px; text-align: center;">{{ $item->cantidad }}</td>
                        <td style="padding: 8px; text-align: right;">$ {{ number_format($item->precio_unitario, 0, ',', '.') }}</td>
                        <td style="padding: 8px; text-align: right; font-weight: bold;">$ {{ number_format($item->cantidad * $item->precio_unitario, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                @if($cotizacion->descuento_total > 0)
                <tr>
                    <td colspan="4" style="padding: 6px 8px; text-align: right; font-size: 11px; color: #6b7280;">Subtotal:</td>
                    <td style="padding: 6px 8px; text-align: right; font-size: 11px; color: #6b7280;">$ {{ number_format($cotizacion->valor_total + $cotizacion->descuento_total, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td colspan="4" style="padding: 6px 8px; text-align: right; font-size: 11px; color: #059669;">Descuento:</td>
                    <td style="padding: 6px 8px; text-align: right; font-size: 11px; color: #059669;">− $ {{ number_format($cotizacion->descuento_total, 0, ',', '.') }}</td>
                </tr>
                @endif
                <tr style="background-color: #faf5ff;">
                    <td colspan="4" style="padding: 8px; text-align: right; font-weight: bold;">TOTAL COTIZADO:</td>
                    <td style="padding: 8px; text-align: right; font-weight: bold; font-size: 13px; color: #7c3aed;">$ {{ number_format($cotizacion->valor_total, 0, ',', '.') }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Detalles de personalización -->
    @php
        $itemsPersonalizados = $cotizacion->items->where('es_personalizado', true);
        $etiquetasSpec = [
            'marca' => 'Marca', 'tela' => 'Tela', 'color' => 'Color', 'medidas' => 'Medidas',
            'acabado' => 'Acabado', 'descripcion' => 'Descripción', 'notas' => 'Notas',
            'material' => 'Material', 'color_material' => 'Color/acabado',
            'largo_cm' => 'Largo', 'ancho_cm' => 'Ancho', 'alto_cm' => 'Alto',
            'variante_marca' => 'Marca', 'variante_color' => 'Color',
        ];
    @endphp
    @if($itemsPersonalizados->isNotEmpty())
    <div style="margin-bottom: 20px; border: 1px solid #ede9fe; border-radius: 8px; padding: 16px; background-color: #faf5ff;">
        <p style="font-size: 10px; font-weight: bold; color: #7c3aed; text-transform: uppercase; margin: 0 0 12px 0;">Detalles de personalización</p>
        @foreach($itemsPersonalizados as $item)
            @php
                $specs = $item->specs_personalizacion ?? [];
                $notas = $specs['notas'] ?? null;
                unset($specs['notas']);
                $specsVisibles = array_filter($specs, fn($v) => $v !== null && $v !== '');
            @endphp
            <div style="margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #ede9fe;">
                <p style="font-size: 11px; font-weight: bold; color: #374151; margin: 0 0 6px 0;">
                    {{ $item->producto->nombre ?? $item->nombre_custom ?? 'Producto personalizado' }}
                </p>
                @if(!empty($specsVisibles))
                    @foreach($specsVisibles as $clave => $valor)
                        <p style="margin: 2px 0; font-size: 10px; color: #4b5563;">
                            <strong>{{ $etiquetasSpec[$clave] ?? ucfirst(str_replace('_', ' ', $clave)) }}:</strong>
                            {{ is_array($valor) ? implode(', ', $valor) : $valor }}
                        </p>
                    @endforeach
                @endif
                @if($notas)
                    <p style="margin: 4px 0 0 0; font-size: 10px; color: #6b7280;"><strong>Notas:</strong> {{ $notas }}</p>
                @endif
            </div>
        @endforeach
    </div>
    @endif

    <!-- Condiciones -->
    <div style="border: 1px solid #fde68a; background-color: #fffbeb; border-radius: 8px; padding: 12px; margin-bottom: 20px;">
        <p style="font-size: 10px; font-weight: bold; color: #92400e; text-transform: uppercase; margin: 0 0 6px 0;">Condiciones</p>
        <p style="margin: 3px 0; font-size: 10px; color: #78350f;">
            • Esta cotización es informativa y <strong>no reserva mercancía</strong>. La disponibilidad se confirma al generar la orden.
        </p>
        @if($cotizacion->cotizacion_valida_hasta)
        <p style="margin: 3px 0; font-size: 10px; color: #78350f;">
            • Precios válidos hasta el <strong>{{ $cotizacion->cotizacion_valida_hasta->format('d/m/Y') }}</strong> y sujetos a cambio después de esa fecha.
        </p>
        @endif
        <p style="margin: 3px 0; font-size: 10px; color: #78350f;">
            • Los tiempos de entrega se acuerdan al confirmar la orden y registrar el anticipo.
        </p>
    </div>

    <!-- Firma del asesor -->
    @if(!empty($firmaVendedor))
    <table style="width: 100%; margin-top: 30px;">
        <tr>
            <td style="width: 50%; text-align: center;">
                <img src="{{ $firmaVendedor }}" style="height: 45px; object-fit: contain;" alt="Firma asesor">
                <div style="border-top: 1px solid #9ca3af; margin: 4px 20px 0 20px; padding-top: 4px;">
                    <p style="font-size: 10px; color: #6b7280; margin: 0;">{{ $cotizacion->vendedor->nombre ?? 'Asesor' }}</p>
                </div>
            </td>
            <td style="width: 50%;"></td>
        </tr>
    </table>
    @endif

    <p style="text-align: center; font-size: 9px; color: #9ca3af; margin-top: 25px;">
        SODEGE — {{ $cotizacion->tienda->nombre ?? '' }} · Generada el {{ now()->format('d/m/Y H:i') }}
    </p>

</body>
</html>
