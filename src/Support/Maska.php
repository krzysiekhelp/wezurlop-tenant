<?php

declare(strict_types=1);

namespace Wezurlop\Support;

/**
 * Zamaskowany adres e-mail — do pokazania **komuś, kto nie jest właścicielem konta**.
 *
 * ⚠ Powód istnienia: ekran „ten link dotyczy innego konta" widzi osoba, która na to konto
 * nie ma prawa. Ma z niego rozpoznać, **czy to jej link**, i nie dowiedzieć się niczego więcej
 * (zlecenie `link-hasla-przy-zalogowanej-sesji` §2.6). Pełny adres byłby daną, której ta osoba
 * nie miała.
 *
 * ⚠ Maska zachowuje **początek** i domenę najwyższego poziomu, bo po nich człowiek rozpoznaje
 * swój adres. Nie zachowuje długości: `re****@pi****.pl` dla „renata…" i dla „rena…" wygląda
 * tak samo, więc z maski nie da się odczytać, ile znaków ma adres.
 */
final class Maska
{
    private const GWIAZDKI = '****';

    public static function email(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at < 1) {
            return self::GWIAZDKI;
        }
        $lokalna = substr($email, 0, $at);
        $domena = substr($email, $at + 1);
        $kropka = strrpos($domena, '.');
        $koncowka = $kropka === false ? '' : substr($domena, $kropka);

        return self::skrot($lokalna) . '@' . self::skrot($kropka === false ? $domena : substr($domena, 0, $kropka)) . $koncowka;
    }

    private static function skrot(string $czesc): string
    {
        return mb_substr($czesc, 0, mb_strlen($czesc) > 2 ? 2 : 1) . self::GWIAZDKI;
    }
}
