# CryptoPulse — Multi-Exchange Price Aggregator & Alerts

[Wersja polska](README.pl.md)

A Symfony API that polls Binance, Kraken, and Coinbase for current crypto
prices, aggregates them, caches the result in Redis, and lets clients
register price-threshold alerts delivered via webhook.

## Stack

- PHP 8.4, Symfony 8.1 — attribute routing, `#[MapRequestPayload]` +
  Validator, `symfony/rate-limiter`, `symfony/lock`.
- Redis only, via `predis/predis`. No relational database.
- Binance, Kraken, Coinbase — public endpoints, no API key needed.
- PHPUnit: unit tests with `MockHttpClient`, integration tests against a real
  Redis instance, functional tests via `WebTestCase`.
- `container/` — Apache + `php:8.4-apache`, plus a local `redis` service.

## What's not installed, and why

- **No Doctrine / no database.** Redis is the store — a HASH per alert plus
  a SET per pair (see `AlertRepository`). No historical price data as a
  result; the cache only holds the latest poll per pair.
- **No SecurityBundle.** Alert management (`GET`/`DELETE /api/alerts/{id}`)
  has no auth — whoever holds an alert's id can read or delete it.
- **No Messenger.** Price polling is one synchronous console command
  (`app:poll-prices`), cron-triggered, not a queue.
- **`predis/predis`, not the `redis` PECL extension.** No native extension
  to compile, so the Docker image needs no `pecl install` step and nothing
  for an `apt-get purge` cleanup to accidentally break.

## Caveat

The aggregate price is the median of whatever exchanges answered, treating
Binance's BTC**USDT** and Coinbase's BTC**USD** as the same instrument. USDT
tracks USD closely but isn't a rigorous 1:1 peg.

## Endpoints

| Method | Path                   | Description |
|--------|------------------------|--------------|
| GET    | `/api/prices`          | Latest aggregated price for every pair with cached data. |
| GET    | `/api/prices/{pair}`   | Detail for one pair (`BTC_USD`, `ETH_USD`, `SOL_USD`), with per-exchange breakdown. 404 if nothing cached yet. |
| POST   | `/api/prices/refresh`  | Forces a real re-poll of the exchanges. Rate-limited globally to once per 60s (`429` + `Retry-After` otherwise). |
| POST   | `/api/alerts`          | Register an alert: `{pair, condition: "above"\|"below", threshold, webhookUrl}`. Returns 201 with the alert (id is a UUIDv7). |
| GET    | `/api/alerts/{id}`     | Fetch one alert by id. |
| DELETE | `/api/alerts/{id}`     | Delete an alert. |

All `/api/*` responses are JSON, including errors. Inbound requests are also
rate-limited to 60/minute per IP.

## Running it

### Docker

```bash
cd container
docker compose up -d --build
docker compose exec web-server composer install
docker compose exec web-server php bin/console app:poll-prices
```

API at `http://localhost:40057`.

### Native PHP

Requires PHP 8.4+, Composer, a local Redis instance.

```bash
composer install
php bin/console app:poll-prices
php -S 127.0.0.1:8000 -t public
```

### Keeping prices fresh

`app:poll-prices` isn't run automatically — add a cron entry:

```
0 * * * * cd /path/to/project && php bin/console app:poll-prices >> var/log/poll.log 2>&1
```

(or `docker compose exec -T web-server php bin/console app:poll-prices`).
Safe to invoke concurrently — a `symfony/lock` guard skips an overlapping
run.

## Tests

```bash
composer install
vendor/bin/phpunit
```

`AlertRepository` and the controller functional tests run against a real
Redis instance (`REDIS_URL` in `.env.test`, separate DB index from dev).

## Layout

```
src/
  Controller/        HTTP endpoints
  Dto/                Price quotes, aggregates, alert request/view
  Enum/               Exchange, AlertCondition, Pair
  Exception/          Domain exceptions, mapped to HTTP status by ApiExceptionListener
  Service/Exchange/   One client per exchange + the pair→symbol mapper
  Service/Price/      Aggregation, Redis price cache, refresh orchestration
  Service/Alert/      Alert storage (Predis), evaluation, webhook delivery
  Command/            app:poll-prices
  EventListener/      Inbound rate limiting, JSON API error responses
```

## Author

Kamil Gałkowski — [GitHub: Tolemak](https://github.com/Tolemak)
