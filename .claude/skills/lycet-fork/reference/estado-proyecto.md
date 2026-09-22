# Estado del proyecto — lycet-fork

Última verificación: 2026-09-22 (guía del transportista); 2026-09-17 (los dos bugs), sobre
`master` + los commits que aplican cada cosa (ver `git log` para los SHA exactos).

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

**✅ Resuelto y verificado.** `send()`/`status()` en `src/Controller/v1/DespatchController.php`
ahora envuelven `$see->send($document)`/`$see->getStatus($ticket)` en `try/catch (\Throwable)`,
devolviendo un `SummaryResult`/`StatusResult` con `error.code: "HTTP"` en vez de dejar propagar
la excepción. La causa real (documentada ahora en `diagnostico-bugs.md`) no era "falta copiar un
try/catch que sí tienen los otros controllers" — esos no tienen ninguno, la resiliencia es
interna a `greenter/ws`; lo que faltaba era proteger el paso de autenticación OAuth2 de
`Greenter\Api`, que no está cubierto por el try/catch interno de `GreSender`.

`php bin/phpunit` sigue en 21/21 OK. Verificación funcional: con `AUTH_URL`/`API_URL` apuntando
a `http://127.0.0.1:65535/v1` (host que rechaza la conexión), tanto `despatch/send` como
`despatch/status` devolvieron `200` con `sunatResponse.success: false` /
`error.code: "HTTP"` (antes: `500` crudo). Con las URLs restauradas al sandbox GRE test real, el
flujo normal (`send` → ticket → `status` → `cdrResponse.code: "0"`) sigue funcionando igual.

## Agregado — Guía de Remisión del TRANSPORTISTA (`tipoDoc` 31)

**✅ Implementado y verificado contra el sandbox GRE** (con la salvedad de abajo). greenter
v5.3.0 solo modela la guía del **remitente** (09): un único modelo `Despatch`, una única
plantilla `despatch2022.xml.twig`, y esa plantilla pone al emisor como remitente
(`cac:DespatchSupplierParty` = `emp.ruc`). Mandar `tipoDoc: "31"` por ese camino devuelve `200` y
un XML firmado que **dice 31 pero declara al transportista como remitente** — mal en silencio,
igual que el bug viejo de `sale/qr` en `demo-lycet`.

Qué se agregó, todo dentro de este repo (greenter queda intacto, sin forkearlo):

| Archivo | Qué hace |
|---|---|
| `src/Model/DespatchCarrier.php` | `extends Despatch` + el único campo que falta: `remitente` |
| `src/Xml/Templates/despatchCarrier.xml.twig` | La plantilla del 31, derivada de la 5.3.0 del 09 |
| `src/Xml/Builder/DespatchCarrierBuilder.php` | Twig apuntando a `src/Xml/Templates/` (el de greenter es un directorio fijo) |
| `src/Greenter/CarrierApi.php` | `extends Api`: intercepta `send()` para un `DespatchCarrier`, arma + firma + `sendXml()` |
| `config/serializer-app/DespatchCarrier.yml` | Mapeo JMS del campo `remitente` |
| `config/services.yaml` | `Greenter\Api` se instancia como `CarrierApi` |
| `src/Controller/v1/DespatchController.php` | Elige el modelo por `tipoDoc`; 400 si un 31 viene sin `remitente` |

**Las 4 diferencias de la plantilla respecto de la del 09** (están comentadas en su cabecera):
`cac:DespatchParty` con el remitente dentro de `Delivery/Despatch` (nuevo), y se omiten
`cbc:HandlingCode` (motivo, catálogo 20), `cbc:TransportModeCode` (modalidad, catálogo 18) y
`cac:SellerSupplierParty`. Todo lo demás es idéntico — **si se actualiza `greenter/xml`, hay que
re-derivar la plantilla** desde la nueva `despatch2022.xml.twig` y volver a aplicar esas 4.

Verificación: `php bin/phpunit` 28/28 OK (6 tests nuevos en
`tests/Controller/v1/DespatchCarrierControllerTest.php`, todos offline contra `/despatch/xml`), y
el ciclo real `POST despatch/send` → ticket → `GET despatch/status` → `cdrResponse.code: "0"` /
`ACEPTADA` con el fixture `tests/Resources/documents/despatch-carrier.json`.

