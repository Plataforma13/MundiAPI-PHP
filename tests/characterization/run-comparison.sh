#!/bin/sh
# Compara o SDK deste diretorio de trabalho com uma tag de referencia, cenario
# a cenario, rodando a referencia num PHP e o candidato em outro.
#
#   PHP_REF=/caminho/php7.0  COMPOSER_REF=/caminho/composer-2.2.phar \
#   PHP_NEW=/caminho/php8.4  COMPOSER_NEW=/caminho/composer.phar \
#   tests/characterization/run-comparison.sh [tag-de-referencia]
#
# A tag padrao e a 3.1.18, com as dependencias fixadas nas versoes em uso
# com ela (apimatic/unirest-php 2.3.0 e apimatic/jsonmapper v1.3.1).
# O candidato e instalado por um repositorio "path" do Composer, entao a
# resolucao usa o composer.json real do SDK.
#
# Variaveis opcionais:
#   WORK_DIR   diretorio de trabalho (padrao: um temporario novo)
#   ONLY       expressao regular para rodar so alguns cenarios
#
# Codigo de saida 0 quando nao ha diferenca fora de accepted-differences.php.

set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../.." && pwd)
REF_TAG=${1:-3.1.18}

: "${PHP_REF:?defina PHP_REF com o binario do PHP da referencia (ex.: PHP 7.0)}"
: "${PHP_NEW:?defina PHP_NEW com o binario do PHP do candidato (ex.: PHP 8.4)}"
: "${COMPOSER_REF:?defina COMPOSER_REF com um composer.phar que rode em PHP_REF (2.2 LTS para PHP 7.0)}"
: "${COMPOSER_NEW:?defina COMPOSER_NEW com um composer.phar que rode em PHP_NEW}"

WORK=${WORK_DIR:-$(mktemp -d)}
mkdir -p "$WORK/ref/sdk" "$WORK/new"
echo "diretorio de trabalho: $WORK"

REF_VERSION=$("$PHP_REF" -n -r 'echo PHP_VERSION;')
NEW_VERSION=$("$PHP_NEW" -n -r 'echo PHP_VERSION;')

# --- referencia: arvore da tag + dependencias fixadas
git -C "$REPO" archive "$REF_TAG" | tar -x -C "$WORK/ref/sdk"
cat > "$WORK/ref/composer.json" <<EOF
{
    "name": "harness/referencia",
    "require": {
        "apimatic/unirest-php": "2.3.0",
        "apimatic/jsonmapper": "v1.3.1"
    },
    "autoload": { "psr-4": { "MundiAPILib\\\\": "sdk/src/" } },
    "config": { "platform": { "php": "$REF_VERSION" } }
}
EOF
(cd "$WORK/ref" && "$PHP_REF" "$COMPOSER_REF" install --no-interaction --no-progress --quiet)

# --- candidato: este diretorio de trabalho, pelo composer.json real
cat > "$WORK/new/composer.json" <<EOF
{
    "name": "harness/candidato",
    "repositories": [
        { "type": "path", "url": "$REPO", "options": { "symlink": true } }
    ],
    "require": {
        "plataforma13/mundiapi-php": "*@dev"
    },
    "config": { "platform": { "php": "$NEW_VERSION" } }
}
EOF
(cd "$WORK/new" && "$PHP_NEW" "$COMPOSER_NEW" install --no-interaction --no-progress --quiet)

ONLY_ARG=""
if [ -n "${ONLY:-}" ]; then
    ONLY_ARG="--only=$ONLY"
fi

# os servidores falsos rodam no mesmo PHP nas duas execucoes
"$PHP_REF" -n "$HERE/run.php" --autoload="$WORK/ref/vendor/autoload.php" --out="$WORK/referencia.json" \
    --label="$REF_TAG" --server-php="$PHP_NEW" $ONLY_ARG 2> "$WORK/referencia.log"
"$PHP_NEW" -n "$HERE/run.php" --autoload="$WORK/new/vendor/autoload.php" --out="$WORK/candidato.json" \
    --label="$(git -C "$REPO" rev-parse --abbrev-ref HEAD)@$(git -C "$REPO" rev-parse --short HEAD)" \
    --server-php="$PHP_NEW" $ONLY_ARG 2> "$WORK/candidato.log"

"$PHP_NEW" -n "$HERE/compare.php" "$WORK/referencia.json" "$WORK/candidato.json"
