<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Liquidación — {{ $d['nombre'] ?? '' }}</title>
</head>
@php $pesos = fn ($n) => '$' . number_format((float) $n, 0, ',', '.'); @endphp
<body style="font-family: 'Helvetica', Arial, sans-serif; font-size: 12px; color: #333; margin: 0; padding: 24px;">

    <table style="width: 100%; border-bottom: 2px solid #2563eb; padding-bottom: 10px; margin-bottom: 18px;">
        <tr>
            <td><h1 style="font-size: 20px; margin: 0; color: #2563eb;">{{ config('app.name', 'Decasa') }}</h1></td>
            <td style="text-align: right;">
                <h2 style="font-size: 16px; margin: 0; color: #2563eb;">LIQUIDACIÓN DEL CONTRATO</h2>
                <p style="font-size: 11px; color: #6b7280; margin: 3px 0 0 0;">N.º {{ $l->id }} · {{ $l->fecha_pago->format('d/m/Y') }}</p>
            </td>
        </tr>
    </table>

    <table style="width: 100%; margin-bottom: 16px; font-size: 11px;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                <p style="margin: 2px 0;"><strong>Trabajador:</strong> {{ $d['nombre'] }}</p>
                <p style="margin: 2px 0;"><strong>Cédula:</strong> {{ $d['cedula'] ?? '—' }}</p>
                <p style="margin: 2px 0;"><strong>Cargo:</strong> {{ $d['cargo'] ?? '—' }}</p>
                <p style="margin: 2px 0;"><strong>Contrato:</strong> {{ \App\Models\NominaLiquidacion::CONTRATOS[$d['tipo_contrato'] ?? ''] ?? '—' }}</p>
            </td>
            <td style="width: 50%; vertical-align: top;">
                <p style="margin: 2px 0;"><strong>Ingreso:</strong> {{ $d['fecha_ingreso'] }}</p>
                <p style="margin: 2px 0;"><strong>Retiro:</strong> {{ $d['fecha_retiro'] }}</p>
                <p style="margin: 2px 0;"><strong>Días de servicio:</strong> {{ $d['dias_servicio'] }}</p>
                <p style="margin: 2px 0;"><strong>Motivo:</strong> {{ $d['motivo_nombre'] }}</p>
                <p style="margin: 2px 0;"><strong>Sueldo:</strong> {{ $pesos($d['sueldo']['mensual'] ?? 0) }} al mes</p>
            </td>
        </tr>
    </table>

    <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
        <thead>
            <tr style="background: #f3f4f6;">
                <th style="text-align: left; padding: 6px; border-bottom: 1px solid #e5e7eb;">Concepto</th>
                <th style="text-align: left; padding: 6px; border-bottom: 1px solid #e5e7eb;">Detalle</th>
                <th style="text-align: right; padding: 6px; border-bottom: 1px solid #e5e7eb;">Valor</th>
            </tr>
        </thead>
        <tbody>
            @foreach($d['conceptos'] as $c)
                <tr>
                    <td style="padding: 6px; border-bottom: 1px solid #f3f4f6;">{{ $c['nombre'] }}</td>
                    <td style="padding: 6px; border-bottom: 1px solid #f3f4f6; color: #6b7280;">{{ $c['detalle'] ?? '' }}</td>
                    <td style="padding: 6px; border-bottom: 1px solid #f3f4f6; text-align: right;">{{ $pesos($c['monto']) }}</td>
                </tr>
            @endforeach
            @foreach($d['deducciones'] as $c)
                <tr>
                    <td style="padding: 6px; border-bottom: 1px solid #f3f4f6;">− {{ $c['nombre'] }}</td>
                    <td style="padding: 6px; border-bottom: 1px solid #f3f4f6;"></td>
                    <td style="padding: 6px; border-bottom: 1px solid #f3f4f6; text-align: right; color: #b91c1c;">−{{ $pesos($c['monto']) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="2" style="padding: 8px 6px; font-weight: bold; font-size: 13px;">TOTAL A PAGAR</td>
                <td style="padding: 8px 6px; font-weight: bold; font-size: 13px; text-align: right;">{{ $pesos($d['total']) }}</td>
            </tr>
        </tbody>
    </table>

    @if($l->estado === 'anulada')
        <p style="margin-top: 14px; color: #b91c1c; font-weight: bold;">ANULADA: {{ $l->motivo_anulacion }}</p>
    @endif

    <p style="margin-top: 18px; font-size: 10px; color: #6b7280;">
        Las prestaciones (prima, cesantías, intereses y vacaciones) se calcularon con lo causado en cada pago de nómina
        del periodo. El trabajador declara recibir a satisfacción el valor de esta liquidación.
    </p>

    <table style="width: 100%; margin-top: 60px; font-size: 11px;">
        <tr>
            <td style="width: 45%; border-top: 1px solid #333; padding-top: 4px;">Firma del empleador</td>
            <td style="width: 10%;"></td>
            <td style="width: 45%; border-top: 1px solid #333; padding-top: 4px;">Firma del trabajador · C.C. {{ $d['cedula'] ?? '' }}</td>
        </tr>
    </table>
</body>
</html>