⚠️ **Qué NO prueba esa aceptación.** `AUTH_URL`/`API_URL` apuntan al sandbox GRE de Nubefact, que
devuelve `notes: ["CDR de prueba"]` — acepta el documento sin correr las validaciones reales de
SUNAT. Vale lo mismo que la verificación de la guía del remitente (Bug 1, arriba): confirma el
canal completo (armado, firma, OAuth2, ticket, CDR), no que SUNAT vaya a aceptar el 31. **Falta
mandar una 31 contra el GRE de SUNAT real con `client_id`/`client_secret` de SOL** antes de
usarla en producción; recién ahí se sabe si falta algún campo (p.ej. datos del vehículo que la 31
exija y la 09 no).

### Qué dice el upstream (verificado 2026-09-22)

greenter **no** tiene la 31, ni en release ni en `master`:

- [`#227`](https://github.com/thegreenter/greenter/issues/227) "Guía de remisión del
  trasportista", **abierto desde 2023-09-11**. Preguntan justo lo que probamos acá —si basta con
  cambiar el tipo de documento— y el autor responde: *"creo que no, se necesita evaluar la
  documentación"*. Dos usuarios más lo pidieron en 2023 y 2025.
- [`#243`](https://github.com/thegreenter/greenter/pull/243) y
  [`#247`](https://github.com/thegreenter/greenter/pull/247), de `gquispeg`, implementan la 31.
  Ambos **cerrados sin mergear** (el último, 2025-08-29), sin un solo comentario del mantenedor —
  solo el bot de calidad, que pasó.
- `master` hoy: sin `DespatchParty`, sin `remitente`, sin condicional por `tipoDoc`. Último
  release `v5.3.0` (2026-06-08), que es el que este fork ya usa.

**Coincidencias y diferencias con el PR #247** (implementación independiente, buen contraste):

| | PR #247 | Este fork |
|---|---|---|
| `cac:DespatchParty` con el remitente dentro de `cac:Despatch` | ✅ igual | ✅ igual |
| `schemeID` del remitente | fijo `"6"` (solo RUC) | `{{ remitente.tipoDoc }}` — un remitente persona natural con DNI es el caso normal de un courier |
| `HandlingCode` / `TransportModeCode` / `SellerSupplierParty` en la 31 | los deja | los omite |
| `envio.subContratado` (`cac:Consignment/cac:LogisticsOperatorParty`) | lo agrega | **no implementado** |

⚠️ **Discrepancia sin resolver**: si la 31 lleva o no motivo (`HandlingCode`) y modalidad
(`TransportModeCode`). Acá se omiten siguiendo la plantilla de la comunidad que separa por
`tipoDoc`; el PR #247 los deja. El sandbox GRE no lo dirime porque acepta cualquiera de las dos
("CDR de prueba"). **Lo resuelve la prueba contra el GRE de SUNAT real**, que es el siguiente paso
de abajo — si rechaza, el código del error dice cuál de las dos lecturas era la correcta.

`subContratado` no aplica mientras el traslado lo haga flota propia; si algún día se terceriza un
tramo, el PR #247 es el modelo a copiar.

## Siguiente paso sugerido

Mandar una guía del transportista (31) contra el **GRE de SUNAT real**, no el sandbox — es lo
único que falta para darla por buena (ver la salvedad de su sección). Después, revisar el issue
relacionado
[`giansalex/lycet#630`](https://github.com/giansalex/lycet/issues/630) (error 3354, `vehiculo`
bajo `modTraslado: "01"`) probando con `vehiculo`/`choferes` incluidos — ya no debería bloquear
el 3617 antes de llegar a esa validación. No es un bug de este fork, es un tema aparte a evaluar
si conviene abordarlo acá.

## Cómo actualizar este archivo

Cada vez que se aplique o verifique uno de los fixes, actualizar la sección correspondiente:
marcar ✅/❌, la fecha de verificación, el commit/SHA contra el que se probó, y si se confirmó
contra SUNAT real o solo localmente. No dejar que este archivo diga "resuelto" sin haber
verificado con los pasos de `diagnostico-bugs.md` (`/xml` no cuenta como verificación del bug 1).
