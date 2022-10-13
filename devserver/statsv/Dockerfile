FROM php:8.1.11-cli-alpine

ENV STATSD_HOSTNAME="statsd"
ENV STATSD_PORT=8125

RUN docker-php-ext-install sockets

WORKDIR /opt/wikimedia/wikimedia_events

COPY statsv.php statsv.php

CMD [ "php", "-S", "0.0.0.0:8127", "statsv.php" ]

EXPOSE 8127
