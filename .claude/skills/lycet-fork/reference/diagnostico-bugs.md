# Diagnóstico completo de los bugs corregidos en el fork

Los bugs 3 a 6 salieron de revisar el fork para usarlo en producción (2026-09-25),
probando la imagen de producción bajo php-pm; están al final. Los bugs 1 y 2, con evidencia y
reproducción detallada en el repo consumidor (`demo-lycet`, un backend Nest/TypeScript que llama
a lycet vía HTTP, sin tocar PHP):
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

**Causa real** (corregido tras leer el código vendorizado — la hipótesis inicial de "copiar el
try/catch de `SummaryController`/`VoidedController`/`ReversionController`" era incorrecta,
**esos controllers no tienen ningún try/catch propio**): la resiliencia de los tipos SOAP
(`Invoice`/`Note`/`Summary`/`Voided`/`Reversion`/`Perception`/`Retention`) no vive en
`lycet-fork` sino dentro de `greenter/ws`: `BillSender::send()`
(`vendor/greenter/ws/src/Ws/Services/BillSender.php`) atrapa `SoapFault` internamente y devuelve
un `BillResult` con `success: false`. `Despatch` usa un sender distinto, `GreSender`
(`vendor/greenter/ws/src/Api/GreSender.php`), que **también** atrapa `ApiException` en
`send()`/`status()` — pero antes de llegar ahí, `Greenter\Api::createSender()`
(`vendor/greenter/lite/src/Greenter/Api.php`) hace un intercambio OAuth2 (`AuthApi`) para
obtener el token de acceso al GRE API. Si ese host (`AUTH_URL`) está caído, Guzzle tira una
excepción de conexión cruda (`GuzzleHttp\Exception\ConnectException`, no `ApiException`) que
nada atrapa antes de llegar a `DespatchController` sin protección → `500`.

**Fix aplicado**: en `src/Controller/v1/DespatchController.php`, envolver `$see->send($document)`
(en `send()`) y `$see->getStatus($ticket)` (en `status()`) en `try { ... } catch (\Throwable $e)`
—`\Throwable` y no un tipo específico porque el fallo puede venir de Guzzle, de `ApiException`,
o de cualquier otra cosa en la cadena de auth+envío— construyendo a mano un
`Greenter\Model\Response\SummaryResult`/`StatusResult` (los mismos tipos que usa `GreSender`
internamente) con `setError(new Error('HTTP', $e->getMessage()))`. `SeeApiFactory::build()` se
deja fuera del try: solo configura credenciales desde `.env`/`empresas.json`, no hace red.

**Cómo verificar**: apuntar `AUTH_URL`/`API_URL` a un host que nunca responde (ej.
`http://127.0.0.1:65535/v1`, mismo patrón que el servicio `lycet-sunat-down` de `demo-lycet`;
`FE_URL`/`RE_URL`/`GUIA_URL` son del cliente SOAP legado y no los usa `Despatch`) y confirmar que
`despatch/send`/`despatch/status` responden `200` con `sunatResponse.success: false` y
`error.code: "HTTP"` en vez de `500`. Verificado 2026-09-17: ambos devuelven `200` con el error
normalizado (`cURL error 7: ... oauth2/token`), y el flujo normal contra el sandbox GRE test
sigue funcionando igual después del fix.

## Bug 3 — los errores de `/api` respondían 502 y reiniciaban el worker de php-pm

