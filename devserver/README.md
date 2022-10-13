# WikimediaEvents devserver

The WikimediaEvents devserver offers a production-like environment for testing
your statsd-based instrumentation during development. With the devserver running,
you can read out the parsed StatsD metrics sent from the browser.

## Running

Add the following to `LocalSettings.php` to enable statsv.js. This suffices
to start sending the metrics from the browser, and seeing them in the Network
panel of your browser console.

```php
$wgWMEStatsdBaseUri = 'http://localhost:8127/beacon/statsv';
```

To read out the parsed metrics, start the devserver by running the following in
the extensions/WikimediaEvents directory (press `Ctrl^C` to stop the server).

```bash
composer start-statsv
```

### Integrating with MediaWiki-Docker

`docker-compose.override.yml`:

```yaml
version: "3.7"
services:
  # …

  statsv:
    build: ./extensions/WikimediaEvents/devserver/
    ports:
      - "8127:8127"
```

`LocalSettings.php`:

```php
$wgStatsdServer = 'statsd';
$wgWMEStatsdBaseUri = 'http://localhost:8127/beacon/statsv';
```

The recommended way of running MediaWiki-Docker uses a detached mode,
which runs services and their output in the background. To find the
debug logs run `docker compose logs statsv -f` from the MediaWiki
core directory. Or, use Docker Desktop to find the log output of
the "mediawiki / mediawiki-statsv" container.
