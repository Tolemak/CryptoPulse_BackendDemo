# CryptoPulse

[![CI](https://github.com/Tolemak/CryptoPulse_BackendDemo/actions/workflows/deploy.yml/badge.svg?branch=master)](https://github.com/Tolemak/CryptoPulse_BackendDemo/actions/workflows/deploy.yml)

API w Symfony, które pobiera ceny krypto z Binance, Kraken i Coinbase, trzyma medianę dla każdej pary w Redisie i wysyła alerty webhookiem, gdy cena przekroczy próg. Redis to jedyny magazyn, bez bazy SQL. Frontend: [CryptoPulse_Frontend](https://github.com/Tolemak/CryptoPulse_Frontend).

[English version](README.md)

## Uruchomienie

```bash
cd container
docker compose up -d --build
docker compose exec web-server composer install
docker compose exec web-server php bin/console app:poll-prices
```

API pod `http://localhost:40057`. Bez Dockera: PHP 8.4, Composer, lokalny Redis, potem `composer install` i `php -S 127.0.0.1:8000 -t public`.

Obraz produkcyjny: po zielonych testach na `master` CI buduje cały runtime z `container/Dockerfile.app` (`vendor` z Composera, Apache na porcie 8080 bez roota), wypycha go jako `ghcr.io/tolemak/cryptopulse-app:<sha commita>` (i `:latest`) i dołącza podpisane poświadczenie pochodzenia builda. Serwer sam pobiera obraz i weryfikuje poświadczenie; CI nigdy się z nim nie łączy.

Ceny odświeża cron, nie aplikacja:

```
0 * * * * php bin/console app:poll-prices
0 3 * * * php bin/console app:refresh-ath
```

## API

```
GET    /api/health              {"ok":true}, 503 {"ok":false} gdy Redis nie odpowiada
GET    /api/prices
GET    /api/prices/{pair}       BTC_USD, ETH_USD, SOL_USD
POST   /api/prices/refresh      raz na 60 s
POST   /api/alerts              {pair, condition: above|below, threshold, webhookUrl}
GET    /api/alerts/{id}
DELETE /api/alerts/{id}
```

## Testy

```bash
vendor/bin/phpunit
```

Część testów wymaga działającego Redisa (`REDIS_URL` w `.env.test`).

## Uwagi operacyjne

- Zaufane proxy: ruch idzie przez Cloudflare, reverse proxy na hoście i kontener. `trusted_proxies` w `config/packages/framework.yaml` zawiera zakresy prywatne i zakresy Cloudflare (https://www.cloudflare.com/ips/), więc cofanie `X-Forwarded-For` poza te przeskoki daje prawdziwy adres klienta. Forwardowany host i port pozostają niezaufane, bo nic nie buduje bezwzględnych URL-i.
- Limity zewnętrznych API (`config/packages/rate_limiter.yaml`): jeden token na wywołanie; pełne odpytanie wszystkich par to jedno wywołanie zbiorcze (Coinbase dodaje jedno na parę brakującą na liście spot). Limity publiczne: Binance 6000 wag/min (zbiorcze wywołanie waży 4), Kraken około 1 req/s, Coinbase 10k req/h, CoinGecko free około 5-15 req/min.
- `manual_refresh` jest globalny, nie per IP: ogranicza `POST /api/prices/refresh` dla całego API, żeby klienci nie mnożyli wywołań do zewnętrznych API.
- Adresy webhooków pochodzą od nieuwierzytelnionych wywołujących, więc klient HTTP webhooków nie może służyć jako relay: przekierowania są odrzucane (omijałyby sprawdzenie URL-a), a zakresy prywatne i zarezerwowane są blokowane po rozwiązaniu DNS.
- Wpisy cache mają `SCHEMA_VERSION`; podbij ją w `PriceCacheService` / `AthCacheService`, gdy zmieni się kształt `AggregatedPrice` / `AthInfo`. TTL jest nieco większy niż cykl crona (ceny 3900 s, ATH 90000 s), żeby spóźniony przebieg nie zostawiał luki.
- Zmiany stanu alertu używają skryptu compare-and-set w Redisie, więc alert przejmuje jeden ewaluator, a wygasły hash nie jest odtwarzany.
- Kraken używa starszych kodów aktywów dla dwóch par (BTC jako XBT, DOGE jako XDG), patrz `PairSymbolMapper`.
- `config/services.yaml` definiuje wartości zastępcze dla niewystawionych zmiennych env; nie ma commitowanego `.env`, a prawdziwe wdrożenia dostarczają zmienne środowiskowe.
- Obraz Dockera instaluje `libonig5` obok `libonig-dev`, bo `mbstring.so` linkuje się z nim w runtime, a apt usunąłby go jako osieroconą zależność przy usuwaniu `libonig-dev`.
- Obraz runtime nie zawiera Composera, testów ani zależności deweloperskich; opcache nie sprawdza znaczników czasu, a Apache prefork jest ograniczony do 3 procesów z `memory_limit` PHP 64M, dobranym do hosta z 1 GiB. `HEALTHCHECK` odpytuje `/robots.txt`, a nie `/api/health`, więc awaria Redisa nie oznacza kontenera jako niezdrowego.
- Plik compose służy do pracy lokalnej: Redis ma limit 24 MB (kontener 48 MB) z polityką `noeviction`, więc alerty, będące źródłem prawdy, nie są po cichu usuwane; przy zapełnieniu zapisy kończą się błędem.
- PHPStan ignoruje raport o nieużywanej `Kernel::getAllowedEnvs()`, bo Symfony wywołuje ją przez `KernelTrait`.