**Síntoma**: en la imagen de producción (php-pm), un token inválido, un JSON roto, un cuerpo
vacío o un documento sin `company` respondían **`502`** ("Slave returned an invalid HTTP
response") y el log decía `Forcing restart of the worker`. Un campo con el tipo equivocado
respondía `500` con la página HTML de Symfony. Para un consumidor, "lycet caído" y "mandé algo
mal" eran indistinguibles. En `phpunit` no se veía porque `WebTestCase` deja escapar la excepción
y los tests la esperaban (`expectException`).

**Causa real** (dos distintas):
- *Token inválido*: `AccessDeniedHttpException` es una excepción normal, pero Symfony 5.4 arma la
  página de error con una **sub-petición** (`ErrorListener::onKernelException`) y
  `TokenSubscriber` la revisaba igual que la petición del cliente. Volvía a fallar ("Exception
  thrown when handling an exception"), la excepción escapaba del kernel y php-pm respondía 502.
- *JSON roto / sin `company`*: terminaban en un **`\Error`** de PHP (`array_key_exists()` sobre
  null en `DocumentRequestParser`, `getRuc()` sobre null en `DocumentRequest`/`DespatchController`).
  `HttpKernel::handle()` de Symfony 5.4 solo atrapa `\Exception`: el `\Error` sale del kernel sin
  respuesta y php-pm corta la conexión.

**Fix aplicado**: `App\EventSubscriber\ApiExceptionSubscriber` responde JSON (`{"message"}` con el
status real) para toda excepción de `/api/*` antes de que ocurra la sub-petición, y envuelve el
controlador para convertir cualquier `\Error` en una excepción común. `TokenSubscriber` ignora
las sub-peticiones. `DocumentRequestParser` responde 400 si el cuerpo no es un objeto JSON o no
deserializa, y `DocumentRequest::getDocument()` 400 si falta `company.ruc`.

**Cómo verificar**: contra la imagen de producción, no con `phpunit` solo — `token=x`,
`{"tipoDoc":`, `{"tipoDoc":"01"}` y `details` como texto responden 403/400/400/400 en JSON, y
`docker logs` no muestra ningún `Forcing restart`.

## Bug 4 — `despatch/send` sin `hash`, y sin `xml` cuando SUNAT no responde

**Síntoma**: el resto de comprobantes responde `{xml, hash, sunatResponse}` y trae el XML firmado
aunque SUNAT no conteste (el comprobante ya existe al firmarse). Las guías nunca traían `hash`, y
con SUNAT caída tampoco `xml`.

**Causa real**: `DespatchController::send()` leía `getLastXml()` dentro del `try`, después del
envío. Y no se podía simplemente moverlo afuera: `Greenter\Api` (`CarrierApi`) es un **servicio
compartido** y el worker de php-pm lo reusa entre peticiones, así que si el armado o la firma
fallaban, `getLastXml()` devolvía el XML de **otra guía anterior**.

**Fix aplicado**: `CarrierApi` sobreescribe `sendXml()` —por donde pasan las dos guías, con el XML
ya firmado y antes del OAuth2— para guardar ese XML, y lo borra al empezar cada `send()`.
`DespatchController` lo lee después del `try` y agrega `hash` (`XmlUtils::getHashSign`, igual que
`DocumentRequest`). Si no hay XML, lo que falló fue armar o firmar: se relanza (500) en vez de
reportarlo como error `"HTTP"`, que invita a reintentar.

**Cómo verificar**: `tests/Controller/v1/DespatchSendTest.php` (SUNAT caída = puerto local
cerrado; y dos envíos en el mismo kernel, el segundo con un certificado que no firma). En la
imagen: con `AUTH_URL`/`API_URL` inalcanzables, la 31 responde `xml` y un `hash` igual al
`ds:DigestValue` del XML.

## Bug 5 — la imagen no instalaba `composer.lock`

**Síntoma**: la imagen de `2b66031` tenía **24 de los 67 paquetes** del lock con otra versión
(`guzzlehttp/psr7` 2.6.2 → 2.13.1, `psr/log` 1 → 2, `symfony/http-kernel` 5.4.33 → 5.4.53, …):
los tests corrían contra una cosa y producción contra otra.

**Causa real**: el Dockerfile agregaba php-pm con `composer require ... --with-all-dependencies`,
que vuelve a resolver dependencias en cada build. Como `greenter/*` está en `^5.3`, el día que
salga un `greenter/xml` nuevo, reconstruir el mismo commit lo traería **sin la revisión de la
plantilla de la 31** que pide la skill `greenter`.

**Fix aplicado**: php-pm en `composer.json`, el Dockerfile solo hace `composer install`, y el lock
fija exactamente las versiones que la imagen ya corría (nada cambió en producción).
`config.platform.php` = 8.1.26, el PHP de la imagen.

**Cómo verificar**: comparar `vendor/composer/installed.json` de la imagen con `composer.lock`:
cero diferencias.

## Bug 6 — la imagen traía credenciales y URLs de prueba por defecto

**Síntoma**: `ENV CLIENT_TOKEN 123456`, las credenciales `MODDATOS`, las URLs del beta de SUNAT y
el `CLIENT_ID`/`CLIENT_SECRET` del sandbox GRE venían en el Dockerfile. Si a un ambiente de
producción le faltaba una variable, lycet caía en silencio a esos valores — con las URLs,
mandaba al beta, que "acepta" comprobantes que SUNAT nunca recibió.

**Fix aplicado**: sin esos `ENV`; `docker/docker-entrypoint.sh` no arranca si falta
`CLIENT_TOKEN`, `SOL_USER`, `SOL_PASS` o una URL, y avisa si faltan `CLIENT_ID`/`CLIENT_SECRET`.
`.dockerignore` excluye `data/` (salvo `.htaccess`) y los `.env` locales, para que un build local
no se lleve el certificado.
