---
name: greenter
description: Cómo revisar si hay una versión nueva de greenter/greenter y actualizarla en este repo con composer sin romper nada, incluida la revisión OBLIGATORIA de la guía de remisión del transportista (tipoDoc 31), que este fork mantiene como copia derivada de greenter. Úsala antes de correr cualquier `composer update`/`composer require greenter/*`, ante cualquier versión nueva de greenter, y antes de decir "greenter está al día".
---

# greenter — cómo actualizar la librería

[`thegreenter/greenter`](https://github.com/thegreenter/greenter) es un **monorepo** (usa
`symplify/monorepo-builder`) que se publica en Packagist como varios paquetes independientes, no
uno solo. Este repo (`lycet-fork`) consume 8 de esos paquetes vía `composer.lock`:

```
greenter/core         (en composer.json)
greenter/lite         (en composer.json)
greenter/report       (en composer.json)
greenter/htmltopdf    (en composer.json)
greenter/xml          (transitivo, solo en composer.lock)
greenter/ws           (transitivo, solo en composer.lock)
greenter/gre-api      (transitivo)
greenter/xmldsig      (transitivo)
```

## La trampa: no se versionan en lockstep

`monorepo-builder` puede taggear un release (ej. `v5.3.0`) sin que **todos** los paquetes suban
de versión ese mismo release — algunos quedan atrás hasta el próximo bump. Eso es exactamente lo
que pasó en este repo: `core`/`lite`/`report`/`htmltopdf` están en `v5.3.0` pero `greenter/xml` y
`greenter/ws` quedaron pinneados en `v5.2.0` (ver skill [`lycet-fork`](../lycet-fork/SKILL.md),
Bug 1). **Nunca asumas que actualizar los paquetes que están en `composer.json`
(`core`/`lite`/`report`/`htmltopdf`) también actualiza los transitivos (`xml`/`ws`/`gre-api`/
`xmldsig`)** — hay que revisarlos y pinnearlos aparte.

## El CHANGELOG.md del monorepo no es confiable

`CHANGELOG.md` en la raíz del monorepo está desactualizado (se queda parado en versiones viejas
tipo `5.0.0` mientras Packagist ya tiene `5.3.0`+ publicado). No lo uses como fuente de "qué
cambió". En su lugar:

