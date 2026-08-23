# Multi-stage build to ease usage of php extensions installed via PIE (aka. "the son of PECL") without bloating the final image

ARG UBUNTU_VERSION=resolute
FROM ubuntu:${UBUNTU_VERSION} AS pie_build

ARG APT_PACKAGE_PROXY=none
ARG PHP_VERSION=default

COPY --chmod=755 setup/build_pie_extensions.sh setup/setup_php.sh setup/setup_pie_extensions.sh /root/setup/
# @todo see https://github.com/protocolbuffers/protobuf/issues/21808 - the PIE name of the protobuf extension might change soon...
# NB: for Swoole, there are 2 extensions we can use:
# - https://packagist.org/packages/swoole/swoole
# - https://packagist.org/packages/openswoole/ext-openswoole
# As of 2026/8/20, we have to work around ext-openswoole issue #397: the only way to get it installed is to specify dev-master version
# @todo see https://github.com/openswoole/ext-openswoole#1-install-via-pie-php-installer-for-extensions : we might need
#       to pass in extra flags for the full installation of ext-openswoole
RUN DEBIAN_FRONTEND=noninteractive apt-get update --allow-releaseinfo-change && \
    DEBIAN_FRONTEND=noninteractive apt-get install -y unzip && \
    /root/setup/build_pie_extensions.sh 'kjdev/brotli kjdev/zstd extport/protobuf openswoole/ext-openswoole:dev-master swoole/swoole'

ARG UBUNTU_VERSION=resolute
FROM ubuntu:${UBUNTU_VERSION}

ARG APT_PACKAGE_PROXY=none
ARG PHP_VERSION=default
ARG UBUNTU_VERSION=resolute
ARG WEBSERVER_TYPE=all

COPY --from=pie_build --chmod=644 /usr/lib/php/pie/*.so /usr/lib/php/pie/

COPY --chmod=755 setup/*.sh /root/setup/
COPY config /root/config/

RUN /root/setup/build.sh

COPY --chmod=755 docker/entrypoint.sh /root/entrypoint.sh

# nginx: website, proxy, website https, proxy https
EXPOSE 80 8080 443 8443
# apache: website, proxy, website https, proxy https
EXPOSE 81 8081 444 8444
# frankenphp: website, proxy
EXPOSE 1082 8082
# roadrunner: proxy
EXPOSE 8083
# swoole: website, proxy
EXPOSE 1084 8084

# @todo can we avoid hardcoding this here? We can f.e. get it passed down as ARG...
WORKDIR /home/docker/workspace

ENTRYPOINT ["/root/entrypoint.sh"]
