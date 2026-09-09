# CryptoPulse — agregator cen z kilku giełd i alerty

[English version](README.md)

API w Symfony, które odpytuje Binance, Kraken i Coinbase o aktualne ceny
kryptowalut, agreguje je, cache'uje wynik w Redisie i pozwala klientom
rejestrować alerty progowe dostarczane webhookiem.

## Stack

- PHP 8.4, Symfony 8.1 — routing atrybutowy, `#[MapRequestPayload]` +
  Validator, `symfony/rate-limiter`, `symfony/lock`.
- Wyłącznie Redis, przez `predis/predis`. Brak bazy relacyjnej.
- Binance, Kraken, Coinbase — publiczne endpointy, bez klucza API.
- PHPUnit: testy jednostkowe z `MockHttpClient`, integracyjne na realnym
  Redisie, funkcjonalne przez `WebTestCase`.
- `container/` — Apache + `php:8.4-apache`, plus lokalny serwis `redis`.

## Czego nie ma i dlaczego

- **Brak Doctrine / bazy danych.** Redis jest jedynym store'em — HASH per
  alert plus SET per para (`AlertRepository`). W efekcie brak historii cen —
  cache trzyma tylko ostatni poll per para.
- **Brak SecurityBundle.** Zarządzanie alertami (`GET`/`DELETE
  /api/alerts/{id}`) bez auth — kto ma ID alertu, ten go odczyta lub usunie.
- **Brak Messengera.** Polling cen to jedna synchroniczna komenda konsolowa
  (`app:poll-prices`), odpalana cronem, nie kolejka.
- **`predis/predis`, nie rozszerzenie PECL `redis`.** Brak natywnego
  rozszerzenia do kompilacji, więc obraz Dockera nie potrzebuje kroku
  `pecl install`, ani nic do przypadkowego popsucia przez `apt-get purge`.

## Zastrzeżenie

Cena zagregowana to mediana z odpowiedzi giełd, traktująca BTC**USDT**
z Binance i BTC**USD** z Coinbase jako ten sam instrument. USDT trzyma się
USD blisko, ale to nie jest ścisły peg 1:1.

## Endpointy

| Metoda | Ścieżka                | Opis |
|--------|-------------------------|------|
| GET    | `/api/prices`           | Ostatnia zagregowana cena dla każdej pary z danymi w cache. |
| GET    | `/api/prices/{pair}`    | Szczegóły jednej pary (`BTC_USD`, `ETH_USD`, `SOL_USD`) z rozbiciem per giełda. 404, jeśli nic jeszcze nie ma w cache. |
| POST   | `/api/prices/refresh`   | Wymusza realny re-poll giełd. Limit globalny raz na 60s (`429` + `Retry-After` w przeciwnym razie). |
| POST   | `/api/alerts`           | Rejestruje alert: `{pair, condition: "above"\|"below", threshold, webhookUrl}`. Zwraca 201 z alertem (id to UUIDv7). |
| GET    | `/api/alerts/{id}`      | Pobiera alert po ID. |
| DELETE | `/api/alerts/{id}`      | Usuwa alert. |

Wszystkie odpowiedzi `/api/*` to JSON, w tym błędy. Ruch przychodzący ma też
limit 60/min na IP.

## Uruchomienie

### Docker

```bash
cd container
docker compose up -d --build
docker compose exec web-server composer install
docker compose exec web-server php bin/console app:poll-prices
```

API pod `http://localhost:40057`.

### Natywny PHP

Wymaga PHP 8.4+, Composera, lokalnej instancji Redis.

```bash
composer install
php bin/console app:poll-prices
php -S 127.0.0.1:8000 -t public
```

### Odświeżanie cen

`app:poll-prices` nie odpala się samo — trzeba dodać wpis crona:

```
0 * * * * cd /path/to/project && php bin/console app:poll-prices >> var/log/poll.log 2>&1
```

(albo `docker compose exec -T web-server php bin/console app:poll-prices`).
Bezpieczne przy równoległym wywołaniu — blokada `symfony/lock` pomija
nakładający się przebieg.

## Testy

```bash
composer install
vendor/bin/phpunit
```

`AlertRepository` i testy funkcjonalne kontrolerów działają na realnym
Redisie (`REDIS_URL` w `.env.test`, osobny indeks DB niż dev).

## Struktura

```
src/
  Controller/        Endpointy HTTP
  Dto/                Ceny, agregaty, request/view alertu
  Enum/               Exchange, AlertCondition, Pair
  Exception/          Wyjątki domenowe, mapowane na status HTTP przez ApiExceptionListener
  Service/Exchange/   Klient per giełda + mapper pary na symbol
  Service/Price/      Agregacja, cache cen w Redisie, orkiestracja odświeżenia
  Service/Alert/      Store alertów (Predis), ewaluacja, dostarczanie webhooków
  Command/            app:poll-prices
  EventListener/      Rate limiting ruchu przychodzącego, JSON-owe błędy API
```

## Autor

Kamil Gałkowski — [GitHub: Tolemak](https://github.com/Tolemak)