- **Qué cambió en un release**: [GitHub Releases](https://github.com/thegreenter/greenter/releases)
  (cada tag trae su propia descripción, más confiable que el changelog) o comparar tags
  directo: `https://github.com/thegreenter/greenter/compare/v5.2.0...v5.3.0`.
- **Qué versión es realmente la última publicada de cada paquete** (no la del monorepo en
  general): la página de Packagist de ese paquete puntual, ej.
  `https://packagist.org/packages/greenter/xml`, o `composer show -a greenter/xml` local.

## Pasos para actualizar

1. **Ver qué versión de cada paquete `greenter/*` está pinneada hoy**, no solo los del
   `composer.json`:
   ```bash
   python3 -c "
   import json
   d = json.load(open('composer.lock'))
   for p in d['packages']:
       if p['name'].startswith('greenter/'):
           print(p['name'], p['version'])
   "
   ```
2. **Ver qué versión hay disponible** para cada uno, y si el release toca la guía de remisión
   (`greenter/xml`, modelos `Despatch`), leer el paso 5 antes de seguir (Packagist o `composer show -a
   greenter/<paquete>`), y leer el/los release(s) intermedios en GitHub Releases para saber si
   traen breaking changes o requieren tocar algo en lycet (serializers YAML en
   `config/serializer/`, nuevos campos en modelos, etc. — ver "Serialización" en el `CLAUDE.md`
   raíz).
3. **Actualizar solo los paquetes `greenter/*` que necesitás**, nunca un `composer update` a
   secas (regla del `CLAUDE.md` raíz — arrastra bumps de Symfony no relacionados):
   ```bash
   composer update greenter/core greenter/xml greenter/ws greenter/lite greenter/report greenter/htmltopdf
   ```
   Si el paquete es transitivo (no aparece en el `require` de `composer.json`, ej. `xml`/`ws`/
   `gre-api`/`xmldsig`) y `composer update greenter/xml` no lo mueve porque no hay constraint
   explícito, agregalo directo con `composer require greenter/xml:^5.3 greenter/ws:^5.3` — eso
   fija el constraint en `composer.json` y resuelve en el mismo paso.
4. **Confirmar el resultado en `composer.lock`**, repitiendo el comando del paso 1: los 8
   paquetes `greenter/*` deberían quedar en la misma versión mayor/minor esperada (no basta con
   mirar los 4 que están en `composer.json`).
5. **🚩 OBLIGATORIO en todo bump: revisar la guía del transportista (`tipoDoc` 31).**
   Este fork agrega ese documento por su cuenta porque greenter no lo modela — ver la skill
   [`lycet-fork`](../lycet-fork/reference/estado-proyecto.md). Es lo único del repo que **depende
   de una copia** de código de greenter, así que **ninguna versión nueva se da por aplicada sin
   pasar por acá**. Son dos preguntas, en este orden:

   **5a. ¿La versión nueva ya trae la 31 de fábrica?** Si greenter la implementó, lo nuestro sobra
   y hay que **borrarlo y usar el suyo** — mantener una copia propia de algo que la librería ya
   resuelve es la peor de las dos opciones.

   ```bash
   # ¿aparecieron el remitente y su DespatchParty en la plantilla del 09?
   curl -s https://raw.githubusercontent.com/thegreenter/greenter/vNUEVA/packages/xml/src/Xml/Templates/despatch2022.xml.twig \
     | grep -c "DespatchParty\|remitente"
   # ¿se movió el issue historico, o se mergeo alguno de los PRs de la comunidad?
   #   https://github.com/thegreenter/greenter/issues/227   (abierto desde 2023)
   #   https://github.com/thegreenter/greenter/pull/243  ·  .../pull/247  (cerrados sin mergear)
   ```

   Si la respuesta es sí: migrar a su modelo/plantilla y eliminar `src/Model/DespatchCarrier.php`,
   `src/Xml/`, `src/Greenter/CarrierApi.php`, `config/serializer-app/` y la rama por `tipoDoc` de
   `DespatchController` — dejándolo anotado en la skill `lycet-fork`. Revisar de paso si su
   implementación resuelve la **discrepancia pendiente** (si la 31 lleva `HandlingCode` y
   `TransportModeCode`, que el PR #247 deja y nosotros omitimos).

   **5b. Si no la trae, re-derivar nuestra plantilla.**
   `src/Xml/Templates/despatchCarrier.xml.twig` es una **copia derivada** de
   `despatch2022.xml.twig` — la del remitente (09). Un bump de `greenter/xml` actualiza la del 09 y
   **deja la nuestra congelada, sin que falle nada**: la 31 sigue emitiendo con la estructura vieja
   hasta que SUNAT la rechace, con el camión cargado. Es el único riesgo silencioso del repo.

   ```bash
   # diff de la plantilla del 09 entre la version vieja y la nueva
   git -C <clon de thegreenter/greenter> diff v5.3.0..vNUEVA -- \
       packages/xml/src/Xml/Templates/despatch2022.xml.twig
   ```

   Si no cambió nada, no hay nada que hacer. Si cambió, re-derivar: partir de la nueva
   `despatch2022.xml.twig` y volver a aplicar las **4 diferencias** del 31, que están listadas en
   la cabecera de `despatchCarrier.xml.twig` y en la skill `lycet-fork` — suma `cac:DespatchParty`
   (el remitente) y omite `cbc:HandlingCode`, `cbc:TransportModeCode` y
   `cac:SellerSupplierParty`. `tests/Controller/v1/DespatchCarrierControllerTest.php` verifica esas
   4 diferencias, pero **no** detecta un campo nuevo que greenter haya agregado y nosotros no —
   eso solo lo ve el diff de arriba.

   Pase lo que pase, dejar en la skill `lycet-fork` contra qué versión de greenter se revisó por
   última vez, aunque la conclusión haya sido "no cambió nada".
6. **Correr los tests**: `php bin/phpunit`.
7. **Si el cambio toca generación de XML o envío a SUNAT** (`greenter/xml`, `greenter/ws`,
   `greenter/gre-api`), correr tests no alcanza — verificar contra SUNAT real (beta), porque
   SUNAT cambia reglas de validación con el tiempo (ej. `fecEntregaBienes` obligatorio desde
   2026-06-01, ver skill `lycet-fork`) y esas reglas no se detectan con `/xml` (solo firma
   localmente) ni con un test unitario que no pega contra SUNAT.
8. Commitear `composer.json` (si cambió) + `composer.lock` juntos.

## Si el bump es de versión mayor (`^5.x` → `^6.x`)

Todo lo de arriba asume minor/patch (el caso típico: `xml`/`ws` atrasados en minor respecto al
resto). Si Packagist muestra un salto de versión mayor, el riesgo de breaking change es real y
hay un paso extra antes del punto 3:

- Buscar un archivo `UPGRADE-N.md` en la raíz del monorepo (ej.
  `https://github.com/thegreenter/greenter/blob/master/UPGRADE-4.md` es el que existe hoy, para
  la migración a v4) y leerlo completo si existe uno para la versión mayor destino.
- Cambiar el constraint en `composer.json` (`^5.3` → `^6.0`, etc.) es una decisión explícita, no
  algo que `composer update` haga solo — no lo hagas sin haber leído qué cambia.
- Esperar romper algo en `config/serializer/*.yml` o en los factories (`SeeFactory`/
  `SeeApiFactory`) si el major bump renombra clases o métodos del modelo — revisar diff de la
  librería (`compare/vN...vN+1` en GitHub) en los paquetes que este repo realmente usa, no todo
  el monorepo.

## Después de actualizar

Si el bump afecta a `Despatch` (guía de remisión) o toca algo descrito en la skill `lycet-fork`,
actualizar `reference/estado-proyecto.md` de esa skill con el nuevo estado — no dejarlo
desactualizado.
