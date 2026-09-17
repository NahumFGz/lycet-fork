# Estado del proyecto — lycet-fork

Última verificación: 2026-09-16, sobre `master` en `872b048` ("Add fecEntregaBienes field to
Despatch.Shipment.yml").

Causa raíz y fix completo de cada bug: [`diagnostico-bugs.md`](diagnostico-bugs.md) — este
archivo solo lleva el estado (qué se confirmó, qué falta, contra qué commit), no lo repitas acá.

## Bug 1 — `greenter/xml`/`greenter/ws` desfasados (error 3617)

**❌ No resuelto.** Verificado en `composer.lock`:

```
greenter/core      v5.3.0
greenter/htmltopdf v5.3.0
greenter/lite      v5.3.0
greenter/report    v5.3.0
greenter/ws        v5.2.0   ← desfasado
greenter/xml       v5.2.0   ← desfasado
```

El commit `872b048` solo agregó `fecEntregaBienes` al serializer YAML
(`config/serializer/Despatch.Shipment.yml`) — el modelo PHP y el serializer ya aceptaban el
campo desde antes; lo que falta sigue siendo el XML builder (`greenter/xml`). Confirmar de nuevo
con:

```bash
composer show greenter/xml greenter/ws | grep versions
# o
python3 -c "import json; d=json.load(open('composer.lock')); [print(p['name'], p['version']) for p in d['packages'] if p['name'].startswith('greenter/')]"
```

**Falta**: aplicar el fix y verificar contra SUNAT real — pasos exactos en
`diagnostico-bugs.md`, sección "Bug 1".

## Bug 2 — `DespatchController` sin try/catch ante SUNAT caída

**❌ No resuelto.** Verificado leyendo `src/Controller/v1/DespatchController.php`: `send()` y
`status()` siguen llamando a `$see->send($document)` / `$see->getStatus($ticket)` directo, sin
try/catch — a diferencia de `SummaryController`/`VoidedController`/`ReversionController`, que
delegan en `DocumentRequest::send()` (`src/Service/DocumentRequest.php`).

**Falta**: aplicar el fix y verificar con el patrón `lycet-sunat-down` — pasos exactos en
`diagnostico-bugs.md`, sección "Bug 2" (incluye el detalle de por qué `DespatchController` no
puede delegar en `DocumentRequest` tal cual).

## Siguiente paso sugerido

Bug 1 es el más simple y desbloquea probar guía de remisión con traslado público de punta a
punta; conviene resolverlo primero. El issue relacionado
[`giansalex/lycet#630`](https://github.com/giansalex/lycet/issues/630) (error 3354, `vehiculo`
bajo `modTraslado: "01"`) no se ha podido reproducir todavía porque el 3617 bloquea antes de
llegar a esa validación — revisar una vez resuelto el bug 1.

## Cómo actualizar este archivo

Cada vez que se aplique o verifique uno de los fixes, actualizar la sección correspondiente:
marcar ✅/❌, la fecha de verificación, el commit/SHA contra el que se probó, y si se confirmó
contra SUNAT real o solo localmente. No dejar que este archivo diga "resuelto" sin haber
verificado con los pasos de `diagnostico-bugs.md` (`/xml` no cuenta como verificación del bug 1).
