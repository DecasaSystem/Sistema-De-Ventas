# Flujo de ramas

Cómo se mueve el código desde que se empieza a trabajar en algo hasta que
llega a producción.

## Las ramas fijas

- **`main`** — lo que está en producción. Render (`decasa-api`) y Vercel
  (`decasa-app`) despliegan desde acá. Nadie trabaja directo sobre `main`;
  solo recibe merges de `develop` cuando se decide soltar una versión.
- **`develop`** — donde vive el trabajo en curso. Las features se integran
  aquí primero, se prueban juntas, y solo cuando está estable se sube a
  `main`. Un push a `develop` **no** despliega producción.

## Ramas de trabajo

Salen de `develop`, no de `main`:

```bash
git checkout develop
git pull
git checkout -b feature/nombre-corto     # una función nueva
git checkout -b fix/nombre-corto         # arreglar un bug
git checkout -b chore/nombre-corto       # limpieza, dependencias, config
```

Cuando está lista, PR de `feature/...` → `develop` (no → `main`). Revisado y
con los tests en verde, se mergea y se borra la rama.

**Excepción — `hotfix/`:** un bug de producción que no puede esperar a la
próxima soltada. Sale de `main`, se arregla, PR a `main` **y** se mergea
también a `develop` para que el arreglo no se pierda en la próxima soltada.

## Soltar una versión (develop → main)

Cuando lo que está en `develop` ya se probó y se quiere en producción:

```bash
git checkout main
git pull
git merge --ff-only develop      # o un PR develop → main en GitHub
git push
```

Eso es lo que dispara el deploy en Render/Vercel.

## Nombres de commit

Ya se usa `tipo(alcance): mensaje corto`, en español, imperativo o
descriptivo — se mantiene igual:

```
feat(produccion): producir contra stock para la Reserva de Fábrica
fix(reportes): la cartera de Tiendas contaba órdenes canceladas
chore(datos): mover la orden #1241 de fecha
```

Tipos: `feat`, `fix`, `chore`, `docs`, `refactor`.

## Antes de mergear a develop o a main

- `decasa-api`: `php artisan test` en verde.
- `decasa-app`: `npm run build` sin errores.
- Si la rama trae migración nueva, decirlo en la descripción del PR — en
  Render corre sola al desplegar (`entrypoint.sh`), pero hay que saber que va.
