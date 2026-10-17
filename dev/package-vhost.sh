#!/usr/bin/env bash
# Paczka na lokalnym vhoście testowym http://paczka.localhost:8090 (nasłuch wyłącznie 127.0.0.1,
# /etc/httpd/conf.d/tenant-paczka.conf) — test idzie na tym, co wyszło z builda, nie na źródłach (§15).
# Ten sam vhost posłuży później do sprawdzenia paczki PO kodowaniu ionCube.
# Baza: domyślnie ta sama co dev, albo inna przez PACZKA_DB (np. <nazwa-bazy>_test — wtedy vhost jest
# instancją testową i regresja nie dotyka bazy, na której trwa przebieg integracyjny).
# Instalację zastępuje znacznik install.lock; APP_ENV z .env deweloperskiego.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
out="$root/build/paczka"
"$root/dev/build-package.sh"
# var/ i storage/ paczki leżą POZA katalogiem builda (ścieżki są konfigurowalne, CLAUDE.md §0), żeby
# kolejny build mógł usunąć build/paczka bez roota — pliki w var/ tworzy apache prawami 700.
state="$root/build/paczka-stan"
if [ ! -e "$state/.przygotowany" ]; then
    mkdir -p "$state/var/cache" "$state/storage/logs" "$state/storage/uploads"
    chgrp -R apache "$state"
    chmod -R g+rwX "$state"
    find "$state" -type d -exec chmod g+s {} +
    touch "$state/.przygotowany"
fi
sed -e 's#^APP_URL=.*#APP_URL=http://paczka.localhost:8090#' \
    -e 's#^APP_BASE_PATH=.*#APP_BASE_PATH=#' \
    -e 's#^APP_FRONT_CONTROLLER=.*#APP_FRONT_CONTROLLER=#' \
    -e "s#^LICENSE_PUBLIC_KEY_PATH=.*#LICENSE_PUBLIC_KEY_PATH=$state/var/license-dev-public.key#" \
    "$root/.env" > "$out/.env"
{ echo "VAR_PATH=$state/var"; echo "STORAGE_PATH=$state/storage"; } >> "$out/.env"
if [ -n "${PACZKA_DB:-}" ]; then
    sed -i "s/^DB_NAME=.*/DB_NAME=${PACZKA_DB}/" "$out/.env"
    echo "baza paczki: ${PACZKA_DB}"
fi
cp "$root/var/license-dev-public.key" "$state/var/"
date -u +%FT%TZ > "$state/var/install.lock"
chgrp apache "$out/.env"
chmod 0640 "$out/.env"
echo "paczka gotowa pod http://paczka.localhost:8090 (127.0.0.1)"
