# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Lycet is a Symfony 5.4 / PHP 7.4+ REST API wrapper around [`greenter/greenter`](https://github.com/thegreenter/greenter), exposing invoicing/CPE operations (invoices, notes, despatch guides, summaries, voided/reversion, perception, retention) over HTTP so non-PHP consumers can talk to SUNAT (Peru's tax authority) without embedding greenter's PHP libs directly.

This is `lycet-fork`, forked from `giansalex/lycet` to fix two production bugs found while integration-testing against real SUNAT, and to add one document greenter does not model at all: the **carrier's despatch guide** (Guía de Remisión del Transportista, `tipoDoc` 31). Load the `lycet-fork` skill (`.claude/skills/lycet-fork/`) before touching `DespatchController`, `config/serializer/Despatch.*.yml`, `composer.json`'s `greenter/*` pins, or anything under `src/Model/`, `src/Xml/`, `src/Greenter/` — it documents why the two bugs happen, what the carrier guide adds, current status, and how to verify against real SUNAT.

## Commands

```bash
# Install dependencies
composer install -o

# Run the app (built-in PHP server)
php -S 0.0.0.0:8000 -t public

# Run tests (Symfony PHPUnit bridge)
php bin/phpunit
# Single test file
php bin/phpunit tests/Controller/v1/InvoiceControllerTest.php
# Single test method
php bin/phpunit --filter testMethodName tests/Controller/v1/InvoiceControllerTest.php

# Symfony console
php bin/console <command>

# Update only specific greenter packages (never run a bare `composer update`,
# it will drag in unrelated Symfony bumps)
composer update greenter/xml greenter/ws
```

Tests need `.env.test.local` (CI copies `.env.test` if missing) and a cert/logo in `data/` — see `tests/Resources/`.

Docker: `docker build -t lycet .` then run with `data/` volume-mounted (cert.pem, logo.png, optional `empresas.json`) and every env var passed explicitly — the image has no defaults for `CLIENT_TOKEN`, SOL credentials or SUNAT URLs, and `docker/docker-entrypoint.sh` refuses to start without them (test values live in `.env`). The Dockerfile only runs `composer install`, so the image carries exactly `composer.lock`. CI (`.github/workflows/symfony.yml`) runs `php bin/phpunit` on PHP 8.1 (same as the image) against every push/PR to `master`, then builds the image and, on push to `master`, publishes it as `ghcr.io/nahumfgz/lycet-fork:<sha>`.

**Errors under php-pm**: the prod image runs Symfony inside php-pm workers, which turn anything escaping the kernel into a `502` plus a worker restart — and Symfony 5.4's `HttpKernel` only catches `\Exception`, not `\Error`. `App\EventSubscriber\ApiExceptionSubscriber` renders every `/api/*` exception as JSON and converts `\Error`s thrown by controllers; keep new input validation as `BadRequestHttpException` (400). `phpunit` alone doesn't show these failures — verify error paths against the built image (bug 3 in the `lycet-fork` skill). Also: services are shared across requests in a worker, so never read per-request state left on a service (see `CarrierApi::sendXml()`).

## Architecture

**Request flow**: `Controller/v1/*Controller` → `DocumentRequestInterface` (impl: `DocumentRequest`) → `RequestParserInterface` (impl: `DocumentRequestParser`, JMS-serializer-based JSON→greenter-model deserialization, configured per-type by `config/serializer/*.yml`) → a `See`/`Api` factory → greenter's SOAP/API client → SUNAT/OSE.

**Two parallel client paths — this is the key thing to know before changing behavior for one document type and assuming it applies to all:**
- Most document types (`Invoice`, `Note`, `Summary`, `Voided`, `Reversion`, `Perception`, `Retention`) go through `SeeFactory` → greenter's legacy `Greenter\See` (SOAP `billService`), and their controllers delegate straight to `DocumentRequest::send()/xml()/pdf()`.
- `Despatch` (guía de remisión) goes through `SeeApiFactory` → greenter's newer `Greenter\Api` (REST GRE API, since despatch guides can be sent to SUNAT's "Guía Electrónica Remitente" API instead of only SOAP). `DespatchController` does **not** delegate to `DocumentRequest` — it reimplements `send()`/`status()` inline, which is why it drifts from the other controllers' behavior (see the `lycet-fork` skill, Bug 2).

**Carrier despatch guide (`tipoDoc` 31) — this fork's own addition.** greenter models only the *sender's* guide (09), so the 31 is built outside its pipeline: `App\Model\DespatchCarrier` (the 09 model plus `remitente`), `App\Xml\Builder\DespatchCarrierBuilder` (own Twig loader over `src/Xml/Templates/`, since greenter's template dir is hardcoded), and `App\Greenter\CarrierApi` — registered in `services.yaml` as the `Greenter\Api` service — which intercepts `send()` for that model, signs the XML itself and hands it to the ordinary `Api::sendXml()`. `DespatchController` picks the model by `tipoDoc` on the same `/despatch/*` routes. Everything else still goes through greenter untouched. **The template is a derivative of greenter's `despatch2022.xml.twig`, so any new greenter version must be reviewed against it** — step 5 of the `greenter` skill, which is mandatory on every bump: if greenter has since implemented the 31, drop all of this and use theirs; if not, re-derive the template and re-apply the four deltas (listed in its header comment and in the `lycet-fork` skill). It fails silently otherwise — the guide keeps emitting with the old structure until SUNAT rejects it.

**Multi-company config**: `ConfigProviderInterface` has two implementations — `EnvConfigProvider` (reads `.env`: `SOL_USER`, `SOL_PASS`, `CLIENT_ID`, etc.) and `FileConfigProvider` (reads per-RUC overrides from `data/empresas.json`, managed at runtime via `ConfigurationController`'s REST API). Both `SeeFactory`/`SeeApiFactory` try the RUC-specific company config first, falling back to `.env` defaults. Certificates/logos are read via `FileDataReader` from `data/`.

**Serialization**: JMS Serializer with explicit per-model YAML mappings in `config/serializer/` (e.g. `Despatch.Shipment.yml`), not annotations — when a greenter model gains/changes a property, the corresponding YAML must be updated or JMS will silently ignore it on (de)serialization.

**Report rendering**: PDF generation goes through `greenter/htmltopdf` via `HtmlReportDecorator`/`PdfReportDecorator`, requiring the `WkhtmltoPdf` executable (`WKHTMLTOPDF_PATH` env var).

## Versioning greenter packages

`composer.json` pins `greenter/core|htmltopdf|lite|report` directly, but `greenter/xml` and `greenter/ws` are pulled in transitively and can lag behind (`composer.lock` currently has them at `v5.2.0` while the rest sit at `v5.3.0` — this mismatch is Bug 1 in the `lycet-fork` skill). When bumping greenter, check `composer.lock` for every `greenter/*` package version, not just the ones listed in `composer.json`'s `require`.
