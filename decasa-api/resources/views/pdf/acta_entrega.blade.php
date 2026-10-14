<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $item->reciboReferencia() ? 'Recibo de entrega y pago ' . $item->reciboReferencia() : 'Acta de satisfacción' }} — Orden {{ $orden->referencia }}</title>
</head>
@php
    // Lo cobrado en esta entrega (uno por medio) y cómo queda la cuenta.
    $cuentas  = $cuentas ?? ['pagos' => collect(), 'hoy' => 0, 'antes' => 0, 'total' => (float) $orden->valor_total, 'saldo' => 0];
    $cobro    = $cuentas['hoy'] > 0;
    $nombreMetodo = ['efectivo' => 'Efectivo', 'transferencia' => 'Transferencia', 'tarjeta' => 'Tarjeta (datáfono)', 'addi' => 'Addi', 'otro' => 'Otro'];
    // Espacio duro: que el "$" no quede en una línea y el número en otra.
    $pesos    = fn ($v) => "$\u{00A0}" . number_format((float) $v, 0, ',', '.');
@endphp
<body style="font-family: 'Helvetica', Arial, sans-serif; font-size: 12px; color: #333; margin: 0; padding: 24px;">

    <!-- Encabezado -->
    <table style="width: 100%; border-bottom: 2px solid #059669; padding-bottom: 10px; margin-bottom: 20px;">
        <tr>
            <td style="vertical-align: middle;">
                @if(!empty($logoBase64))
                    <img src="{{ $logoBase64 }}" style="height: 46px; max-width: 170px; object-fit: contain;" alt="SODEGE">
                @else
                    <h1 style="font-size: 22px; font-weight: bold; color: #059669; margin: 0;">SODEGE</h1>
                @endif
            </td>
            <td style="text-align: right; vertical-align: middle;">
                @if($cobro)
                    <h2 style="font-size: 16px; font-weight: bold; margin: 0; color: #059669;">ACTA DE ENTREGA Y RECIBO DE PAGO</h2>
                    @if($item->reciboReferencia())
                        <p style="font-size: 13px; font-weight: bold; color: #111827; margin: 3px 0 0 0;">N.º {{ $item->reciboReferencia() }}</p>
                    @endif
                @else
                    <h2 style="font-size: 17px; font-weight: bold; margin: 0; color: #059669;">ACTA DE SATISFACCIÓN</h2>
                @endif
                <p style="font-size: 11px; color: #6b7280; margin: 3px 0 0 0;">
                    Orden {{ $orden->referencia }}
                    @if($pendientes->isNotEmpty()) · <strong style="color: #b45309;">entrega parcial</strong> @endif
                </p>
            </td>
        </tr>
    </table>

    <!-- Datos de la entrega -->
    <table style="width: 100%; margin-bottom: 18px; border-collapse: collapse;">
        <tr>
            <td style="width: 50%; vertical-align: top; padding-right: 10px;">
                <div style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px;">
                    <p style="font-size: 10px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin: 0 0 8px 0;">Cliente</p>
                    <p style="margin: 3px 0; font-size: 11px;"><strong>Nombre:</strong> {{ $orden->cliente->nombre ?? '—' }}</p>
                    @if($orden->cliente?->cedula)
                        <p style="margin: 3px 0; font-size: 11px;"><strong>C.C. / NIT:</strong> {{ $orden->cliente->cedula }}</p>
                    @endif
                    @if($orden->cliente?->telefono)
                        <p style="margin: 3px 0; font-size: 11px;"><strong>Teléfono:</strong> {{ $orden->cliente->telefono }}</p>
                    @endif
                    @if($orden->direccion_envio)
                        <p style="margin: 3px 0; font-size: 11px;"><strong>Dirección:</strong> {{ $orden->direccion_envio }}</p>
                    @endif
                    @if($orden->ciudad_envio)
                        <p style="margin: 3px 0; font-size: 11px;">{{ $orden->ciudad_envio }}{{ $orden->departamento_envio ? ', ' . $orden->departamento_envio : '' }}</p>
                    @endif
                </div>
            </td>
            <td style="width: 50%; vertical-align: top; padding-left: 10px;">
                <div style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px;">
                    <p style="font-size: 10px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin: 0 0 8px 0;">Entrega</p>
                    <p style="margin: 3px 0; font-size: 11px;">
                        <strong>Fecha:</strong>
                        {{ $item->entregado_at ? \Carbon\Carbon::parse($item->entregado_at)->format('d/m/Y H:i') : '—' }}
                    </p>
                    <p style="margin: 3px 0; font-size: 11px;"><strong>{{ $item->despacho->tipo === 'directa' ? 'Entregó' : 'Transportador' }}:</strong> {{ $item->despacho->conductor->nombre ?? $item->despacho->entregadoPor->nombre ?? '—' }}</p>
                    <p style="margin: 3px 0; font-size: 11px;"><strong>Tienda:</strong> {{ $orden->tienda->nombre ?? '—' }}</p>
                </div>
            </td>
        </tr>
    </table>

    <!-- Ítems entregados EN ESTA ENTREGA. Una orden puede entregarse por
         partes: el acta firma lo que llegó hoy, no el pedido entero. -->
    <div style="margin-bottom: 18px;">
        <p style="font-size: 10px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin: 0 0 8px 0;">Productos entregados</p>
        <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
            <thead>
                <tr style="background-color: #059669; color: white;">
                    <th style="padding: 7px; text-align: center; width: 30px;">#</th>
                    <th style="padding: 7px; text-align: left;">Producto</th>
                    <th style="padding: 7px; text-align: center; width: 60px;">Cant.</th>
                </tr>
            </thead>
            <tbody>
                @forelse($entregados as $idx => $it)
                    <tr style="border-bottom: 1px solid #e5e7eb; {{ $loop->even ? 'background-color:#f9fafb;' : '' }}">
                        <td style="padding: 7px; text-align: center; color: #6b7280;">{{ $idx + 1 }}</td>
                        <td style="padding: 7px;">
                            {{ $it['nombre'] }}
                            {{-- Es el papel que lleva el que entrega: si no dice la
                                 tela, se la juega a adivinar cuál se lleva. --}}
                            @if($it['variante'])
                                <br><span style="font-weight: bold; color: #dc2626;">{{ $it['variante'] }}</span>
                            @endif
                        </td>
                        <td style="padding: 7px; text-align: center;">{{ $it['cantidad'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" style="padding: 7px; color: #6b7280; text-align: center;">
                            Nada se quedó en esta entrega: todo lo que iba se devolvió.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if($devueltos->isNotEmpty())
            <p style="font-size: 10.5px; color: #991b1b; margin: 8px 0 0 0;">
                <strong>Se devolvió en el camión:</strong>
                {{ $devueltos->map(fn ($d) => $d['nombre'] . ' ×' . $d['cantidad'])->implode(', ') }}.
            </p>
        @endif

        @if($pendientes->isNotEmpty())
            <p style="font-size: 10.5px; color: #92400e; background: #fffbeb; border: 1px dashed #b45309; border-radius: 6px; padding: 6px 10px; margin: 8px 0 0 0;">
                <strong>Queda pendiente para otra entrega:</strong>
                {{ $pendientes->map(fn ($p) => $p['nombre'] . ' ×' . $p['cantidad'] . ' (' . $p['motivo'] . ')')->implode(', ') }}.
            </p>
        @endif
    </div>

    <!-- Pago recibido en la entrega. Es el soporte del efectivo: la
         transferencia y la tarjeta tienen además su propio comprobante. -->
    <div style="margin-bottom: 18px;">
        <p style="font-size: 10px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin: 0 0 8px 0;">
            {{ $cobro ? 'Pago recibido en la entrega' : 'Estado de cuenta' }}
        </p>
        @if($cobro)
            <table style="width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 8px;">
                <thead>
                    <tr style="background-color: #059669; color: white;">
                        <th style="padding: 7px; text-align: left;">Medio de pago</th>
                        <th style="padding: 7px; text-align: left;">Referencia</th>
                        <th style="padding: 7px; text-align: right; width: 120px;">Valor</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($cuentas['pagos'] as $p)
                        <tr style="border-bottom: 1px solid #e5e7eb;">
                            <td style="padding: 7px;">{{ $nombreMetodo[$p->metodo] ?? ucfirst((string) $p->metodo) }}</td>
                            <td style="padding: 7px; color: #6b7280;">{{ $p->referencia ?: '—' }}</td>
                            <td style="padding: 7px; text-align: right;">{{ $pesos($p->monto) }}</td>
                        </tr>
                    @endforeach
                    <tr>
                        <td colspan="2" style="padding: 7px; text-align: right; font-weight: bold;">Total recibido</td>
                        <td style="padding: 7px; text-align: right; font-weight: bold;">{{ $pesos($cuentas['hoy']) }}</td>
                    </tr>
                </tbody>
            </table>
        @endif
        <table style="width: 100%; border-collapse: collapse; font-size: 11px; background-color: #f9fafb; border: 1px solid #e5e7eb;">
            <tr>
                <td style="padding: 6px 10px;">Valor total del pedido</td>
                <td style="padding: 6px 10px; text-align: right;">{{ $pesos($cuentas['total']) }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 10px;">Abonado antes de esta entrega</td>
                <td style="padding: 6px 10px; text-align: right;">{{ $pesos($cuentas['antes']) }}</td>
            </tr>
            @if($cobro)
                <tr>
                    <td style="padding: 6px 10px;">Pagado en esta entrega</td>
                    <td style="padding: 6px 10px; text-align: right;">{{ $pesos($cuentas['hoy']) }}</td>
                </tr>
            @endif
            <tr style="border-top: 1px solid #d1d5db;">
                <td style="padding: 6px 10px; font-weight: bold;">Saldo pendiente</td>
                <td style="padding: 6px 10px; text-align: right; font-weight: bold; color: {{ $cuentas['saldo'] > 0.01 ? '#b45309' : '#047857' }};">
                    {{ $cuentas['saldo'] > 0.01 ? $pesos($cuentas['saldo']) : 'Pagado — $ 0' }}
                </td>
            </tr>
        </table>
    </div>

    <!-- Declaración: una sola firma cubre el recibo de los productos y el pago. -->
    <div style="border: 1px solid #d1d5db; border-radius: 8px; padding: 14px; margin-bottom: 18px;">
        <p style="font-size: 11px; line-height: 1.6; margin: 0;">
            Declaro que recibí los productos relacionados en esta acta, correspondientes a la orden
            <strong>{{ $orden->referencia }}</strong>, en la fecha y dirección indicadas.
            @if($pendientes->isNotEmpty())
                Esta es una <strong>entrega parcial</strong>: lo que aparece como pendiente se entregará aparte.
            @endif
            {{-- Sin firma no hay quien manifieste nada: ni conforme ni novedad. --}}
            @if($item->conforme === true)
                Manifiesto que <strong>llegaron en buen estado y a satisfacción</strong>.
            @elseif($item->conforme === false)
                Dejo constancia de la siguiente <strong>novedad</strong> al momento de recibir.
            @endif
            @if($cobro)
                Así mismo, hago constar que en esta entrega pagué la suma de <strong>{{ $pesos($cuentas['hoy']) }}</strong>
                por los medios indicados, que se abona a la orden {{ $orden->referencia }}.
            @endif
        </p>
    </div>

    @if(! $item->conforme && $item->observaciones_entrega)
    <div style="border: 1px solid #fcd34d; background-color: #fffbeb; border-radius: 8px; padding: 12px; margin-bottom: 18px;">
        <p style="font-size: 10px; font-weight: bold; color: #92400e; text-transform: uppercase; margin: 0 0 6px 0;">Novedad reportada</p>
        <p style="font-size: 11px; color: #78350f; margin: 0;">{{ $item->observaciones_entrega }}</p>
    </div>
    @endif

    <!-- Firma -->
    <table style="width: 100%; margin-top: 30px;">
        <tr>
            <td style="width: 55%; text-align: center; vertical-align: bottom;">
                @if(!empty($firmaBase64))
                    <img src="{{ $firmaBase64 }}" style="height: 55px; object-fit: contain;" alt="Firma">
                @else
                    <div style="height: 55px;"></div>
                @endif
                <div style="border-top: 1px solid #9ca3af; margin: 4px 20px 0 20px; padding-top: 5px;">
                    <p style="font-size: 11px; font-weight: bold; color: #374151; margin: 0;">
                        {{ $item->recibido_por_nombre ?? '—' }}
                    </p>
                    @if($item->recibido_por_cedula)
                        <p style="font-size: 10px; color: #6b7280; margin: 2px 0 0 0;">C.C. {{ $item->recibido_por_cedula }}</p>
                    @endif
                    <p style="font-size: 9px; color: #9ca3af; margin: 2px 0 0 0;">{{ $cobro ? 'Recibe conforme y declara el pago' : 'Recibe conforme' }}</p>
                    @if(! $item->firma_recibido_url && $item->firma_omitida_motivo)
                        <p style="font-size: 9px; color: #b45309; margin: 4px 0 0 0;">Sin firma: {{ $item->firma_omitida_motivo }}</p>
                    @endif
                </div>
            </td>
            <td style="width: 45%; text-align: center; vertical-align: bottom;">
                <div style="height: 55px;"></div>
                <div style="border-top: 1px solid #9ca3af; margin: 4px 20px 0 20px; padding-top: 5px;">
                    <p style="font-size: 11px; color: #374151; margin: 0;">{{ $item->despacho->conductor->nombre ?? $item->despacho->entregadoPor->nombre ?? '' }}</p>
                    <p style="font-size: 9px; color: #9ca3af; margin: 2px 0 0 0;">
                        {{ $item->despacho->tipo === 'directa' ? 'Entregó' : 'Transportador' }}{{ $cobro ? ' · recibió el pago' : '' }}
                    </p>
                </div>
            </td>
        </tr>
    </table>

    @if($cobro)
        <p style="text-align: center; font-size: 9px; color: #6b7280; margin-top: 22px; line-height: 1.5;">
            Este documento es el soporte de la entrega y del pago recibido. No reemplaza la factura electrónica de venta.
        </p>
    @endif
    <p style="text-align: center; font-size: 9px; color: #9ca3af; margin-top: {{ $cobro ? '6px' : '28px' }};">
        SODEGE — {{ $orden->tienda->nombre ?? '' }} · Documento generado el {{ now()->format('d/m/Y H:i') }}
    </p>

</body>
</html>
