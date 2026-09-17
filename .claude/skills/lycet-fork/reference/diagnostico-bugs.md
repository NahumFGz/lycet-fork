# Diagnóstico completo de los dos bugs

Evidencia y reproducción detallada en el repo consumidor (`demo-lycet`, un backend Nest/TypeScript
que llama a lycet vía HTTP, sin tocar PHP):
[`docs/comprobantes.md`](https://github.com/NahumFGz/demo-lycet/blob/master/docs/comprobantes.md#guía-de-remisión-despatch)
(sección "Guía de remisión") y
[`docs/known-issues.md`](https://github.com/NahumFGz/demo-lycet/blob/master/docs/known-issues.md).

Commit base sobre el que se diagnosticó: `872b048a` (también probado contra `831a07b`, "Update
greenter v5.3" — el bug 1 sigue presente en ambos).

## Bug 1 — `greenter/xml` quedó en v5.2.0, bloquea guía de remisión con traslado público

**Síntoma**: `POST despatch/send` con `modTraslado: "01"` (traslado público, con
`transportista`) contra SUNAT real falla siempre con:

```json
{
  "success": false,
  "error": {
    "code": "3617",
    "message": "No ha ingresado el campo de “Fecha de entrega de bienes al transportista” o esta vacio: 3617 (nodo: \"/\" valor: \"\")"
  }
}
```

`modTraslado: "02"` (traslado privado) no se ve afectado — mismo body sin ese campo se acepta
sin problema.

**Causa raíz** (confirmada leyendo el código dentro del contenedor): desde el 1 de junio de 2026
SUNAT exige `envio.fecEntregaBienes` para todo traslado con `modTraslado: "01"`.

- El modelo PHP de greenter (`Shipment.php`, `getFecEntregaBienes`/`setFecEntregaBienes`) **sí**
  tiene la propiedad.
- El serializer de lycet (`config/serializer/Despatch.Shipment.yml`) **sí** la acepta
  (`fecEntregaBienes: DateTime`), sin remapeo.
- El **XML builder** (`greenter/xml`) es el que falta: en `composer.lock` quedó pinneado en
  `v5.2.0`, que nunca vuelca ese campo al XML — confirmado enviando el mismo body con y sin
  `envio.fecEntregaBienes` seteado, mismo 3617 en ambos casos. `greenter/xml` v5.3.0 (ya
  publicada en Packagist) sí lo renderiza como `<cbc:OccurrenceDate>` dentro de `ShipmentStage`
  ([`despatch2022.xml.twig#L131-L133`](https://github.com/thegreenter/greenter/blob/v5.3.0/packages/xml/src/Xml/Templates/despatch2022.xml.twig#L131-L133)).
- El bump río abajo llevó `greenter/core`/`lite`/`report`/`htmltopdf` a `v5.3.0` pero **dejó
  `greenter/xml` y `greenter/ws` en `v5.2.0`** — de ahí el desfase entre "el modelo tiene el
  campo" y "el XML nunca lo incluye".

**Fix**: bumpear `composer.json` a `greenter/xml: ^5.3` (y `greenter/ws` si aplica), correr
`composer update greenter/xml greenter/ws` (solo esos paquetes, no un `composer update` general)
y commitear el `composer.lock` resultante. El resto de la imagen (endpoints, multi-empresa,
certificados) queda igual.

**Cómo verificar**: `POST despatch/send` con `codTraslado: "01"`, `modTraslado: "01"`,
`transportista` (sin `vehiculo`/`choferes`, el caso más simple) y `envio.fecEntregaBienes`
seteado, contra SUNAT real (beta). Si el 3617 desaparece, seguir con `GET despatch/status` hasta
`cdrResponse.code: "0"` / `"ACEPTADA"`. Nota: `POST despatch/xml` **no** sirve para verificar
esto — solo firma localmente, no pasa por la validación que dispara el 3617.

Ojo con el issue relacionado [`giansalex/lycet#630`](https://github.com/giansalex/lycet/issues/630)
(error 3354, `vehiculo` bajo `modTraslado: "01"` sin el indicador
`SUNAT_Envio_IndicadorVehiculoConductoresTransp`): es un error *distinto y posterior* en el mismo
flujo (traslado público con vehículo/choferes) que todavía no se pudo reproducir porque el 3617
bloquea antes de llegar a esa validación. Vale la pena revisarlo una vez resuelto el bug 1, antes
de dar por completo el soporte a traslado público.

## Bug 2 — `despatch/send` y `despatch/status` no degradan con gracia ante SUNAT caída

**Síntoma**: con SUNAT (o el OSE) inalcanzable, el resto de comprobantes asíncronos
(`summary/send`, `voided/send`, `reversion/send`) responden `200`/`201` con
`sunatResponse: {success: false, error: {code: "HTTP", ...}}` — un body JSON normal que el
caller puede inspeccionar. `despatch/send` y `despatch/status`, en el mismo escenario, devuelven
un **`500` crudo** (excepción PHP sin capturar, sin body JSON útil).

**Causa**: inconsistencia entre los controllers — los de `summary`/`voided`/`reversion` delegan
en `DocumentRequest::send()`, que captura la excepción de conexión y la normaliza al
`sunatResponse` de siempre; `DespatchController` reimplementa `send()`/`status()` inline (porque
usa `SeeApiFactory`/`Greenter\Api` en vez de `SeeFactory`/`Greenter\See`, ver `CLAUDE.md` —
sección Arquitectura) y no captura nada.

**Fix**: en `src/Controller/v1/DespatchController.php`, envolver la llamada a
`$see->send($document)` / `$see->getStatus($ticket)` en el mismo try/catch que usan
`SummaryController`/`VoidedController`/`ReversionController` (vía `DocumentRequest::send()`),
devolviendo `sunatResponse: {success: false, error: {code: "HTTP", message: "..."}}` en vez de
dejar propagar la excepción a un `500`. Como `DespatchController` no puede delegar tal cual en
`DocumentRequest` (está cableado a `SeeFactory`/`Greenter\See`, no a `SeeApiFactory`/
`Greenter\Api`), el try/catch hay que agregarlo directo en el controller o extender
`DocumentRequest` para que soporte ambos factories.

**Cómo verificar**: apuntar `FE_URL`/`RE_URL`/`GUIA_URL`/`AUTH_URL`/`API_URL` a un host que nunca
responde (mismo patrón que el servicio `lycet-sunat-down` de `demo-lycet`) y confirmar que
`despatch/send`/`despatch/status` responden `200` con `sunatResponse.success: false` en vez de
`500`.
