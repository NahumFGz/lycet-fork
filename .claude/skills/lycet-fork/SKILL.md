---
name: lycet-fork
description: Contexto del fork lycet-fork (por qué existe, qué bugs corrige, qué agrega, estado de cada cosa). Úsala antes de tocar composer.json/composer.lock (versiones de greenter/*), src/Controller/v1/DespatchController.php, config/serializer/Despatch.*.yml, o cualquier cosa de la guía de remisión del transportista (src/Model/DespatchCarrier.php, src/Xml/, src/Greenter/) — y siempre antes de decir que un bug ya está resuelto.
---

# lycet-fork — contexto y estado

Fork de [`giansalex/lycet`](https://github.com/giansalex/lycet) (wrapper REST/Symfony sobre
[`greenter/greenter`](https://github.com/thegreenter/greenter)). Nació para corregir dos
bugs encontrados en producción-de-prueba contra SUNAT real, diagnosticados desde el repo
consumidor `demo-lycet` (Nest/TypeScript, sin poder tocar PHP desde ahí), y hoy además **agrega
un documento que greenter no modela**: la Guía de Remisión del **TRANSPORTISTA** (`tipoDoc` 31).

greenter solo trae la guía del **remitente** (09), donde el emisor es quien manda la carga. Un
courier que traslada carga de terceros emite la 31, donde el emisor es el transportista y el
remitente es otro. Poner `tipoDoc: "31"` en la 09 no falla: firma un XML que dice 31 pero declara
al transportista como remitente. Qué se agregó y cómo está verificado:
[`reference/estado-proyecto.md`](reference/estado-proyecto.md).

No confundir con `CLAUDE.md` (raíz): ese archivo describe la arquitectura general (flujo de
request, `SeeFactory` vs `SeeApiFactory`, serialización JMS) para trabajar en el código en
general. Esta skill es específica de los dos bugs que motivan el fork.

## Dónde está cada cosa

- **Causa raíz, evidencia y cómo verificar cada fix contra SUNAT real** (no alcanza con `/xml`,
  solo firma localmente): [`reference/diagnostico-bugs.md`](reference/diagnostico-bugs.md). Es
  la fuente de verdad técnica — no repitas ese contenido acá ni en el otro reference.
- **Qué está hecho y qué falta ahora mismo**, con fecha y commit de la última verificación:
  [`reference/estado-proyecto.md`](reference/estado-proyecto.md). Es el único de los tres
  archivos que cambia seguido — léelo siempre antes de decir "ya está arreglado", nunca lo
  asumas por la fecha de este texto.

## Después de aplicar los fixes

Los consumidores fijan un commit del fork. `demo-lycet` buildea desde git
(`build.context: https://github.com/NahumFGz/lycet-fork.git#${LYCET_GIT_SHA}`); desde el bug 5,
el CI publica la imagen de `master` como `ghcr.io/nahumfgz/lycet-fork:<sha>`, que es lo que
conviene usar: no se construye en el servidor y es exactamente la que pasó los tests. El CI
solo corre a mano (Actions → Symfony → Run workflow sobre `master`): un push no publica nada, así
que el commit que se quiera fijar hay que publicarlo corriéndolo.

Al mover el SHA a uno posterior a los bugs 3 a 6: la imagen ya no trae valores por defecto, así
que el consumidor tiene que pasar todas las variables (`docker-entrypoint.sh` dice cuáles faltan);
los errores llegan como JSON 4xx/5xx en vez de 502; y `despatch/send` suma `hash`.

**El QR de la guía solo sale en un lugar.** Es el enlace de `cdrResponse.reference` que devuelve
`despatch/status` con la guía aceptada: ni `despatch/send` ni el XML lo traen, y `despatch/pdf`
no lo dibuja (`DocumentRequest::pdf()` no reenvía `parameters.system.qr`, aunque la plantilla de
greenter lo acepta; y una 31 sale armada como 09). Los consumidores
arman su propio formato con el XML, el `hash` y ese enlace, así que esos PDF
no se corrigieron. El QR de boleta/factura, en cambio, se arma con los datos del comprobante.
