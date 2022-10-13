# WikimediaEvents Development Server

The WikimediaEvents development server (devserver) offers a production-ish environment for testing
your statsd-based instrumentation. With the devserver running, you can see metrics sent from the
browser be processed by a statsd server.

## Running

Run `docker compose up` in this directory and add the following to `LocalSettings.php`:

```php
$wgWMEStatsdBaseUri = 'http://localhost:8127/beacon/statsv';
```

### Integrating with MediaWiki-Docker

`docker-compose.override.yml`:

```yaml
version: "3.7"
services:
  # …

  statsd:
    image: statsd/statsd:latest
    ports:
      - "8125:8125/udp"
    volumes:
      - ./extensions/WikimediaEvents/devserver/statsd/statsd.config.js:/usr/src/app/config.js

  statsv:
    build: ./extensions/WikimediaEvents/devserver/statsv/
    ports:
      - "8127:8127"
```

`LocalSettings.php`:

```php
$wgStatsdServer = 'statsd';
$wgWMEStatsdBaseUri = 'http://localhost:8127/beacon/statsv';
```
