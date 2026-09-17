# Estado del proyecto — lycet-fork

Última verificación: 2026-09-17, sobre `master` + el commit que aplica el fix del Bug 1
(`composer update greenter/xml greenter/ws`, ver `git log` para el SHA exacto).

Causa raíz y fix completo de cada bug: [`diagnostico-bugs.md`](diagnostico-bugs.md) — este
archivo solo lleva el estado (qué se confirmó, qué falta, contra qué commit), no lo repitas acá.

## Bug 1 — `greenter/xml`/`greenter/ws` desfasados (error 3617)

**✅ Resuelto y verificado contra SUNAT/GRE test real (no solo local).** `composer update
greenter/xml greenter/ws` (dentro del contenedor de la skill `dev`) los subió a `v5.3.0`,
igualando al resto:

```
greenter/core      v5.3.0
greenter/htmltopdf v5.3.0
greenter/lite      v5.3.0
greenter/report    v5.3.0
greenter/ws        v5.3.0
greenter/xml       v5.3.0
```

`php bin/phpunit` sigue en 21/21 OK. Verificación funcional (no solo el bump de versión):
`POST /api/v1/despatch/send` con `codTraslado: "01"`, `modTraslado: "01"`, `transportista` (sin
`vehiculo`/`choferes`) y `envio.fecEntregaBienes` seteado contra el ambiente GRE test
(`AUTH_URL`/`API_URL` del `.env`, sandbox de Nubefact) — el XML generado ahora sí incluye
`<cac:LoadingTransportEvent><cbc:OccurrenceDate>...</cbc:OccurrenceDate></cac:LoadingTransportEvent>`
dentro de `ShipmentStage` (ausente con `greenter/xml` v5.2.0), y `GET /api/v1/despatch/status`
devolvió `cdrResponse.code: "0"` / `"ACEPTADA"`. Sin errores 3617.

**Pendiente, no bloqueante**: no se pudo reproducir el issue relacionado
[`giansalex/lycet#630`](https://github.com/giansalex/lycet/issues/630) (error 3354, `vehiculo`
bajo `modTraslado: "01"`) porque el caso de prueba usado no incluía `vehiculo`/`choferes` — es
lo que dice el "siguiente paso sugerido" de abajo, sigue abierto.

## Bug 2 — `DespatchController` sin try/catch ante SUNAT caída

**❌ No resuelto.** Verificado leyendo `src/Controller/v1/DespatchController.php`: `send()` y
`status()` siguen llamando a `$see->send($document)` / `$see->getStatus($ticket)` directo, sin
try/catch — a diferencia de `SummaryController`/`VoidedController`/`ReversionController`, que
delegan en `DocumentRequest::send()` (`src/Service/DocumentRequest.php`).

**Falta**: aplicar el fix y verificar con el patrón `lycet-sunat-down` — pasos exactos en
`diagnostico-bugs.md`, sección "Bug 2" (incluye el detalle de por qué `DespatchController` no
puede delegar en `DocumentRequest` tal cual).

## Siguiente paso sugerido

Con el Bug 1 resuelto, sigue el Bug 2 (try/catch en `DespatchController`). Después, revisar el
issue relacionado [`giansalex/lycet#630`](https://github.com/giansalex/lycet/issues/630) (error
3354, `vehiculo` bajo `modTraslado: "01"`) probando con `vehiculo`/`choferes` incluidos — ya no
debería bloquear el 3617 antes de llegar a esa validación.

## Cómo actualizar este archivo

Cada vez que se aplique o verifique uno de los fixes, actualizar la sección correspondiente:
marcar ✅/❌, la fecha de verificación, el commit/SHA contra el que se probó, y si se confirmó
contra SUNAT real o solo localmente. No dejar que este archivo diga "resuelto" sin haber
verificado con los pasos de `diagnostico-bugs.md` (`/xml` no cuenta como verificación del bug 1).
