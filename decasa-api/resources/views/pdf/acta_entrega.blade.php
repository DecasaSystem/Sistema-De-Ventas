<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Acta de satisfacción — Orden {{ $orden->referencia }}</title>
</head>
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
                <h2 style="font-size: 17px; font-weight: bold; margin: 0; color: #059669;">ACTA DE SATISFACCIÓN</h2>
                <p style="font-size: 11px; color: #6b7280; margin: 3px 0 0 0;">
                    Orden {{ $orden->referencia }}
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
                    <p style="margin: 3px 0; font-size: 11px;"><strong>Transportador:</strong> {{ $item->despacho->conductor->nombre ?? '—' }}</p>
                    <p style="margin: 3px 0; font-size: 11px;"><strong>Tienda:</strong> {{ $orden->tienda->nombre ?? '—' }}</p>
                </div>
            </td>
        </tr>
    </table>

    <!-- Ítems entregados -->
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
                @foreach($orden->items as $idx => $it)
                    <tr style="border-bottom: 1px solid #e5e7eb; {{ $loop->even ? 'background-color:#f9fafb;' : '' }}">
                        <td style="padding: 7px; text-align: center; color: #6b7280;">{{ $idx + 1 }}</td>
                        <td style="padding: 7px;">
                            {{ $it->producto->nombre ?? $it->nombre_custom ?? 'Producto personalizado' }}
                            {{-- Es el papel que lleva el que entrega: si no dice la
                                 tela, se la juega a adivinar cuál se lleva. --}}
                            @if($it->variante_texto)
                                <br><span style="font-weight: bold; color: #dc2626;">{{ $it->variante_texto }}</span>
                            @endif
                        </td>
                        <td style="padding: 7px; text-align: center;">{{ $it->cantidad }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <!-- Declaración -->
    <div style="border: 1px solid #d1d5db; border-radius: 8px; padding: 14px; margin-bottom: 18px;">
        <p style="font-size: 11px; line-height: 1.6; margin: 0;">
            Declaro que recibí los productos relacionados en esta acta, correspondientes a la orden
            <strong>{{ $orden->referencia }}</strong>, en la fecha y dirección indicadas.
            @if($item->conforme)
                Manifiesto que <strong>llegaron en buen estado y a satisfacción</strong>.
            @else
                Dejo constancia de la siguiente <strong>novedad</strong> al momento de recibir.
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
                    <p style="font-size: 9px; color: #9ca3af; margin: 2px 0 0 0;">Recibe conforme</p>
                </div>
            </td>
            <td style="width: 45%; text-align: center; vertical-align: bottom;">
                <div style="height: 55px;"></div>
                <div style="border-top: 1px solid #9ca3af; margin: 4px 20px 0 20px; padding-top: 5px;">
                    <p style="font-size: 11px; color: #374151; margin: 0;">{{ $item->despacho->conductor->nombre ?? '' }}</p>
                    <p style="font-size: 9px; color: #9ca3af; margin: 2px 0 0 0;">Transportador</p>
                </div>
            </td>
        </tr>
    </table>

    <p style="text-align: center; font-size: 9px; color: #9ca3af; margin-top: 28px;">
        SODEGE — {{ $orden->tienda->nombre ?? '' }} · Documento generado el {{ now()->format('d/m/Y H:i') }}
    </p>

</body>
</html>
