# CryptoPulse

Symfony API that polls Binance, Kraken and Coinbase for crypto prices, keeps the median per pair in Redis and sends webhook alerts when a price crosses a threshold. Redis is the only store, no SQL database. Frontend: [CryptoPulse_Frontend](https://github.com/Tolemak/CryptoPulse_Frontend).

[Polska wersja](README.pl.md)

## Running

```bash
cd container
docker compose up -d --build
docker compose exec web-server composer install
docker compose exec web-server php bin/console app:poll-prices
```

API on `http://localhost:40057`. Without Docker: PHP 8.4, Composer, local Redis, then `composer install` and `php -S 127.0.0.1:8000 -t public`.

Prices are refreshed by cron, not by the app:

```
0 * * * * php bin/console app:poll-prices
0 3 * * * php bin/console app:refresh-ath
```

## API

```
GET    /api/prices
GET    /api/prices/{pair}       BTC_USD, ETH_USD, SOL_USD
POST   /api/prices/refresh      once per 60 s
POST   /api/alerts              {pair, condition: above|below, threshold, webhookUrl}
GET    /api/alerts/{id}
DELETE /api/alerts/{id}
```

## Tests

```bash
vendor/bin/phpunit
```

Some tests need a running Redis (`REDIS_URL` in `.env.test`).
