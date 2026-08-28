# Multi-stage build to ease usage of php extensions installed via PIE (aka. "the son of PECL") without bloating the final image

ARG UBUNTU_VERSION=resolute
FROM ubuntu:${UBUNTU_VERSION} AS pie_build

ARG APT_PACKAGE_PROXY=none
ARG PHP_VERSION=default

COPY --chmod=755 setup/build_pie_extensions.sh.sh setup/setup_php.sh setup/setup_pie_extensions.sh /root/setup/
RUN /root/setup/build_pie_extensions.sh

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

# nginx: website, waf, website https, waf https
EXPOSE 80 8080 443 8443
# apache: website, waf, website https, waf https
EXPOSE 81 8081 444 8444
# frankenphp: website, waf
EXPOSE 1082 8082
# roadrunner: waf
EXPOSE 8083
# swoole: website, proxy
EXPOSE 1084 8084

# @todo can we avoid hardcoding this here? We can f.e. get it passed down as ARG...
WORKDIR /home/docker/workspace

ENTRYPOINT ["/root/entrypoint.sh"]
