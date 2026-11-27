<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Trabajo del taller — {{ $periodo }}</title>
    {{--
        Hoja de trabajo de cada trabajador del taller (Nómina → Trabajo del taller).
        Tamaño carta, corta a propósito (usuario, 2026-11-27: "que se entienda
        bien y que no sea tan largo; si es mucho, 2 hojas"): solo qué hizo y su
        nombre, sin horas, calidad ni para quién era. Una página (o dos) por
        persona, para cortarla y entregársela.
    --}}
    <style>
        @page { margin: 28px 34px; }
        body { font-family: 'Helvetica', Arial, sans-serif; font-size: 11px; color: #222; margin: 0; }
        .persona { page-break-after: always; }
        .persona:last-child { page-break-after: auto; }
        .cab { width: 100%; border-bottom: 2px solid #2563eb; padding-bottom: 6px; margin-bottom: 10px; }
        .cab h1 { font-size: 17px; margin: 0; color: #111; }
        .cab p { font-size: 10px; color: #6b7280; margin: 2px 0 0 0; }
        .proceso { font-size: 11px; font-weight: bold; color: #2563eb; text-transform: uppercase;
                   margin: 9px 0 2px 0; letter-spacing: .3px; }
        table.trabajo { width: 100%; border-collapse: collapse; }
        table.trabajo td { padding: 2.5px 4px; border-bottom: 1px solid #eef0f3; vertical-align: top; }
        td.cant { width: 46px; text-align: right; font-weight: bold; white-space: nowrap; }
        .version { color: #6b7280; }
        .marca { color: #92400e; font-size: 9.5px; }
        .total { margin-top: 8px; font-size: 11px; }
        .mano h3 { font-size: 11px; margin: 14px 0 0 0; text-transform: uppercase; color: #374151; }
        .mano p { font-size: 9.5px; color: #6b7280; margin: 1px 0 4px 0; }
        table.lineas { width: 100%; border-collapse: collapse; }
        table.lineas td { height: 19px; border-bottom: 1px solid #9ca3af; }
        table.firmas { width: 100%; margin-top: 26px; font-size: 10px; color: #374151; }
        table.firmas td { width: 50%; padding-top: 4px; }
        .raya { border-top: 1px solid #374151; width: 88%; padding-top: 3px; }
    </style>
</head>
<body>
@foreach($personas as $p)
    @php
        // Mucho trabajo: menos renglones en blanco, para que no pase de 2 hojas.
        $lineasAMano = $p['trabajos'] > 30 ? 4 : 8;
        $cant = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    @endphp
    <div class="persona">
        <table class="cab">
            <tr>
                <td>
                    <h1>{{ $p['nombre'] }}</h1>
                    <p>Trabajo del taller · {{ $periodo }}</p>
                </td>
                <td style="text-align: right; vertical-align: bottom;">
                    <p>{{ \App\Support\PlantillasDocumento::empresa()['nombre_completo'] }}</p>
                </td>
            </tr>
        </table>

        @forelse($p['grupos'] as $g)
            <div class="proceso">{{ $g['proceso'] }}</div>
            <table class="trabajo">
                @foreach($g['renglones'] as $r)
                    <tr>
                        <td>
                            {{ $r['producto'] }}@if($r['version'])<span class="version"> · {{ $r['version'] }}</span>@endif
                            @if($r['marca'])<span class="marca"> ({{ $r['marca'] }})</span>@endif
                        </td>
                        <td class="cant">{{ $cant($r['cantidad']) }}</td>
                    </tr>
                @endforeach
            </table>
        @empty
            <p style="color: #6b7280;">No terminó pasos en Producción en este periodo.</p>
        @endforelse

        @if($p['trabajos'] > 0)
            <div class="total">
                <strong>Total: {{ $cant($p['piezas']) }} {{ (float) $p['piezas'] === 1.0 ? 'pieza' : 'piezas' }}</strong>
                en {{ $p['trabajos'] }} {{ $p['trabajos'] === 1 ? 'trabajo' : 'trabajos' }} (sale de Producción)
            </div>
        @endif

        <div class="mano">
            <h3>Otros trabajos (escribir a mano)</h3>
            <p>Lo que no pasó por Producción. Producto, qué se le hizo y cuántos.</p>
            <table class="lineas">
                @for($i = 0; $i < $lineasAMano; $i++)
                    <tr><td></td></tr>
                @endfor
            </table>
        </div>

        <table class="firmas">
            <tr>
                <td><div class="raya">Firma del trabajador</div></td>
                <td><div class="raya">Revisó</div></td>
            </tr>
        </table>
    </div>
@endforeach
</body>
</html>
