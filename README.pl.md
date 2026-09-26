# CryptoPulse

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

Ceny odświeża cron, nie aplikacja:

```
0 * * * * php bin/console app:poll-prices
0 3 * * * php bin/console app:refresh-ath
```

## API

```
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
