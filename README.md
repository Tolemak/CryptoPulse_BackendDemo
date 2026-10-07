# CryptoPulse

[![CI](https://github.com/Tolemak/CryptoPulse_BackendDemo/actions/workflows/deploy.yml/badge.svg?branch=master)](https://github.com/Tolemak/CryptoPulse_BackendDemo/actions/workflows/deploy.yml)

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
GET    /api/health              {"ok":true}, 503 {"ok":false} if Redis is down
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

## Operational notes

- Trusted proxies: traffic goes Cloudflare, then the host reverse proxy, then the container. `trusted_proxies` in `config/packages/framework.yaml` lists the private ranges plus the Cloudflare ranges (https://www.cloudflare.com/ips/), so walking `X-Forwarded-For` back past these hops yields the real client IP. Forwarded host and port stay untrusted because nothing builds absolute URLs.
- Upstream rate limits (`config/packages/rate_limiter.yaml`): one token per upstream call; a full poll of every pair is a single batched call (Coinbase adds one per pair missing from its spot list). Public limits: Binance 6000 weight/min (a batch weighs 4), Kraken about 1 req/s, Coinbase 10k req/h, CoinGecko free tier about 5-15 req/min.
- `manual_refresh` is global, not per IP: it caps `POST /api/prices/refresh` for the whole API so clients cannot multiply upstream calls.
- Webhook URLs come from unauthenticated callers, so the webhook HTTP client must not act as a relay: redirects are refused (they would sidestep the URL check) and private or reserved ranges are blocked after DNS resolution.
- Cache entries carry a `SCHEMA_VERSION`; bump it in `PriceCacheService` / `AthCacheService` whenever `AggregatedPrice` / `AthInfo` changes shape. TTLs sit slightly above the cron cadence (prices 3900 s, ATH 90000 s) so a late run leaves no gap.
- Alert state changes use a Redis compare-and-set script, so one evaluator claims an alert and an expired hash is never recreated.
- Kraken uses legacy asset codes for two pairs (BTC as XBT, DOGE as XDG), see `PairSymbolMapper`.
- `config/services.yaml` defines fallback values for env vars that are not set; no `.env` is committed and real deployments provide actual environment variables.
- The Docker image installs `libonig5` next to `libonig-dev` because `mbstring.so` links against it at runtime and apt would otherwise remove it as an orphan when `libonig-dev` is purged.
- PHPStan ignores the unused `Kernel::getAllowedEnvs()` report because Symfony calls it through `KernelTrait`.
