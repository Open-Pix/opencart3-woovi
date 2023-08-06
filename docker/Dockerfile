FROM ubuntu:20.04

ENV TIMEZONE=UTC

RUN ln -snf /usr/share/zoneinfo/$TIMEZONE /etc/localtime && \
    echo $TIMEZONE > /etc/timezone

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y \
        gnupg curl ca-certificates wget netcat-traditional \
        zip unzip git software-properties-common \
    && curl -sS 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14aa40ec0831756756d7f66c4f4ea0aae5267a6c' \
        | gpg --dearmor \
        | tee /usr/share/keyrings/ppa_ondrej_php.gpg > /dev/null \
    && echo "deb [signed-by=/usr/share/keyrings/ppa_ondrej_php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu focal main" > /etc/apt/sources.list.d/ppa_ondrej_php.list

RUN apt-get update \
    && apt-get install -y php7.4-cli \
       php7.4-gd php7.4-zip php7.4-curl \
       php7.4-mysql php7.4-mbstring php7.4-xml \
       php7.4-xdebug

RUN apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

COPY php.ini-development /etc/php7.4/php.ini
COPY xdebug.ini /etc/php7.4/conf.d/xdebug.ini

RUN wget https://getcomposer.org/composer-stable.phar -O /usr/local/bin/composer \
    && chmod +x /usr/local/bin/composer

ARG EXTENSION_PATH
ARG OPENCART_PATH
ARG OPENCART_STORAGE_PATH
ARG OPENCART_VERSION
ARG DOCKER_USER
ARG APP_PORT
ARG USER_ID
ARG GROUP_ID

COPY entrypoint-dev.sh /usr/local/bin/docker-entrypoint-dev
RUN chmod +x /usr/local/bin/docker-entrypoint-dev

RUN groupadd -o -g ${GROUP_ID} $DOCKER_USER \
    && useradd -o -l -u ${USER_ID} -g $DOCKER_USER $DOCKER_USER \
    && install -d -m 0777 -o $DOCKER_USER -g $DOCKER_USER /home/$DOCKER_USER
USER $DOCKER_USER

VOLUME $OPENCART_PATH
VOLUME $OPENCART_STORAGE_PATH

WORKDIR $OPENCART_PATH

RUN curl -o opencart.zip -fL "https://github.com/opencart/opencart/releases/download/$OPENCART_VERSION/opencart-$OPENCART_VERSION.zip"

RUN unzip opencart.zip -d opencart \
    && cp -r opencart/upload/* . \
    && rm -rf opencart.zip opencart \
    && mv config-dist.php config.php \
    && mv admin/config-dist.php admin/config.php \
    && mkdir -p $OPENCART_STORAGE_PATH \
    && chown -R $DOCKER_USER:$DOCKER_USER $OPENCART_PATH $OPENCART_STORAGE_PATH \
    && chmod -R 777 . $OPENCART_STORAGE_PATH

WORKDIR $EXTENSION_PATH

EXPOSE $APP_PORT
ENTRYPOINT ["docker-entrypoint-dev"]