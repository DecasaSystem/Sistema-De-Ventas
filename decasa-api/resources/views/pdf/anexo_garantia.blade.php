<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Anexo de garantías</title>
<style>
  @page { margin: 28px 34px; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1f2937; line-height: 1.45; }
  .encabezado { border-bottom: 2px solid #1f2937; padding-bottom: 8px; margin-bottom: 10px; }
  .encabezado table { width: 100%; }
  .titulo { font-size: 13px; font-weight: bold; text-transform: uppercase; }
  .sub { font-size: 9px; color: #6b7280; }
  h2 { font-size: 10.5px; text-transform: uppercase; margin: 10px 0 4px; color: #111827; }
  p { margin: 0 0 4px; text-align: justify; }
  .leido { color: #15803d; font-size: 8.5px; font-weight: bold; }
  table.check { width: 100%; border-collapse: collapse; margin-top: 4px; }
  table.check td { border-bottom: 1px solid #e5e7eb; padding: 5px 4px; }
  table.check td.r { width: 70px; text-align: center; font-weight: bold; }
  .si { color: #15803d; } .no { color: #b91c1c; }
  table.firma { width: 100%; margin-top: 14px; border-collapse: collapse; }
  table.firma td { padding: 4px 6px; vertical-align: bottom; }
  .lbl { color: #6b7280; font-size: 8.5px; text-transform: uppercase; }
  .val { font-size: 10.5px; font-weight: bold; border-bottom: 1px solid #9ca3af; padding-bottom: 2px; }
  .pie { margin-top: 12px; font-size: 8px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 6px; }
</style>
</head>
<body>
  <div class="encabezado">
    <table><tr>
      <td>
        <div class="titulo">{{ $texto['titulo'] }}</div>
        <div class="sub">Versión del documento {{ $anexo->version }} · Firmado {{ $anexo->modo === 'remoto' ? 'a distancia, desde el teléfono del cliente' : 'en la tienda' }}</div>
      </td>
      @if ($logo)
        <td style="text-align:right;width:120px;"><img src="{{ $logo }}" style="height:38px;"></td>
      @endif
    </tr></table>
  </div>

  @php($leidas = $anexo->respuestas['secciones'] ?? [])
  @foreach ($texto['secciones'] as $s)
    <h2>{{ $s['titulo'] }} @if (in_array($s['id'], $leidas)) <span class="leido">✓ leído</span> @endif</h2>
    @foreach ($s['parrafos'] as $p)
      <p>{{ $p }}</p>
    @endforeach
  @endforeach

  <h2>Check list</h2>
  @php($resp = $anexo->respuestas['checklist'] ?? [])
  <table class="check">
    @foreach ($texto['checklist'] as $q)
      @php($r = $resp[$q['id']] ?? null)
      <tr>
        <td>{{ $q['pregunta'] }}</td>
        <td class="r {{ $r === 'si' ? 'si' : ($r === 'no' ? 'no' : '') }}">{{ $r === 'si' ? 'SÍ' : ($r === 'no' ? 'NO' : '—') }}</td>
      </tr>
    @endforeach
  </table>

  <table class="firma">
    <tr>
      <td style="width:50%;"><div class="lbl">Nombre del cliente</div><div class="val">{{ $anexo->nombre_firmante }}</div></td>
      <td style="width:50%;"><div class="lbl">N° de documento</div><div class="val">{{ $anexo->documento_firmante }}</div></td>
    </tr>
    <tr>
      <td><div class="lbl">Fecha</div><div class="val">{{ $anexo->firmado_at?->timezone('America/Bogota')->format('d/m/Y h:i a') }}</div></td>
      <td><div class="lbl">N° de pedido</div><div class="val">{{ $anexo->orden?->referencia ?? 'Por asignar' }}</div></td>
    </tr>
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
  </table>

  <div class="pie">
    Documento firmado electrónicamente en el sistema de Decasa Muebles y Decoración.
    @if ($anexo->vendedor) Asesor: {{ $anexo->vendedor->nombre }}. @endif
  </div>
</body>
</html>
