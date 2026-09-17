---
name: greenter
description: Cómo revisar si hay una versión nueva de greenter/greenter y actualizarla en este repo con composer sin romper nada. Úsala antes de correr cualquier `composer update`/`composer require greenter/*`, y antes de decir "greenter está al día".
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
2. **Ver qué versión hay disponible** para cada uno (Packagist o `composer show -a
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
5. **Correr los tests**: `php bin/phpunit`.
6. **Si el cambio toca generación de XML o envío a SUNAT** (`greenter/xml`, `greenter/ws`,
   `greenter/gre-api`), correr tests no alcanza — verificar contra SUNAT real (beta), porque
   SUNAT cambia reglas de validación con el tiempo (ej. `fecEntregaBienes` obligatorio desde
   2026-06-01, ver skill `lycet-fork`) y esas reglas no se detectan con `/xml` (solo firma
   localmente) ni con un test unitario que no pega contra SUNAT.
7. Commitear `composer.json` (si cambió) + `composer.lock` juntos.

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
