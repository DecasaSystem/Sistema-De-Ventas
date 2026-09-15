<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Hoja de ruta — {{ $despacho->nombre_ruta ?? ('Ruta #' . $despacho->id) }}</title>
</head>
{{--
    La hoja que se lleva el conductor: las paradas en orden, con quién
    recibe, dónde, qué se baja del camión en cada una y cuánto hay que
    cobrar. Al final, cuánto plata debe volver en total.
--}}
<body style="font-family: 'Helvetica', Arial, sans-serif; font-size: 11.5px; color: #111; margin: 0; padding: 20px 24px;">

    <table style="width: 100%; border-collapse: collapse; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 12px;">
        <tr>
            <td style="width: 35%; vertical-align: middle;">
                @if(!empty($logoBase64))
                    <img src="{{ $logoBase64 }}" style="height: 44px; max-width: 170px; object-fit: contain;" alt="Decasa">
                @endif
            </td>
            <td style="text-align: right; vertical-align: middle;">
                <h2 style="font-size: 18px; font-weight: bold; margin: 0; letter-spacing: 1px;">HOJA DE RUTA</h2>
                <p style="font-size: 12px; margin: 3px 0 0 0;"><strong>{{ $despacho->nombre_ruta ?? ('Ruta #' . $despacho->id) }}</strong></p>
                <p style="font-size: 11px; color: #555; margin: 2px 0 0 0;">
                    {{ $despacho->fecha_despacho ? \Carbon\Carbon::parse($despacho->fecha_despacho)->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY') : '' }}
                </p>
            </td>
        </tr>
    </table>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 11px;">
        <tr>
            <td style="width: 33%; padding: 4px 0;"><strong>Conductor:</strong> {{ $despacho->conductor->nombre ?? 'Sin asignar' }}</td>
            <td style="width: 33%; padding: 4px 0;"><strong>Camión:</strong> {{ $despacho->camion->nombre ?? '—' }}{{ $despacho->camion?->placa ? ' · ' . $despacho->camion->placa : '' }}</td>
            <td style="width: 34%; padding: 4px 0;"><strong>Paradas:</strong> {{ count($paradas) }} · <strong>Armó:</strong> {{ $despacho->supervisor->nombre ?? '—' }}</td>
        </tr>
    </table>

    @if($despacho->instrucciones)
        <div style="border: 1px solid #f59e0b; background: #fffbeb; padding: 8px 10px; margin-bottom: 12px; font-size: 11px;">
            <strong>Instrucciones:</strong> {{ $despacho->instrucciones }}
        </div>
    @endif

    {{-- Una tabla por parada --}}
    @foreach($paradas as $p)
        <table style="width: 100%; border-collapse: collapse; border: 1px solid #111; margin-bottom: 10px; page-break-inside: avoid;">
            <tr style="background: #111; color: #fff;">
                <td style="padding: 5px 8px; width: 30px; font-weight: bold; font-size: 13px; text-align: center;">{{ $p['posicion'] }}</td>
                <td style="padding: 5px 8px; font-weight: bold;">{{ mb_strtoupper($p['cliente']) }}</td>
                <td style="padding: 5px 8px; text-align: right;">Orden {{ $p['referencia'] }}{{ $p['parcial'] ? ' · parcial' : '' }}</td>
            </tr>
            <tr>
                <td colspan="3" style="padding: 5px 8px; border-bottom: 1px solid #ccc; font-size: 11px;">
                    <strong>Dirección:</strong> {{ $p['direccion'] ?: '—' }}{{ $p['ciudad'] ? ' · ' . $p['ciudad'] : '' }}
                    &nbsp;&nbsp;<strong>Tel:</strong> {{ $p['telefono'] ?: '—' }}
                    @if($p['cedula']) &nbsp;&nbsp;<strong>C.C.:</strong> {{ $p['cedula'] }} @endif
                    &nbsp;&nbsp;<strong>Asesor:</strong> {{ $p['asesor'] ?: '—' }}
                </td>
            </tr>
            <tr>
                <td colspan="3" style="padding: 0;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                        <thead>
                            <tr style="background: #f3f4f6;">
                                <th style="padding: 4px 8px; text-align: left;">Se entrega</th>
                                <th style="padding: 4px 8px; text-align: center; width: 50px;">Cant.</th>
                                <th style="padding: 4px 8px; text-align: left; width: 200px;">Detalle</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($p['lineas'] as $l)
                                <tr>
                                    <td style="padding: 4px 8px; border-bottom: 1px dotted #bbb;">{{ mb_strtoupper($l['nombre']) }}</td>
                                    <td style="padding: 4px 8px; border-bottom: 1px dotted #bbb; text-align: center;">{{ $l['cantidad'] }}</td>
                                    <td style="padding: 4px 8px; border-bottom: 1px dotted #bbb; color: #b91c1c; font-weight: bold;">{{ $l['variante'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </td>
            </tr>
            {{-- Lo que el cliente va a preguntar: qué de su pedido NO viene hoy. --}}
            @if(!empty($p['pendientes']))
                <tr>
                    <td colspan="3" style="padding: 4px 8px; font-size: 10.5px; color: #92400e; background: #fffbeb; border-top: 1px dashed #b45309;">
                        <strong>NO va hoy (pendiente):</strong>
                        {{ collect($p['pendientes'])->map(fn ($q) => mb_strtoupper($q['nombre']) . ' ×' . $q['cantidad'] . ' — ' . $q['motivo'])->implode(' · ') }}
                    </td>
                </tr>
            @endif
            <tr style="background: #f9fafb;">
                <td colspan="3" style="padding: 5px 8px; font-size: 11px;">
                    <span>Total pedido: <strong>$ {{ number_format($p['total'], 0, ',', '.') }}</strong></span>
                    &nbsp;&nbsp;·&nbsp;&nbsp;
                    <span>Abonado: $ {{ number_format($p['abonado'], 0, ',', '.') }}</span>
                    &nbsp;&nbsp;·&nbsp;&nbsp;
                    @if($p['parcial'] && $p['saldo'] > 0)
                        <span>Debe $ {{ number_format($p['saldo'], 0, ',', '.') }} <em>(se cobra en la última entrega)</em></span>
                    @elseif($p['saldo'] > 0)
                        <span style="font-weight: bold; font-size: 12.5px;">COBRAR: $ {{ number_format($p['saldo'], 0, ',', '.') }}</span>
                    @else
                        <span style="font-weight: bold;">PAGADO</span>
                    @endif
                    @if($p['notas'])
                        <br><span style="color: #555;"><strong>Notas:</strong> {{ $p['notas'] }}</span>
                    @endif
                </td>
            </tr>
            <tr>
                <td colspan="3" style="padding: 6px 8px; font-size: 10px; color: #555;">
                    Recibe: ______________________________ &nbsp; C.C. ________________ &nbsp; Hora: ________ &nbsp; Novedad: ________________________
                </td>
            </tr>
        </table>
    @endforeach

    {{-- Lo que tiene que volver --}}
    <table style="width: 100%; border-collapse: collapse; border: 2px solid #111; margin-top: 6px; font-size: 12px;">
        <tr>
            <td style="padding: 7px 10px;"><strong>Total a cobrar en la ruta</strong></td>
            <td style="padding: 7px 10px; text-align: right; font-weight: bold; font-size: 14px;">$ {{ number_format($totalCobrar, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td colspan="2" style="padding: 0; border-top: 1px solid #ccc;">
                <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                    <tr>
                        <td style="padding: 8px 10px; width: 33%;">Efectivo: $ ______________</td>
                        <td style="padding: 8px 10px; width: 33%;">Transferencias: $ ______________</td>
                        <td style="padding: 8px 10px; width: 34%;">Datáfono: $ ______________</td>
                    </tr>
                    <tr>
                        <td colspan="3" style="padding: 8px 10px; border-top: 1px solid #eee;">Firma conductor: ______________________________ &nbsp;&nbsp; Recibió en caja: ______________________________</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <p style="text-align: center; font-size: 9px; color: #888; margin-top: 16px;">
        Decasa · Hoja de ruta generada el {{ now()->format('d/m/Y H:i') }}
    </p>
</body>
</html>
