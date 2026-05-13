<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Orden #{{ $orden->id }}</title>
</head>
<body style="font-family: 'Helvetica', Arial, sans-serif; font-size: 12px; color: #333; margin: 0; padding: 20px;">

    <!-- Header -->
    <table style="width: 100%; border-bottom: 2px solid #2563eb; padding-bottom: 10px; margin-bottom: 20px;">
        <tr>
            <td style="vertical-align: middle;">
                @if(!empty($logoBase64))
                    <img src="{{ $logoBase64 }}" style="height: 50px; max-width: 180px; object-fit: contain;" alt="Decasa">
                @else
                    <h1 style="font-size: 24px; font-weight: bold; color: #2563eb; margin: 0;">DECASA</h1>
                    <p style="font-size: 10px; color: #666; margin: 2px 0 0 0;">Sistema de Órdenes</p>
                @endif
            </td>
            <td style="text-align: right; vertical-align: middle;">
                <h2 style="font-size: 18px; font-weight: bold; margin: 0;">Orden #{{ $orden->id }}</h2>
                @php
                    $estadoLabel = [
                        'pendiente_anticipo' => 'Pendiente Anticipo',
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
                <span style="display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: bold; color: white; background-color: {{ $estadoColor[$orden->estado] ?? '#666' }}; margin-top: 5px;">
                    {{ $estadoLabel[$orden->estado] ?? $orden->estado }}
                </span>
            </td>
        </tr>
    </table>

    <!-- Info General -->
    <table style="width: 100%; margin-bottom: 20px; border-collapse: collapse;">
        <tr>
            <td style="width: 50%; vertical-align: top; padding-right: 10px;">
                <div style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px;">
                    <p style="font-size: 10px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin: 0 0 8px 0;">Información General</p>
                    <p style="margin: 4px 0; font-size: 11px;"><strong>Cliente:</strong> {{ $orden->cliente->nombre ?? 'N/A' }}</p>
                    <p style="margin: 4px 0; font-size: 11px;"><strong>Cédula / NIT:</strong> {{ $orden->cliente->cedula ?? 'N/A' }}</p>
                    <p style="margin: 4px 0; font-size: 11px;"><strong>Teléfono:</strong> {{ $orden->cliente->telefono ?? 'N/A' }}</p>
                    <p style="margin: 4px 0; font-size: 11px;"><strong>Tienda:</strong> {{ $orden->tienda->nombre ?? 'N/A' }}</p>
                    <p style="margin: 4px 0; font-size: 11px;"><strong>Vendedor:</strong> {{ $orden->vendedor->nombre ?? 'N/A' }}</p>
                    <p style="margin: 4px 0; font-size: 11px;"><strong>Canal:</strong> {{ ucfirst($orden->canal) }}</p>
                    <p style="margin: 4px 0; font-size: 11px;"><strong>Fecha compra:</strong> {{ \Carbon\Carbon::parse($orden->created_at)->format('d/m/Y H:i') }}</p>
                    @if($orden->ciudad_envio || $orden->direccion_envio)
                        <p style="margin: 8px 0 4px 0; font-size: 10px; font-weight: bold; color: #6b7280; text-transform: uppercase;">Envío</p>
                        @if($orden->ciudad_envio)
                            <p style="margin: 4px 0; font-size: 11px;"><strong>Ciudad:</strong> {{ $orden->ciudad_envio }}</p>
                        @endif
                        @if($orden->direccion_envio)
                            <p style="margin: 4px 0; font-size: 11px;"><strong>Dirección:</strong> {{ $orden->direccion_envio }}</p>
                        @endif
                    @endif
                    @if($orden->notas)
                        <p style="margin: 4px 0; font-size: 11px;"><strong>Notas:</strong> {{ $orden->notas }}</p>
                    @endif
                </div>
            </td>
            <td style="width: 50%; vertical-align: top; padding-left: 10px;">
                <div style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px;">
                    <p style="font-size: 10px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin: 0 0 8px 0;">Resumen Financiero</p>
                    <table style="width: 100%; font-size: 11px;">
                        <tr>
                            <td style="padding: 3px 0;"><strong>Total:</strong></td>
                            <td style="text-align: right; font-weight: bold;">$ {{ number_format($orden->valor_total, 2) }}</td>
                        </tr>
                        <tr>
                            <td style="padding: 3px 0;"><strong>Pagado:</strong></td>
                            <td style="text-align: right; color: #16a34a; font-weight: bold;">$ {{ number_format($orden->total_pagado, 2) }}</td>
                        </tr>
                        <tr>
                            <td style="padding: 3px 0;"><strong>Saldo:</strong></td>
                            <td style="text-align: right; color: #dc2626; font-weight: bold;">$ {{ number_format($orden->saldo_pendiente, 2) }}</td>
                        </tr>
                    </table>
                    <div style="margin-top: 8px;">
                        <div style="width: 100%; background-color: #e5e7eb; border-radius: 10px; height: 12px; overflow: hidden;">
                            <div style="width: {{ $orden->porcentaje_pagado }}%; background-color: {{ $orden->porcentaje_pagado >= 100 ? '#10b981' : '#2563eb' }}; height: 100%; border-radius: 10px;"></div>
                        </div>
                        <p style="text-align: right; font-size: 10px; color: #6b7280; margin: 3px 0 0 0;">{{ $orden->porcentaje_pagado }}% pagado</p>
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <!-- Ítems -->
    <div style="margin-bottom: 20px;">
        <p style="font-size: 10px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin: 0 0 8px 0;">Ítems ({{ count($orden->items) }})</p>
        <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
            <thead>
                <tr style="background-color: #2563eb; color: white;">
                    <th style="padding: 8px; text-align: center; width: 30px;">#</th>
                    <th style="padding: 8px; text-align: left;">Producto</th>
                    <th style="padding: 8px; text-align: center;">Cant.</th>
                    <th style="padding: 8px; text-align: right;">P. Unit.</th>
                    <th style="padding: 8px; text-align: right;">Subtotal</th>
                    <th style="padding: 8px; text-align: center;">Entrega</th>
                </tr>
            </thead>
            <tbody>
                @foreach($orden->items as $idx => $item)
                    <tr style="border-bottom: 1px solid #e5e7eb; {{ $loop->even ? 'background-color:#f9fafb;' : '' }}">
                        <td style="padding: 8px; text-align: center; color: #6b7280; font-size: 10px;">{{ $idx + 1 }}</td>
                        <td style="padding: 8px;">
                            {{ $item->producto->nombre ?? 'N/A' }}
                            @if($item->es_personalizado)
                                <span style="display: inline-block; padding: 1px 6px; background-color: #ede9fe; color: #7c3aed; font-size: 9px; border-radius: 8px; margin-left: 4px;">Personalizado</span>
                            @endif
                            @php
                                $specs = $item->specs_personalizacion ?? [];
                                $marca = $specs['variante_marca'] ?? null;
                                $color = $specs['variante_color'] ?? null;
                            @endphp
                            @if($marca || $color)
                                <br><span style="font-size: 9px; color: #7c3aed;">🧵 {{ implode(' · ', array_filter([$marca, $color])) }}</span>
                            @endif
                            @if(!empty($specs['descripcion']))
                                <br><span style="font-size: 9px; color: #6b7280;">{{ $specs['descripcion'] }}</span>
                            @endif
                            @if($item->tienda_origen_id)
                                <br><span style="font-size: 9px; color: #d97706;">📍 Otra tienda</span>
                            @endif
                        </td>
                        <td style="padding: 8px; text-align: center;">{{ $item->cantidad }}</td>
                        <td style="padding: 8px; text-align: right;">$ {{ number_format($item->precio_unitario, 2) }}</td>
                        <td style="padding: 8px; text-align: right; font-weight: bold;">$ {{ number_format($item->cantidad * $item->precio_unitario, 2) }}</td>
                        <td style="padding: 8px; text-align: center; font-size: 10px; color: {{ $item->fecha_entrega_prom ? '#374151' : '#9ca3af' }};">
                            {{ $item->fecha_entrega_prom ? \Carbon\Carbon::parse($item->fecha_entrega_prom)->format('d/m/Y') : '—' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="background-color: #eff6ff;">
                    <td colspan="4" style="padding: 8px; text-align: right; font-weight: bold;">TOTAL:</td>
                    <td style="padding: 8px; text-align: right; font-weight: bold; font-size: 13px; color: #2563eb;">$ {{ number_format($orden->valor_total, 2) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Detalles de Personalización -->
    @php $itemsPersonalizados = $orden->items->where('es_personalizado', true); @endphp
    @if($itemsPersonalizados->isNotEmpty())
    <div style="margin-bottom: 20px; border: 1px solid #ede9fe; border-radius: 8px; padding: 16px; background-color: #faf5ff;">
        <p style="font-size: 10px; font-weight: bold; color: #7c3aed; text-transform: uppercase; margin: 0 0 12px 0;">✦ Detalles de Personalización</p>
        @foreach($itemsPersonalizados as $item)
            @php $specs = $item->specs_personalizacion ?? []; @endphp
            <div style="margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #ede9fe;">
                <p style="font-size: 11px; font-weight: bold; color: #374151; margin: 0 0 6px 0;">
                    {{ $item->producto->nombre ?? 'Producto' }}
                    <span style="color: #7c3aed; font-weight: normal; font-size: 10px;">(ítem personalizado)</span>
                </p>
                @if(!empty($specs['descripcion']))
                    <p style="font-size: 10px; font-weight: bold; color: #6b7280; margin: 0 0 2px 0;">Especificaciones / Medidas:</p>
                    <p style="font-size: 11px; color: #374151; margin: 0 0 8px 0; white-space: pre-wrap;">{{ $specs['descripcion'] }}</p>
                @endif
                @if(!empty($bocetosBase64[$item->id]))
                    <p style="font-size: 10px; font-weight: bold; color: #6b7280; margin: 0 0 4px 0;">Boceto del vendedor:</p>
                    <img src="{{ $bocetosBase64[$item->id] }}" style="max-width: 100%; max-height: 220px; border: 1px solid #e5e7eb; border-radius: 6px; display: block;" alt="Boceto" />
                @endif
            </div>
        @endforeach
    </div>
    @endif

    <!-- Historial de Pagos -->
    <div style="margin-bottom: 20px;">
        <p style="font-size: 10px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin: 0 0 8px 0;">Historial de Pagos ({{ count($orden->pagos) }})</p>
        @if(count($orden->pagos) > 0)
            <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                <thead>
                    <tr style="background-color: #16a34a; color: white;">
                        <th style="padding: 8px; text-align: left;">Tipo</th>
                        <th style="padding: 8px; text-align: left;">Método</th>
                        <th style="padding: 8px; text-align: left;">Referencia</th>
                        <th style="padding: 8px; text-align: right;">Monto</th>
                        <th style="padding: 8px; text-align: right;">Fecha</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($orden->pagos as $pago)
                        <tr style="border-bottom: 1px solid #e5e7eb;">
                            <td style="padding: 8px;">
                                @switch($pago->tipo)
                                    @case('anticipo') Anticipo @break
                                    @case('abono') Abono @break
                                    @case('saldo_final') Saldo Final @break
                                    @default {{ $pago->tipo }}
                                @endswitch
                            </td>
                            <td style="padding: 8px; text-transform: capitalize;">{{ $pago->metodo }}</td>
                            <td style="padding: 8px;">{{ $pago->referencia ?? '—' }}</td>
                            <td style="padding: 8px; text-align: right; color: #16a34a; font-weight: bold;">$ {{ number_format($pago->monto, 2) }}</td>
                            <td style="padding: 8px; text-align: right;">{{ \Carbon\Carbon::parse($pago->created_at)->format('d/m/Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p style="font-size: 11px; color: #9ca3af; text-align: center; padding: 15px; background-color: #f9fafb; border-radius: 8px;">No hay pagos registrados.</p>
        @endif
    </div>

    <!-- Firmas -->
    <div style="margin-bottom: 20px; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px;">
        <p style="font-size: 10px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin: 0 0 12px 0;">Confirmación de la Orden</p>
        <table style="width: 100%;">
            <tr>
                <!-- Firma del vendedor -->
                <td style="width: 50%; padding-right: 16px; vertical-align: bottom;">
                    <div style="border-top: 1px solid #374151; padding-top: 8px; min-height: 80px; display: flex; align-items: flex-end;">
                        @if($firmaVendedor)
                            <img src="{{ $firmaVendedor }}" style="max-height: 60px; max-width: 200px; display: block; margin-bottom: 4px;" alt="Firma vendedor" />
                        @else
                            <div style="height: 50px;"></div>
                        @endif
                    </div>
                    <p style="font-size: 10px; color: #374151; margin: 4px 0 0 0;"><strong>{{ $orden->vendedor->nombre ?? 'Vendedor' }}</strong></p>
                    <p style="font-size: 9px; color: #6b7280; margin: 2px 0 0 0;">Vendedor — Decasa</p>
                </td>
                <!-- Firma del cliente -->
                <td style="width: 50%; padding-left: 16px; vertical-align: bottom; border-left: 1px solid #e5e7eb;">
                    <div style="border-top: 1px solid #374151; padding-top: 8px; min-height: 80px; display: flex; align-items: flex-end;">
                        @if($firmaCliente)
                            <img src="{{ $firmaCliente }}" style="max-height: 60px; max-width: 200px; display: block; margin-bottom: 4px;" alt="Firma cliente" />
                        @else
                            <div style="height: 50px;"></div>
                        @endif
                    </div>
                    <p style="font-size: 10px; color: #374151; margin: 4px 0 0 0;"><strong>{{ $orden->cliente->nombre ?? 'Cliente' }}</strong></p>
                    <p style="font-size: 9px; color: #6b7280; margin: 2px 0 0 0;">{{ $orden->cliente->cedula ? 'C.C. / NIT: ' . $orden->cliente->cedula : 'Cliente' }}</p>
                </td>
            </tr>
        </table>
        <p style="font-size: 9px; color: #9ca3af; margin: 12px 0 0 0; text-align: center;">
            Al firmar, ambas partes confirman haber leído y aceptado los términos de esta orden.
        </p>
    </div>

    <!-- Footer -->
    <div style="border-top: 1px solid #e5e7eb; padding-top: 10px; text-align: center;">
        <p style="font-size: 9px; color: #9ca3af; margin: 0;">Documento generado el {{ now()->format('d/m/Y H:i:s') }} | Decasa - Sistema de Gestión</p>
    </div>
</body>
</html>
