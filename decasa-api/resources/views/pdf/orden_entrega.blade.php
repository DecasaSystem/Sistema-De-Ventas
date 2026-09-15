<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Orden de entrega — {{ $orden->referencia }}</title>
</head>
{{--
    El papel que lleva quien entrega. Es la hoja que ya se hacía a mano:
    datos del cliente, la lista de lo que va, el total, lo abonado y lo que
    hay que cobrar contra entrega, y un espacio para que firme quien recibe.

    Con entregas por producto, la lista es lo que FALTA por entregar (o lo
    que va en esta entrega, si se pidió para una entrega concreta); lo ya
    entregado se anota aparte para que no se lleve dos veces.
--}}
<body style="font-family: 'Helvetica', Arial, sans-serif; font-size: 12px; color: #111; margin: 0; padding: 22px 26px;">

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px;">
        <tr>
            <td style="width: 40%; vertical-align: middle;">
                @if(!empty($logoBase64))
                    <img src="{{ $logoBase64 }}" style="height: 48px; max-width: 180px; object-fit: contain;" alt="Decasa">
                @endif
            </td>
            <td style="text-align: right; vertical-align: middle;">
                <h2 style="font-size: 18px; font-weight: bold; margin: 0; letter-spacing: 1px;">ORDEN DE ENTREGA</h2>
                <p style="font-size: 11px; color: #555; margin: 3px 0 0 0;">
                    Orden {{ $orden->referencia }}
                    @if($parcial) · <strong>entrega parcial</strong> @endif
                </p>
            </td>
        </tr>
    </table>

    {{-- Datos del cliente: la misma cabecera de la hoja a mano --}}
    <table style="width: 100%; border-collapse: collapse; border: 1px solid #111; margin-bottom: 12px; font-size: 11.5px;">
        @php
            $filas = [
                ['FECHA',     now()->locale('es')->isoFormat('D [de] MMMM [de] YYYY')],
                ['NOMBRE',    $orden->cliente->nombre ?? '—'],
                ['CÉDULA',    $orden->cliente->cedula ?? '—'],
                ['DIRECCIÓN', $orden->direccion_envio ?: ($orden->cliente->direccion ?? '—')],
                ['TELÉFONO',  $orden->cliente->telefono ?? '—'],
                ['CIUDAD',    trim(($orden->ciudad_envio ?: '') . ($orden->departamento_envio ? ', ' . $orden->departamento_envio : '')) ?: '—'],
                ['ASESOR',    $orden->vendedor->nombre ?? '—'],
            ];
        @endphp
        @foreach($filas as [$k, $v])
            <tr>
                <td style="width: 90px; padding: 4px 8px; border-bottom: 1px solid #ccc; font-weight: bold; text-transform: uppercase; color: #333;">{{ $k }}:</td>
                <td style="padding: 4px 8px; border-bottom: 1px solid #ccc;">{{ $v }}</td>
            </tr>
        @endforeach
    </table>

    {{-- Lo que va --}}
    <table style="width: 100%; border-collapse: collapse; border: 1px solid #111; font-size: 11.5px;">
        <thead>
            <tr style="background: #111; color: #fff;">
                <th style="padding: 6px 8px; text-align: left;">PRODUCTO</th>
                <th style="padding: 6px 8px; text-align: center; width: 60px;">CANT.</th>
                <th style="padding: 6px 8px; text-align: right; width: 110px;">VALOR</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lineas as $l)
                <tr>
                    <td style="padding: 6px 8px; border-bottom: 1px dotted #999;">
                        {{ mb_strtoupper($l['nombre']) }}
                        @if($l['variante'])
                            <br><span style="font-weight: bold; color: #b91c1c;">{{ $l['variante'] }}</span>
                        @endif
                    </td>
                    <td style="padding: 6px 8px; border-bottom: 1px dotted #999; text-align: center;">{{ $l['cantidad'] }}</td>
                    <td style="padding: 6px 8px; border-bottom: 1px dotted #999; text-align: right;">$ {{ number_format($l['valor'], 0, ',', '.') }}</td>
                </tr>
            @endforeach
            {{-- Renglones en blanco, como en la hoja de siempre: para anotar a mano --}}
            @for($i = count($lineas); $i < 8; $i++)
                <tr>
                    <td style="padding: 9px 8px; border-bottom: 1px dotted #999;">&nbsp;</td>
                    <td style="border-bottom: 1px dotted #999;"></td>
                    <td style="border-bottom: 1px dotted #999;"></td>
                </tr>
            @endfor
        </tbody>
        <tfoot>
            {{-- En una parcial se separa lo que va hoy del total del pedido:
                 si no, quien recibe suma los renglones y no le cuadra. --}}
            @if($parcial)
                <tr>
                    <td colspan="2" style="padding: 6px 8px; text-align: right; font-weight: bold; border-top: 1px solid #111;">VALOR DE ESTA ENTREGA</td>
                    <td style="padding: 6px 8px; text-align: right; font-weight: bold; border-top: 1px solid #111;">$ {{ number_format($valorEntrega, 0, ',', '.') }}</td>
                </tr>
            @endif
            <tr>
                <td colspan="2" style="padding: 6px 8px; text-align: right; font-weight: bold; {{ $parcial ? '' : 'border-top: 1px solid #111;' }}">TOTAL PEDIDO</td>
                <td style="padding: 6px 8px; text-align: right; font-weight: bold; {{ $parcial ? '' : 'border-top: 1px solid #111;' }}">$ {{ number_format($totalPedido, 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td colspan="2" style="padding: 6px 8px; text-align: right; font-weight: bold;">ABONOS</td>
                <td style="padding: 6px 8px; text-align: right;">$ {{ number_format($abonos, 0, ',', '.') }}</td>
            </tr>
            <tr style="background: #f3f4f6;">
                <td colspan="2" style="padding: 7px 8px; text-align: right; font-weight: bold; font-size: 12.5px;">
                    {{ $parcial ? 'SALDO DE LA ORDEN (se cobra en la última entrega)' : 'SALDO CONTRA ENTREGA — TOTAL A CANCELAR' }}
                </td>
                <td style="padding: 7px 8px; text-align: right; font-weight: bold; font-size: 12.5px;">$ {{ number_format($saldo, 0, ',', '.') }}</td>
            </tr>
        </tfoot>
    </table>

    {{-- Lo que NO va hoy. En una parcial es lo primero que pregunta quien
         recibe: "¿y el comedor?". Que el papel lo responda. --}}
    @if($pendientes->isNotEmpty())
        <table style="width: 100%; border-collapse: collapse; border: 1px dashed #b45309; margin-top: 10px; font-size: 10.5px; background: #fffbeb;">
            <tr>
                <td style="padding: 6px 8px; font-weight: bold; color: #92400e; text-transform: uppercase;">
                    Queda pendiente para otra entrega
                </td>
            </tr>
            @foreach($pendientes as $p)
                <tr>
                    <td style="padding: 3px 8px 3px 16px; color: #78350f;">
                        {{ mb_strtoupper($p['nombre']) }}
                        @if(!empty($p['variante'])) <span style="color: #b91c1c;">{{ $p['variante'] }}</span> @endif
                        ×{{ $p['cantidad'] }}
                        <span style="color: #a16207;">— {{ $p['motivo'] }}</span>
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    @if($yaEntregado->isNotEmpty())
        <p style="font-size: 10px; color: #555; margin: 8px 0 0 0;">
            Ya entregado antes (no va en esta hoja):
            {{ $yaEntregado->map(fn ($e) => $e['nombre'] . ' ×' . $e['cantidad'])->implode(', ') }}.
        </p>
    @endif

    @if($orden->notas)
        <p style="font-size: 10.5px; margin: 10px 0 0 0;"><strong>Notas:</strong> {{ $orden->notas }}</p>
    @endif

    {{-- Firma --}}
    <table style="width: 100%; margin-top: 38px;">
        <tr>
            <td style="width: 55%; vertical-align: bottom;">
                <div style="border-top: 1px solid #111; padding-top: 4px; margin-right: 30px;">
                    <p style="font-size: 11px; font-weight: bold; margin: 0;">RECIBE A SATISFACCIÓN</p>
                    <p style="font-size: 10px; color: #555; margin: 2px 0 0 0;">Nombre y cédula de quien recibe</p>
                </div>
            </td>
            <td style="width: 45%; vertical-align: bottom;">
                <div style="border-top: 1px solid #111; padding-top: 4px;">
                    <p style="font-size: 11px; font-weight: bold; margin: 0;">ENTREGA</p>
                    <p style="font-size: 10px; color: #555; margin: 2px 0 0 0;">{{ $entregador ?? 'Conductor / asesor' }}</p>
                </div>
            </td>
        </tr>
    </table>

    <p style="text-align: center; font-size: 9px; color: #888; margin-top: 22px;">
        Decasa — {{ $orden->tienda->nombre ?? '' }} · Generado el {{ now()->format('d/m/Y H:i') }}
    </p>
</body>
</html>
