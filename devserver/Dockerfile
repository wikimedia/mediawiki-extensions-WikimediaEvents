FROM php:8.1.11-cli-alpine

WORKDIR /opt/wikimedia/WikimediaEvents

COPY statsv.php statsv.php

CMD [ "php", "-S", "0.0.0.0:8127", "statsv.php" ]

EXPOSE 8127
