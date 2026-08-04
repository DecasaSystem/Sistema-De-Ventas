<!DOCTYPE html>
{{--
    Orden en PDF — pensada para imprimirse en UNA hoja carta.

    Los bocetos y fotos no van aquí: cada uno se llevaba media página y la orden
    salía en dos hojas. Se consultan en el detalle de la orden, en el sistema.
    Si algo se agrega a esta plantilla, comprobar que siga cabiendo en una hoja.
--}}
<html>
<head>
    <meta charset="utf-8">
    <title>Orden {{ $orden->referencia }}</title>
</head>
<body style="font-family: 'Helvetica', Arial, sans-serif; font-size: 11px; color: #333; margin: 0; padding: 14px 18px;">

    <!-- Header -->
    <table style="width: 100%; border-bottom: 2px solid #2563eb; padding-bottom: 6px; margin-bottom: 10px;">
        <tr>
            <td style="vertical-align: middle;">
                @if(!empty($logoBase64))
                    <img src="{{ $logoBase64 }}" style="height: 38px; max-width: 150px; object-fit: contain;" alt="Decasa">
                @else
                    <h1 style="font-size: 20px; font-weight: bold; color: #2563eb; margin: 0;">DECASA</h1>
                @endif
            </td>
            <td style="text-align: right; vertical-align: middle;">
                <h2 style="font-size: 16px; font-weight: bold; margin: 0;">Orden {{ $orden->referencia }}</h2>
                @if($orden->es_descuento_especial)
                    <p style="font-size: 9px; color: #92400e; margin: 1px 0 0 0; font-weight: bold;">Descuento especial</p>
                @endif
                @php
                    $estadoLabel = [
                        'pendiente_anticipo' => 'En Espera',
                        'en_produccion'      => 'En Producción',
                        'listo_entrega'      => 'Listo Entrega',
                        'entregado'          => 'Entregado',
                        'cancelado'          => 'Cancelado',
                    ];
                    $estadoColor = [
                        'pendiente_anticipo' => '#f59e0b',
                        'en_produccion'      => '#3b82f6',
                        'listo_entrega'      => '#8b5cf6',
                        'entregado'          => '#10b981',
                        'cancelado'          => '#ef4444',
                    ];
                @endphp
                <span style="display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 10px; font-weight: bold; color: white; background-color: {{ $estadoColor[$orden->estado] ?? '#666' }}; margin-top: 3px;">
                    {{ $estadoLabel[$orden->estado] ?? $orden->estado }}
                </span>
            </td>
        </tr>
    </table>

    <!-- Info General -->
    <table style="width: 100%; margin-bottom: 10px; border-collapse: collapse;">
        <tr>
            <td style="width: 50%; vertical-align: top; padding-right: 8px;">
                <div style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 8px 10px;">
                    <p style="font-size: 9px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin: 0 0 5px 0;">Información General</p>
                    <p style="margin: 2px 0; font-size: 10px;"><strong>Cliente:</strong> {{ $orden->cliente->nombre ?? 'N/A' }}</p>
                    <p style="margin: 2px 0; font-size: 10px;"><strong>Cédula / NIT:</strong> {{ $orden->cliente->cedula ?? 'N/A' }}</p>
                    <p style="margin: 2px 0; font-size: 10px;"><strong>Teléfono:</strong> {{ $orden->cliente->telefono ?? 'N/A' }}</p>
                    <p style="margin: 2px 0; font-size: 10px;"><strong>Tienda:</strong> {{ $orden->tienda->nombre ?? 'N/A' }}</p>
                    <p style="margin: 2px 0; font-size: 10px;"><strong>Vendedor:</strong> {{ $orden->vendedor->nombre ?? 'N/A' }}</p>
                    <p style="margin: 2px 0; font-size: 10px;"><strong>Canal:</strong> {{ ucfirst($orden->canal) }}</p>
                    <p style="margin: 2px 0; font-size: 10px;"><strong>Fecha compra:</strong> {{ \Carbon\Carbon::parse($orden->created_at)->format('d/m/Y H:i') }}</p>
                    @if($orden->departamento_envio || $orden->ciudad_envio || $orden->direccion_envio)
                        <p style="margin: 5px 0 2px 0; font-size: 9px; font-weight: bold; color: #6b7280; text-transform: uppercase;">Envío</p>
                        @if($orden->ciudad_envio || $orden->departamento_envio)
                            <p style="margin: 2px 0; font-size: 10px;"><strong>Ciudad:</strong>
                                {{ $orden->ciudad_envio }}{{ $orden->departamento_envio ? ', ' . $orden->departamento_envio : '' }}
                            </p>
                        @endif
                        @if($orden->direccion_envio)
                            <p style="margin: 2px 0; font-size: 10px;"><strong>Dirección:</strong> {{ $orden->direccion_envio }}</p>
                        @endif
                    @endif
                    @if($orden->notas)
                        <p style="margin: 2px 0; font-size: 10px;"><strong>Notas:</strong> {{ $orden->notas }}</p>
                    @endif
                </div>
            </td>
            <td style="width: 50%; vertical-align: top; padding-left: 8px;">
                <div style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 8px 10px;">
                    <p style="font-size: 9px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin: 0 0 5px 0;">Resumen Financiero</p>
                    <table style="width: 100%; font-size: 10px;">
                        <tr>
                            <td style="padding: 2px 0;"><strong>Total:</strong></td>
                            <td style="text-align: right; font-weight: bold;">$ {{ number_format($orden->valor_total, 2) }}</td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Pagado:</strong></td>
                            <td style="text-align: right; color: #16a34a; font-weight: bold;">$ {{ number_format($orden->total_pagado, 2) }}</td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><strong>Saldo:</strong></td>
                            <td style="text-align: right; color: #dc2626; font-weight: bold;">$ {{ number_format($orden->saldo_pendiente, 2) }}</td>
                        </tr>
                    </table>
                    <div style="margin-top: 5px;">
                        <div style="width: 100%; background-color: #e5e7eb; border-radius: 8px; height: 9px; overflow: hidden;">
                            <div style="width: {{ $orden->porcentaje_pagado }}%; background-color: {{ $orden->porcentaje_pagado >= 100 ? '#10b981' : '#2563eb' }}; height: 100%; border-radius: 8px;"></div>
                        </div>
                        <p style="text-align: right; font-size: 9px; color: #6b7280; margin: 2px 0 0 0;">{{ $orden->porcentaje_pagado }}% pagado</p>
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <!-- Ítems -->
    <div style="margin-bottom: 10px;">
        <p style="font-size: 9px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin: 0 0 4px 0;">Ítems ({{ count($orden->items) }})</p>
        <table style="width: 100%; border-collapse: collapse; font-size: 10px;">
            <thead>
                <tr style="background-color: #2563eb; color: white;">
                    <th style="padding: 5px; text-align: center; width: 24px;">#</th>
                    <th style="padding: 5px; text-align: left;">Producto</th>
                    <th style="padding: 5px; text-align: center;">Cant.</th>
                    <th style="padding: 5px; text-align: right;">P. Unit.</th>
                    <th style="padding: 5px; text-align: right;">Subtotal</th>
                    <th style="padding: 5px; text-align: center;">Entrega</th>
                </tr>
            </thead>
            <tbody>
                @foreach($orden->items as $idx => $item)
                    <tr style="border-bottom: 1px solid #e5e7eb; {{ $loop->even ? 'background-color:#f9fafb;' : '' }}">
                        <td style="padding: 4px 5px; text-align: center; color: #6b7280; font-size: 9px;">{{ $idx + 1 }}</td>
                        <td style="padding: 4px 5px;">
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
                                <span style="display: inline-block; padding: 0 5px; background-color: {{ $tipoBadge[1] }}; color: {{ $tipoBadge[2] }}; font-size: 8px; border-radius: 6px; margin-left: 3px;">{{ $tipoBadge[0] }}</span>
                            @endif
                            @php
                                $specs = $item->specs_personalizacion ?? [];
                                $marca = $specs['variante_marca'] ?? null;
                                $color = $specs['variante_color'] ?? null;
                            @endphp
                            @if($marca || $color)
                                <br><span style="font-size: 8px; color: #7c3aed;">{{ implode(' · ', array_filter([$marca, $color])) }}</span>
                            @endif
                            @if(!empty($specs['descripcion']))
                                <br><span style="font-size: 8px; color: #6b7280;">{{ $specs['descripcion'] }}</span>
                            @endif
                            @php
                                $origenInventario = (!$item->es_personalizado || $item->usa_stock_tienda)
                                    ? ($item->tiendaOrigen->nombre ?? $orden->tienda->nombre ?? null)
                                    : null;
                            @endphp
                            @if($origenInventario)
                                <br><span style="font-size: 8px; color: #059669;">Inventario {{ $origenInventario }}</span>
                            @endif
                        </td>
                        <td style="padding: 4px 5px; text-align: center;">{{ $item->cantidad }}</td>
                        <td style="padding: 4px 5px; text-align: right;">$ {{ number_format($item->precio_unitario, 2) }}</td>
                        <td style="padding: 4px 5px; text-align: right; font-weight: bold;">$ {{ number_format($item->cantidad * $item->precio_unitario, 2) }}</td>
                        <td style="padding: 4px 5px; text-align: center; font-size: 9px; color: {{ $item->fecha_entrega_prom ? '#374151' : '#9ca3af' }};">
                            {{ $item->fecha_entrega_prom ? \Carbon\Carbon::parse($item->fecha_entrega_prom)->format('d/m/Y') : '—' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                @php
                    // El monto es la fuente de verdad; el % se deriva solo para
                    // que el cliente vea a cuánto equivale la rebaja.
                    $subtotalBruto = $orden->subtotalBruto();
                    $pctTexto = function ($monto) use ($subtotalBruto) {
                        if ($subtotalBruto <= 0 || $monto <= 0) return '';
                        $pct = round($monto / $subtotalBruto * 100, 1);
                        return rtrim(rtrim(number_format($pct, 1, ',', '.'), '0'), ',');
                    };
                @endphp

                @if($orden->descuento_total > 0 || $orden->descuento_condicionado > 0)
                <tr>
                    <td colspan="4" style="padding: 3px 5px; text-align: right; font-size: 10px; color: #6b7280;">Subtotal:</td>
                    <td style="padding: 3px 5px; text-align: right; font-size: 10px; color: #6b7280;">$ {{ number_format($subtotalBruto, 2) }}</td>
                    <td></td>
                </tr>
                @endif

                @if($orden->descuento_total > 0)
                <tr>
                    <td colspan="4" style="padding: 3px 5px; text-align: right; font-size: 10px; color: #059669;">
                        Descuento ({{ $pctTexto($orden->descuento_total) }}%):
                    </td>
                    <td style="padding: 3px 5px; text-align: right; font-size: 10px; color: #059669;">− $ {{ number_format($orden->descuento_total, 2) }}</td>
                    <td></td>
                </tr>
                @endif

                @if($orden->descuento_condicionado > 0 && ! $orden->descuento_condicionado_revertido_at)
                <tr>
                    <td colspan="4" style="padding: 3px 5px; text-align: right; font-size: 10px; color: #059669;">
                        Descuento por pago en efectivo o transferencia ({{ $pctTexto($orden->descuento_condicionado) }}%):
                    </td>
                    <td style="padding: 3px 5px; text-align: right; font-size: 10px; color: #059669;">− $ {{ number_format($orden->descuento_condicionado, 2) }}</td>
                    <td></td>
                </tr>
                @endif
                <tr style="background-color: #eff6ff;">
                    <td colspan="4" style="padding: 5px; text-align: right; font-weight: bold;">TOTAL:</td>
                    <td style="padding: 5px; text-align: right; font-weight: bold; font-size: 12px; color: #2563eb;">$ {{ number_format($orden->valor_total, 2) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Detalles de Personalización -->
    @php
        // Solo los que tienen algo que detallar: un personalizado sin specs, sin
        // notas y sin boceto solo repetía el nombre que ya está en la tabla de
        // arriba y gastaba una línea.
        $itemsPersonalizados = $orden->items
            ->where('es_personalizado', true)
            ->filter(function ($i) {
                $s = $i->specs_personalizacion ?? [];
                $tieneSpecs = ! empty(array_filter($s, fn($v) => $v !== null && $v !== '' && $v !== []));
                return $tieneSpecs || ! empty($i->boceto_url);
            });
        // Etiquetas legibles para claves conocidas de specs_personalizacion (mismas que
        // usa el detalle web); cualquier clave nueva que no esté aquí se muestra con su
        // nombre formateado en vez de ocultarse.
        $etiquetasSpec = [
            'marca' => 'Marca', 'tela' => 'Tela', 'color' => 'Color', 'medidas' => 'Medidas',
            'acabado' => 'Acabado', 'descripcion' => 'Descripción', 'notas' => 'Notas',
            'material' => 'Material', 'color_material' => 'Color/acabado',
            'largo_cm' => 'Largo', 'ancho_cm' => 'Ancho', 'alto_cm' => 'Alto',
            'variante_marca' => 'Marca', 'variante_color' => 'Color',
        ];
    @endphp
    @if($itemsPersonalizados->isNotEmpty())
    <div style="margin-bottom: 10px; border: 1px solid #ede9fe; border-radius: 6px; padding: 8px 10px; background-color: #faf5ff;">
        <p style="font-size: 9px; font-weight: bold; color: #7c3aed; text-transform: uppercase; margin: 0 0 6px 0;">Detalles de Personalizacion</p>
        @foreach($itemsPersonalizados as $item)
            @php
                $specs = $item->specs_personalizacion ?? [];
                $notas = $specs['notas'] ?? null;
                unset($specs['notas']);
                $tipoTexto = match($item->tipo_item) {
                    'restauracion'    => 'restauración',
                    'diseno_especial' => 'diseño especial',
                    'fabricar'        => 'para fabricar',
                    default           => 'ítem personalizado',
                };
            @endphp
            <div style="margin-bottom: 5px; padding-bottom: 5px; {{ $loop->last ? '' : 'border-bottom: 1px solid #ede9fe;' }}">
                <p style="font-size: 10px; font-weight: bold; color: #374151; margin: 0 0 2px 0;">
                    {{ $item->producto->nombre ?? $item->nombre_custom ?? 'Producto personalizado' }}
                    <span style="color: #7c3aed; font-weight: normal; font-size: 9px;">({{ $tipoTexto }})</span>
                </p>
                @if(!empty(array_filter($specs, fn($v) => $v !== null && $v !== '')))
                    <p style="font-size: 10px; color: #374151; margin: 0 0 2px 0;">
                        @foreach($specs as $key => $val)
                            @continue($val === null || $val === '')
                            <strong>{{ $etiquetasSpec[$key] ?? ucfirst(str_replace('_', ' ', $key)) }}:</strong> {{ $val }}@if(!$loop->last) &nbsp;·&nbsp; @endif
                        @endforeach
                    </p>
                @endif
                @if(!empty($notas))
                    <p style="font-size: 10px; color: #374151; margin: 0 0 2px 0; white-space: pre-wrap;"><strong>Notas:</strong> {{ $notas }}</p>
                @endif
                {{-- El boceto no se imprime: ocupaba media hoja por ítem. Se avisa
                     de que existe y de dónde verlo. --}}
                @if($item->boceto_url)
                    <p style="font-size: 9px; color: #7c3aed; margin: 0;">
                        Tiene boceto — se consulta en el sistema, en el detalle de la orden.
                    </p>
                @endif
            </div>
        @endforeach
    </div>
    @endif

    <!-- Historial de Pagos -->
    <div style="margin-bottom: 10px;">
        <p style="font-size: 9px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin: 0 0 4px 0;">Historial de Pagos ({{ count($orden->pagos) }})</p>
        @if(count($orden->pagos) > 0)
            <table style="width: 100%; border-collapse: collapse; font-size: 10px;">
                <thead>
                    <tr style="background-color: #16a34a; color: white;">
                        <th style="padding: 5px; text-align: left;">Tipo</th>
                        <th style="padding: 5px; text-align: left;">Método</th>
                        <th style="padding: 5px; text-align: left;">Referencia</th>
                        <th style="padding: 5px; text-align: right;">Monto</th>
                        <th style="padding: 5px; text-align: right;">Fecha</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($orden->pagos as $pago)
                        <tr style="border-bottom: 1px solid #e5e7eb;">
                            <td style="padding: 4px 5px;">
                                @switch($pago->tipo)
                                    @case('anticipo') Anticipo @break
                                    @case('abono') Abono @break
                                    @case('saldo_final') Saldo Final @break
                                    @default {{ $pago->tipo }}
                                @endswitch
                            </td>
                            <td style="padding: 4px 5px; text-transform: capitalize;">{{ $pago->metodo }}</td>
                            <td style="padding: 4px 5px;">{{ $pago->referencia ?? '—' }}</td>
                            <td style="padding: 4px 5px; text-align: right; color: #16a34a; font-weight: bold;">$ {{ number_format($pago->monto, 2) }}</td>
                            <td style="padding: 4px 5px; text-align: right;">{{ \Carbon\Carbon::parse($pago->created_at)->format('d/m/Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p style="font-size: 10px; color: #9ca3af; text-align: center; padding: 8px; background-color: #f9fafb; border-radius: 6px;">No hay pagos registrados.</p>
        @endif
    </div>

    <!-- Firmas -->
    <div style="border: 1px solid #e5e7eb; border-radius: 6px; padding: 8px 10px;">
        <p style="font-size: 9px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin: 0 0 6px 0;">Confirmación de la Orden</p>
        <table style="width: 100%;">
            <tr>
                <!-- Firma del vendedor -->
                <td style="width: 50%; padding-right: 12px; vertical-align: bottom;">
                    <div style="border-top: 1px solid #374151; padding-top: 4px; height: 46px;">
                        @if($firmaVendedor)
                            <img src="{{ $firmaVendedor }}" style="max-height: 42px; max-width: 170px; display: block;" alt="Firma vendedor" />
                        @endif
                    </div>
                    <p style="font-size: 10px; color: #374151; margin: 2px 0 0 0;"><strong>{{ $orden->vendedor->nombre ?? 'Vendedor' }}</strong></p>
                    <p style="font-size: 8px; color: #6b7280; margin: 0;">Vendedor — Decasa</p>
                </td>
                <!-- Firma del cliente -->
                <td style="width: 50%; padding-left: 12px; vertical-align: bottom; border-left: 1px solid #e5e7eb;">
                    <div style="border-top: 1px solid #374151; padding-top: 4px; height: 46px;">
                        @if($firmaCliente)
                            <img src="{{ $firmaCliente }}" style="max-height: 42px; max-width: 170px; display: block;" alt="Firma cliente" />
                        @endif
                    </div>
                    <p style="font-size: 10px; color: #374151; margin: 2px 0 0 0;"><strong>{{ $orden->cliente->nombre ?? 'Cliente' }}</strong></p>
                    <p style="font-size: 8px; color: #6b7280; margin: 0;">{{ $orden->cliente->cedula ? 'C.C. / NIT: ' . $orden->cliente->cedula : 'Cliente' }}</p>
                </td>
            </tr>
        </table>
        <p style="font-size: 8px; color: #9ca3af; margin: 6px 0 0 0; text-align: center;">
            Al firmar, ambas partes confirman haber leído y aceptado los términos de esta orden.
        </p>
    </div>

    <!-- Footer -->
    <div style="border-top: 1px solid #e5e7eb; padding-top: 5px; margin-top: 8px; text-align: center;">
        <p style="font-size: 8px; color: #9ca3af; margin: 0;">Documento generado el {{ now()->format('d/m/Y H:i:s') }} | Decasa - Sistema de Gestión</p>
    </div>
</body>
</html>
