# Kraken Futures Trade Manager

CLI-first Symfony 7/8 MVP voor het beheren van precies één handmatig geopende Kraken Futures trade tegelijk. Deze app automatiseert alleen execution en order-management nadat jij zelf de trade hebt gekozen.

## Scope

- 1 entry order
- direct daarna 1 stop loss + 2 take profits
- TP1 gevuld => oude stop loss cancelen => nieuwe stop loss op break-even voor de resterende size
- TP2 of de break-even stop handelt de rest af
- v1 ondersteunt maximaal 1 actieve trade tegelijk
- geen frontend
- standaard dry-run; live vereist expliciet `--execute`

## Packages

Belangrijkste Composer packages:

- `symfony/framework-bundle`
- `symfony/console`
- `symfony/http-client`
- `symfony/lock`
- `symfony/dotenv`
- `symfony/clock`
- `doctrine/orm`
- `doctrine/doctrine-bundle`
- `doctrine/doctrine-migrations-bundle`
- `monolog/monolog`
- `symfony/monolog-bundle`
- `phpunit/phpunit`

## Lokaal starten

1. Installeer PHP 8.2+ en Composer.
2. Run `composer install`.
3. Vul `.env` aan met je Kraken Futures API key en secret.
4. Run `php bin/console doctrine:migrations:migrate`.
5. Open eerst een trade in dry-run:

```bash
php bin/console trade:open --side=long --size=1 --entry-type=limit --entry-price=60000 --sl=59000 --tp1=61000 --tp2=62000
```

6. Gebruik live execution alleen bewust:

```bash
php bin/console trade:open --side=long --size=1 --entry-type=limit --entry-price=60000 --sl=59000 --tp1=61000 --tp2=62000 --execute
```

7. Daarna:

```bash
php bin/console trade:monitor
php bin/console trade:monitor --execute
php bin/console trade:status
php bin/console trade:close
php bin/console trade:close --execute
php bin/console trade:reset
php bin/console trade:cleanup
php bin/console trade:cleanup --execute
composer test
```

Als je al een lokale database had voordat de `dry_run` kolom is toegevoegd, run dan nogmaals:

```bash
php bin/console doctrine:migrations:migrate
```

## Kraken Futures aannames

- Authenticatie volgt Kraken Futures REST v3 signing met `postData + Nonce + endpointPath`, `SHA-256` en daarna `HMAC-SHA-512` over de SHA output met het base64-decoded secret. Bron: [Futures REST guide](https://docs.kraken.com/api/docs/guides/futures-rest/).
- Gebruikte endpointfamilie: `/sendorder`, `/editorder`, `/cancelorder`, `/batchorder`, `/openorders`, `/openpositions`, `/fills`. Bron: [Order management](https://docs.kraken.com/api/docs/futures-api/trading/order-management/).
- De docs tonen de endpointnamen duidelijk, maar niet alle request payloadvelden even volledig in de pagina-render. Daarom zijn order payloads geïsoleerd in [TradeManagerService](src/Service/TradeManagerService.php) en [KrakenFuturesClient](src/Kraken/KrakenFuturesClient.php), zodat je velden zoals `orderType`, `stopPrice`, `limitPrice`, `reduceOnly` en `triggerSignal` makkelijk kunt aanpassen.
- Voor safety vereist deze MVP altijd `--entry-price`, ook bij `market` entries. Dat houdt de SL/TP-validatie en break-even logica deterministisch.
- TP1 size gebruikt standaard 50% via `APP_TP1_RATIO`; TP2 krijgt de rest.

## Projectstructuur

- `src/Kraken/KrakenFuturesClient.php`: HTTP-calls, signing, retries, response-normalisatie
- `src/Service/TradeManagerService.php`: businesslogica
- `src/Entity/Trade.php`: trade state
- `src/Command/*`: CLI workflows
- `tests/Service/TradeManagerServiceTest.php`: kernscenario's

## Dry-run gedrag

- trades die zonder `--execute` zijn geopend worden als lokale dry-run trade opgeslagen
- `trade:monitor` doet voor zulke trades geen Kraken API-calls
- `trade:status` toont de opgeslagen trade gewoon zonder live exchange data op te halen
- `trade:close` sluit een dry-run trade lokaal af
- `trade:reset` reset alleen lokale state en stuurt nooit exchange-acties

## Close en Reset

- `trade:close --execute` is bedoeld voor live trades en sluit de resterende positie market reduce-only af, gevolgd door order cleanup
- `trade:close` zonder `--execute` werkt alleen veilig voor dry-run trades
- `trade:reset` is bedoeld voor vastgelopen lokale state; gebruik deze niet als vervanging voor echte live order cleanup
