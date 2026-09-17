---
name: lycet-fork
description: Contexto del fork lycet-fork (por qué existe, qué bugs corrige, estado de cada fix). Úsala antes de tocar composer.json/composer.lock (versiones de greenter/*), src/Controller/v1/DespatchController.php, o config/serializer/Despatch.*.yml — y siempre antes de decir que un bug ya está resuelto.
---

# lycet-fork — contexto y estado

Fork de [`giansalex/lycet`](https://github.com/giansalex/lycet) (wrapper REST/Symfony sobre
[`greenter/greenter`](https://github.com/thegreenter/greenter)). Existe solo para corregir dos
bugs encontrados en producción-de-prueba contra SUNAT real, diagnosticados desde el repo
consumidor `demo-lycet` (Nest/TypeScript, sin poder tocar PHP desde ahí).

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

En `demo-lycet`, `docker-compose.yml` buildea la imagen `lycet` directo desde git
(`build.context: https://github.com/giansalex/lycet.git#${LYCET_GIT_SHA}`). Una vez los fixes
estén commiteados acá, el único cambio río abajo es repuntar esa URL/SHA a
`https://github.com/NahumFGz/lycet-fork.git#<sha>` — no hace falta vendorizar ni tocar el backend
TypeScript.
