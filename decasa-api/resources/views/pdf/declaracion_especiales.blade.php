<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Declaración de diseños especiales</title>
{{--
    La declaración para la fabricación de muebles con diseños y medidas
    especiales, como el papel que se firmaba antes: cada producto con lo que
    el cliente aprobó (ficha y bocetos, congelados en el anexo), la constancia
    con el texto de la versión que leyó, y su firma. Ver DeclaracionEspeciales.
--}}
<style>
  @page { margin: 28px 34px; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1f2937; line-height: 1.45; }
  .encabezado { border-bottom: 2px solid #1f2937; padding-bottom: 8px; margin-bottom: 10px; }
  .encabezado table { width: 100%; }
  .titulo { font-size: 12.5px; font-weight: bold; text-transform: uppercase; }
  .sub { font-size: 9px; color: #6b7280; }
  p { margin: 0 0 4px; text-align: justify; }
  .producto { border: 1px solid #d1d5db; border-radius: 4px; padding: 8px 10px; margin: 8px 0; page-break-inside: avoid; }
  .producto .nombre { font-size: 11px; font-weight: bold; }
  .producto .tipo { font-size: 8.5px; color: #1d4ed8; font-weight: bold; text-transform: uppercase; }
  .aprobado { color: #15803d; font-size: 8.5px; font-weight: bold; }
  h3 { font-size: 9px; text-transform: uppercase; color: #374151; margin: 7px 0 2px; }
  table.datos { width: 100%; border-collapse: collapse; }
  table.datos td { padding: 2px 4px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
  table.datos td.l { width: 120px; color: #6b7280; }
  .bocetos img { height: 120px; margin: 4px 6px 0 0; border: 1px solid #e5e7eb; }
  table.firma { width: 100%; margin-top: 14px; border-collapse: collapse; }
  table.firma td { padding: 4px 6px; vertical-align: bottom; }
  .lbl { color: #6b7280; font-size: 8.5px; text-transform: uppercase; }
  .val { font-size: 10.5px; font-weight: bold; border-bottom: 1px solid #9ca3af; padding-bottom: 2px; }
  .pie { margin-top: 12px; font-size: 8px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 6px; }
</style>
</head>
<body>
  @php
    $fecha     = ($anexo->firmado_at ?? now())->timezone('America/Bogota');
    $meses     = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    $fechaTxt  = $fecha->day . ' de ' . $meses[$fecha->month - 1] . ' de ' . $fecha->year;
    $aprobados = $anexo->respuestas['declaracion']['aprobados'] ?? [];
    $datos     = $anexo->respuestas['declaracion'] ?? [];
  @endphp

  <div class="encabezado">
    <table><tr>
      <td>
        <div class="titulo">{{ $texto['titulo'] }}</div>
        <div class="sub">Versión del documento {{ $anexo->declaracion_version === 'fabrica' ? 'original' : $anexo->declaracion_version }} · Firmada {{ $anexo->modo === 'remoto' ? 'a distancia, desde el teléfono del cliente' : 'en la tienda' }} · N° de pedido {{ $anexo->orden?->referencia ?? 'por asignar' }}</div>
      </td>
      @if ($logo)
        <td style="text-align:right;width:120px;"><img src="{{ $logo }}" style="height:38px;"></td>
      @endif
    </tr></table>
  </div>

  <p>{{ trim(($texto['ciudad'] ?? '') !== '' ? $texto['ciudad'] . ', ' . $fechaTxt : $fechaTxt) }}</p>
  <p style="white-space: pre-line;">{{ $texto['introduccion'] }}</p>

  @foreach ($productos as $n => $p)
    <div class="producto">
      <div class="nombre">
        {{ $n + 1 }}. {{ $p['nombre'] }}@if (($p['cantidad'] ?? 1) != 1) — {{ $p['cantidad'] }} unidades @endif
        @if (in_array($n, $aprobados, true)) <span class="aprobado">✓ aprobado por el cliente</span> @endif
      </div>
      @if (! empty($p['tipo'])) <div class="tipo">{{ $p['tipo'] }}</div> @endif

      <h3>I. {{ $texto['rotulo_descripcion'] }}</h3>
      <table class="datos">
        @forelse ($p['grupos']['descripcion'] as $d)
          <tr><td class="l">{{ $d['label'] }}</td><td style="white-space: pre-line;">{{ $d['value'] }}</td></tr>
        @empty
          <tr><td colspan="2">—</td></tr>
        @endforelse
      </table>

      <h3>II. {{ $texto['rotulo_medidas'] }}</h3>
      <table class="datos">
        @forelse ($p['grupos']['medidas'] as $d)
          <tr><td class="l">{{ $d['label'] }}</td><td>{{ $d['value'] }}</td></tr>
        @empty
          <tr><td colspan="2">—</td></tr>
        @endforelse
      </table>

      <h3>III. {{ $texto['rotulo_tipo'] }}</h3>
      <table class="datos">
        @forelse ($p['grupos']['tipo'] as $d)
          <tr><td class="l">{{ $d['label'] }}</td><td>{{ $d['value'] }}</td></tr>
        @empty
          <tr><td colspan="2">—</td></tr>
        @endforelse
      </table>

      @if (count($p['imagenes']))
        <h3>Bocetos</h3>
        <div class="bocetos">
          @foreach ($p['imagenes'] as $img)
            <img src="{{ $img }}">
          @endforeach
        </div>
      @elseif (count($p['bocetos'] ?? []))
        <p class="sub">Bocetos aprobados: {{ count($p['bocetos']) }} (en el sistema, orden {{ $anexo->orden?->referencia ?? '' }}).</p>
      @endif
    </div>
  @endforeach

  <p style="white-space: pre-line; margin-top: 8px;">{{ $texto['constancia'] }}</p>
  @if (! empty($texto['cierre'])) <p style="margin-top: 8px;">{{ $texto['cierre'] }}</p> @endif

  <table class="firma">
    <tr>
      <td colspan="2">
        <div class="lbl">Firma</div>
        @if ($firma)
          <img src="{{ $firma }}" style="height:70px;">
        @else
          <div class="val">&nbsp;</div>
        @endif
      </td>
    </tr>
    <tr>
      <td style="width:50%;"><div class="lbl">Nombre</div><div class="val">{{ $anexo->nombre_firmante }}</div></td>
      <td style="width:50%;"><div class="lbl">Cédula</div><div class="val">{{ $anexo->documento_firmante }}</div></td>
    </tr>
    <tr>
      <td><div class="lbl">Dirección</div><div class="val">{{ $datos['direccion'] ?? $anexo->cliente?->direccion ?? '' }}&nbsp;</div></td>
      <td><div class="lbl">Teléfono</div><div class="val">{{ $datos['telefono'] ?? $anexo->cliente?->telefono ?? '' }}&nbsp;</div></td>
    </tr>
    <tr>
      <td><div class="lbl">Fecha y hora de la firma</div><div class="val">{{ $anexo->firmado_at?->timezone('America/Bogota')->format('d/m/Y h:i a') }}</div></td>
      <td><div class="lbl">N° de pedido</div><div class="val">{{ $anexo->orden?->referencia ?? 'Por asignar' }}</div></td>
    </tr>
  </table>

  <div class="pie">
    Documento firmado electrónicamente en el sistema de {{ \App\Support\PlantillasDocumento::empresa()['nombre_completo'] }}, junto con el anexo de garantías (una sola firma).
    @if ($anexo->vendedor) Asesor: {{ $anexo->vendedor->nombre }}. @endif
  </div>
</body>
</html>
