---
name: dev
description: Cómo levantar y probar lycet-fork en Docker cuando no tenés PHP instalado localmente — usa docker-compose.dev.yml/Dockerfile.dev (no el Dockerfile de raíz, que es de producción). Úsala antes de decir "no puedo probar esto" o "necesito instalar PHP".
---

# dev — probar lycet-fork sin tener PHP instalado

Este repo trae un `Dockerfile` en la raíz, pero es de **producción** (multi-stage, sin
dependencias de dev, optimizado con `php-pm`, código copiado dentro de la imagen). No sirve para
iterar código ni para correr `phpunit`. Para eso están `Dockerfile.dev` +
`docker-compose.dev.yml`, agregados aparte.

**Probado de punta a punta** (2026-09-17): `docker compose -f docker-compose.dev.yml up --build`
levanta bien, `POST /api/v1/invoice/send?token=123456` con
`tests/Resources/documents/invoice.json` fue aceptado por SUNAT beta real
(`cdrResponse.code: "0"`), y `php bin/phpunit` corre 21/21 OK dentro del contenedor.

## Requisitos

- Docker Desktop corriendo, con la integración WSL activada para esta distro (Docker Desktop →
  Settings → Resources → WSL Integration). Sin eso, `docker`/`docker compose` no existen dentro
  de WSL aunque el binario esté instalado en Windows.

## Levantar el entorno

```bash
docker compose -f docker-compose.dev.yml up --build
```

La primera vez tarda más: el entrypoint (`docker/dev-entrypoint.sh`) corre automáticamente,
antes de arrancar el servidor:

1. `composer install` (deps de dev incluidas — guarda `vendor/` en un volumen nombrado para que
   no lo pise el bind mount del código fuente).
2. copia `.env.test` → `.env.test.local` si no existe (lo que pide `CLAUDE.md` para correr
   tests).
3. copia `tests/Resources/cert.pem` y `tests/Resources/logo.png` a `data/` si no están — es el
   certificado y logo de prueba que trae el propio repo para el RUC sandbox de SUNAT
   (`20161515648MODDATOS`), **no un certificado real**.

Cuando termine, la API queda en `http://localhost:8000`. El código fuente está montado en vivo
(bind mount), así que los cambios que hagas en `src/`, `config/`, etc. se reflejan sin
reconstruir la imagen — el servidor embebido de PHP (`php -S`) los recoge en el siguiente
request.

`.env` ya viene commiteado con credenciales del sandbox de pruebas de SUNAT (`SOL_USER
20161515648MODDATOS` / `SOL_PASS MODDATOS`, URLs `*-beta.sunat.gob.pe`) — no son secretos reales,
son las credenciales públicas de pruebas que usa todo el ecosistema greenter. El contenedor
necesita salida a internet para que las requests lleguen de verdad a SUNAT beta.

## Probar los endpoints

Todas las rutas cuelgan de `/api/v1/...` (no `/v1/...`) y la autenticación es un **query param**
`?token=...` (no un header `Authorization`) — la valida `src/EventSubscriber/TokenSubscriber.php`
contra `CLIENT_TOKEN` del `.env` (`123456` en el `.env` committeado). Ejemplo verificado:

```bash
curl -X POST "http://localhost:8000/api/v1/invoice/send?token=123456" \
  -H "Content-Type: application/json" \
  -d @tests/Resources/documents/invoice.json
```

Esto de verdad manda la factura a SUNAT beta y devuelve `sunatResponse.cdrResponse.code: "0"`
si fue aceptada.

- Swagger: `http://localhost:8000/swagger.yaml` (pegalo en
  [editor.swagger.io](https://editor.swagger.io) o en el petstore que linkea el `README.md`)
  para ver todos los endpoints documentados. Para ver las rutas reales tal como las registra
  Symfony: `docker compose -f docker-compose.dev.yml exec app php bin/console debug:router`.
- Controllers en `src/Controller/v1/`: `Invoice`, `Note`, `Despatch`, `Summary`, `Voided`,
  `Reversion`, `Perception`, `Retention`, `Configuration` — cada uno expone `send`/`xml`/`pdf`
  (o `send`/`status` en el caso de `Despatch`, ver `CLAUDE.md` sección Arquitectura sobre por qué
  ese controller es distinto).
- Body de ejemplo para invoice: `tests/Resources/documents/invoice.json`.
- El binario `wkhtmltopdf` está incluido en la imagen dev (mismo que usa producción), así que los
  endpoints `*/pdf` también se pueden probar, no solo `send`/`xml`.

## Correr los tests dentro del contenedor

Con el `app` service ya levantado (`up` en otra terminal, o `up -d`):

```bash
docker compose -f docker-compose.dev.yml exec app php bin/phpunit
# un archivo puntual
docker compose -f docker-compose.dev.yml exec app php bin/phpunit tests/Controller/v1/InvoiceControllerTest.php
# un método puntual
docker compose -f docker-compose.dev.yml exec app php bin/phpunit --filter testMethodName tests/Controller/v1/InvoiceControllerTest.php
```

## Correr composer/consola dentro del contenedor

Para lo que pide la skill [`greenter`](../greenter/SKILL.md) (actualizar paquetes) o `bin/console`:

```bash
docker compose -f docker-compose.dev.yml exec app sh
# adentro: composer update greenter/xml greenter/ws, php bin/console ..., etc.
```

## Apagar / limpiar

```bash
docker compose -f docker-compose.dev.yml down
# si querés forzar un composer install limpio en el próximo up:
docker compose -f docker-compose.dev.yml down -v
```

## Verificar los bugs de la skill `lycet-fork` con este entorno

Una vez levantado, los pasos de verificación de
[`reference/diagnostico-bugs.md`](../lycet-fork/reference/diagnostico-bugs.md) (Bug 1: `POST
despatch/send` con `modTraslado: "01"` y `envio.fecEntregaBienes`; Bug 2: apuntar una `*_URL` a
un host que no responde) se pueden correr tal cual contra `http://localhost:8000` — el
contenedor ya sale a SUNAT beta real con las credenciales del `.env`.
