<!DOCTYPE html>
{{--
    Remisión de surtido: la hoja que acompaña la mercancía.

    Se imprime y viaja con el envío, así que abajo lleva las dos firmas —quien
    entrega y quien recibe—. Lo que se compara al descargar es la columna de
    cantidades, por eso va grande y a la derecha.

    Una hoja por tienda. Un surtido puede ir a varias, y cada una se queda con
    la suya: mezclarlas obligaría al de la tienda a buscar sus renglones entre
    los de otras y a leer lo que no le corresponde.
--}}
<html>
<head>
    <meta charset="utf-8">
    <title>Surtido #{{ $surtido->id }}</title>
</head>
<body style="font-family: 'Helvetica', Arial, sans-serif; font-size: 11px; color: #333; margin: 0; padding: 14px 18px;">

@foreach($tiendas as $indice => $st)
    @php
        $items      = $st->items;
        $totalEnv   = $items->sum('cantidad');
        // Solo cuenta cuando ya se respondió: antes de eso, "0 aceptadas" se
        // leería como un rechazo y lo que pasa es que todavía no ha llegado.
        $respondido = in_array($st->estado, ['aceptado', 'rechazado'], true);
        $totalAcep  = $respondido ? $items->sum(fn ($i) => (int) ($i->cantidad_aceptada ?? 0)) : null;

        $estadoLabel = ['pendiente' => 'Pendiente de recibir', 'aceptado' => 'Recibido', 'rechazado' => 'Rechazado'];
        $estadoColor = ['pendiente' => '#f59e0b', 'aceptado' => '#10b981', 'rechazado' => '#ef4444'];
    @endphp

    <div @if($indice > 0) style="page-break-before: always;" @endif>

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
                    <h2 style="font-size: 16px; font-weight: bold; margin: 0;">Remisión de surtido #{{ $surtido->id }}</h2>
                    <p style="font-size: 10px; color: #666; margin: 2px 0 0 0;">
                        {{ $surtido->created_at->timezone('America/Bogota')->format('d/m/Y h:i a') }}
                    </p>
                    <span style="display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 10px; font-weight: bold; color: white; background-color: {{ $estadoColor[$st->estado] ?? '#666' }}; margin-top: 3px;">
                        {{ $estadoLabel[$st->estado] ?? $st->estado }}
                    </span>
                </td>
            </tr>
        </table>

        <!-- De dónde sale y a dónde va -->
        <table style="width: 100%; margin-bottom: 10px; border-collapse: collapse;">
            <tr>
                <td style="width: 50%; padding: 6px 10px; background-color: #f9fafb; border: 1px solid #e5e7eb; vertical-align: top;">
                    <span style="font-size: 9px; font-weight: bold; color: #6b7280; text-transform: uppercase;">Sale de</span>
                    <p style="font-size: 12px; font-weight: bold; margin: 2px 0 0 0;">{{ $origen }}</p>
                    <p style="font-size: 10px; color: #666; margin: 2px 0 0 0;">
                        Envía: {{ $surtido->supervisor?->nombre ?? '—' }}
                    </p>
                </td>
                <td style="width: 50%; padding: 6px 10px; background-color: #eff6ff; border: 1px solid #bfdbfe; vertical-align: top;">
                    <span style="font-size: 9px; font-weight: bold; color: #1d4ed8; text-transform: uppercase;">Llega a</span>
                    <p style="font-size: 12px; font-weight: bold; color: #1e3a8a; margin: 2px 0 0 0;">{{ $st->tienda?->nombre ?? '—' }}</p>
                    <p style="font-size: 10px; color: #1e40af; margin: 2px 0 0 0;">
                        Recibe: {{ $st->vendedorValidador?->nombre ?? '—' }}
                    </p>
                </td>
            </tr>
        </table>

        <!-- Lo que va -->
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px;">
            <thead>
                <tr style="background-color: #f3f4f6;">
                    <th style="text-align: left;  padding: 5px 8px; font-size: 10px; border: 1px solid #e5e7eb;">#</th>
                    <th style="text-align: left;  padding: 5px 8px; font-size: 10px; border: 1px solid #e5e7eb;">Producto</th>
                    <th style="text-align: center; padding: 5px 8px; font-size: 10px; border: 1px solid #e5e7eb; width: 70px;">Enviado</th>
                    @if($respondido)
                        <th style="text-align: center; padding: 5px 8px; font-size: 10px; border: 1px solid #e5e7eb; width: 70px;">Recibido</th>
                    @else
                        {{-- Sin responder, la casilla va en blanco: la hoja se usa
                             para ir marcando a mano mientras se descarga. --}}
                        <th style="text-align: center; padding: 5px 8px; font-size: 10px; border: 1px solid #e5e7eb; width: 70px;">Recibido</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($items as $i => $item)
                    @php
                        $detalle  = $item->detalleVariante();
                        $aceptada = (int) ($item->cantidad_aceptada ?? 0);
                        $faltante = $respondido && $aceptada < (int) $item->cantidad;
                    @endphp
                    <tr @if($faltante) style="background-color: #fef2f2;" @endif>
                        <td style="padding: 4px 8px; border: 1px solid #e5e7eb; font-size: 10px; color: #9ca3af;">{{ $i + 1 }}</td>
                        <td style="padding: 4px 8px; border: 1px solid #e5e7eb;">
                            <span style="font-size: 11px;">{{ $item->producto?->nombre ?? 'Producto #' . $item->producto_id }}</span>
                            @if($detalle)
                                <span style="font-size: 9px; color: #6b7280; display: block;">{{ $detalle }}</span>
                            @endif
                        </td>
                        <td style="padding: 4px 8px; border: 1px solid #e5e7eb; text-align: center; font-size: 13px; font-weight: bold;">
                            {{ $item->cantidad }}
                        </td>
                        <td style="padding: 4px 8px; border: 1px solid #e5e7eb; text-align: center; font-size: 13px; font-weight: bold; color: {{ $faltante ? '#dc2626' : '#111' }};">
                            {{ $respondido ? $aceptada : '' }}
                        </td>
                    </tr>
                @endforeach
                <tr style="background-color: #f9fafb;">
                    <td colspan="2" style="padding: 5px 8px; border: 1px solid #e5e7eb; text-align: right; font-size: 11px; font-weight: bold;">
                        Total de unidades
                    </td>
                    <td style="padding: 5px 8px; border: 1px solid #e5e7eb; text-align: center; font-size: 13px; font-weight: bold;">{{ $totalEnv }}</td>
                    <td style="padding: 5px 8px; border: 1px solid #e5e7eb; text-align: center; font-size: 13px; font-weight: bold; color: {{ $respondido && $totalAcep < $totalEnv ? '#dc2626' : '#111' }};">
                        {{ $respondido ? $totalAcep : '' }}
                    </td>
                </tr>
            </tbody>
        </table>

        @if($respondido && $totalAcep < $totalEnv)
            <p style="font-size: 10px; color: #991b1b; background-color: #fef2f2; border: 1px solid #fecaca; padding: 5px 8px; margin: 0 0 8px 0;">
                Faltaron {{ $totalEnv - $totalAcep }} unidad(es) de las que se enviaron.
            </p>
        @endif

        @if($st->notas_vendedor)
            <p style="font-size: 10px; color: #444; margin: 0 0 8px 0;">
                <strong>Nota de quien recibe:</strong> {{ $st->notas_vendedor }}
            </p>
        @endif
        @if($surtido->notas)
            <p style="font-size: 10px; color: #444; margin: 0 0 8px 0;">
                <strong>Notas del envío:</strong> {{ $surtido->notas }}
            </p>
        @endif

        <!-- Firmas: la hoja viaja con la mercancía y se firma al descargar -->
        <table style="width: 100%; margin-top: 26px; border-collapse: collapse;">
            <tr>
                <td style="width: 50%; padding: 0 14px 0 0; text-align: center; vertical-align: bottom;">
                    <div style="border-top: 1px solid #9ca3af; padding-top: 3px;">
                        <span style="font-size: 10px; color: #6b7280;">Entrega — {{ $surtido->supervisor?->nombre ?? '' }}</span>
                    </div>
                </td>
                <td style="width: 50%; padding: 0 0 0 14px; text-align: center; vertical-align: bottom;">
                    <div style="border-top: 1px solid #9ca3af; padding-top: 3px;">
                        <span style="font-size: 10px; color: #6b7280;">Recibe — {{ $st->vendedorValidador?->nombre ?? '' }}</span>
                    </div>
                </td>
            </tr>
        </table>

        <p style="font-size: 8px; color: #9ca3af; text-align: center; margin-top: 10px;">
            Surtido #{{ $surtido->id }} · {{ $st->tienda?->nombre }} ·
            Impreso el {{ now()->timezone('America/Bogota')->format('d/m/Y h:i a') }}
        </p>
    </div>
@endforeach

</body>
</html>
