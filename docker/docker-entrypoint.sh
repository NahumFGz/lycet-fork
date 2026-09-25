#!/usr/bin/env sh

# La imagen no trae valores por defecto (ver Dockerfile): sin estas variables lycet firmaria o
# enviaria con credenciales o URLs que no son las del ambiente, asi que no arranca.
faltan=''
for var in CLIENT_TOKEN SOL_USER SOL_PASS FE_URL RE_URL GUIA_URL AUTH_URL API_URL; do
    eval "valor=\${$var:-}"
    [ -z "$valor" ] && faltan="$faltan $var"
done
if [ -n "$faltan" ]; then
    echo "lycet: faltan variables de entorno:$faltan" >&2
    exit 1
fi

# Solo las usa la guia de remision (API REST con OAuth2); una empresa registrada en
# empresas.json puede traer las suyas.
for var in CLIENT_ID CLIENT_SECRET; do
    eval "valor=\${$var:-}"
    [ -z "$valor" ] && echo "lycet: $var vacia, la guia de remision falla salvo para las empresas de empresas.json" >&2
done

ARGS='--host 0.0.0.0 --port 8000'

# symfony bootstrap
ARGS="$ARGS --bootstrap=symfony --app-env=$APP_ENV --logging=0 --debug=0"

# make sure static-directory is not served by php-pm
ARGS="$ARGS --static-directory=''"

# no limits
ARGS="$ARGS --max-execution-time 0"

# increase body buffer for large payloads (logo base64, etc). Default is 64KB.
ARGS="$ARGS --request-body-buffer=524288"

php vendor/bin/ppm start $ARGS $@

