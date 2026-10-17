#!/usr/bin/env bash
# Paczka dla klienta w build/paczka: pliki śledzone w git z listy dev/package-allowlist.txt,
# vendor/ z composer install --no-dev, na końcu weryfikacja wyniku (dowodem jest paczka, nie źródła).
# Kodowanie strefy /src/License i /src/Core ionCube Encoderem to osobny krok (CLAUDE.md §15).
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
out="$root/build/paczka"
# Zgodność statyczna przed budowaniem: zakres instalacyjny 8.3–8.5 (bramka z §0), jeden zestaw reguł —
# Encoder 14 obsługuje składnię 8.3, więc osobny przebieg „od 8.2” stracił sens (§15). Kod paczki, bez dev/ i tests/.
"$root/vendor/bin/phpcs" -q --standard="$root/phpcs.xml.dist" --basepath="$root" \
    "$root/src" "$root/bin" "$root/config" "$root/db" "$root/public"
# ⚠ Paczka bierze pliki ŚLEDZONE W GIT (`git ls-files`), nie zawartość katalogu roboczego. Niezacommitowana
# klasa albo szablon po prostu nie pojedzie, a paczka wywróci się dopiero przy żądaniu u klienta —
# raz już tak było (Class "Wezurlop\Support\NazwaOsoby" not found). Dlatego build ZATRZYMUJE SIĘ tutaj.
niezapisane="$(git -C "$root" status --porcelain -- src templates config public db bin | grep -v '^ D' || true)"
if [ -n "$niezapisane" ]; then
    echo "BUILD ZATRZYMANY: pliki poza gitem nie trafią do paczki — zacommituj je albo wycofaj:" >&2
    echo "$niezapisane" >&2
    exit 1
fi
rm -rf "$out"
mkdir -p "$out"
# ⚠ Numer wydania: data i skrót commita. Wchodzi do manifestu jako wersja zasobu, którego
# w manifeście nie ma — stały napis znaczyłby adres, który nigdy się nie zmienia, czyli plik
# zamrożony w przeglądarce klienta (rozstrzygnięcie 22.09.2026). Liczone TUTAJ, bo `exec`
# w kodzie PHP jest zakazany (CLAUDE.md §14).
WYDANIE="$(date -u +%Y%m%d)-$(git -C "$root" rev-parse --short HEAD)"
echo "numer wydania paczki: $WYDANIE"
export WYDANIE
git -C "$root" ls-files | php "$root/dev/build-package.php" copy "$out"
composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --quiet --working-dir="$out"
composer check-platform-reqs --no-dev --working-dir="$out" > /dev/null
php "$root/dev/build-package.php" verify "$out"
