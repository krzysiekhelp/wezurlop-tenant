<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use RuntimeException;

/**
 * Adres e-mail należy już do innej osoby albo do innego konta (zlecenie Z40, 18.10.2026).
 *
 * ⚠ To **nie jest** błąd w kodzie, tylko odmowa na wejściu człowieka — ta sama konwencja
 * co `RozdzialRolException`: wyjątek mieszka przy warstwie, która go rzuca, a treść komunikatu
 * składa warstwa widoku (`Support\AdresKontaktowy`).
 *
 * ⚠ Rzucany z DWÓCH powodów i to jest sedno: sprawdzenie przed zapisem (`PersonRepository::
 * ktoMaAdres()`) odpowiada na pytanie „czy ten adres jest wolny TERAZ", a klucz unikalny
 * odpowiada na pytanie „czy był wolny W CHWILI ZAPISU". Między jednym a drugim mieści się
 * drugi administrator zapisujący ten sam adres — i bez tłumaczenia błędu 1062 na tę samą
 * odmowę wyścig kończyłby się stroną błędu 500 zamiast zdaniem, co poprawić (CLAUDE.md §14).
 */
final class AdresZajetyException extends RuntimeException
{
}
