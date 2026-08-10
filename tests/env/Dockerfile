# Multi-stage build to ease usage of php extensions installed via PIE (aka. "the son of PECL") without bloating the final image

ARG UBUNTU_VERSION=resolute
FROM ubuntu:${UBUNTU_VERSION} AS pie_build

ARG APT_PACKAGE_PROXY=none
ARG PHP_VERSION=default

COPY --chmod=755 setup/build_pie_extensions.sh setup/setup_php.sh /root/setup/
# @todo allow using a proxy for apt packages, same as we do in install_packages.sh
# @todo see https://github.com/protocolbuffers/protobuf/issues/21808 - the PIE name of the protobuf extension might change soon...
RUN DEBIAN_FRONTEND=noninteractive apt-get update --allow-releaseinfo-change && \
    DEBIAN_FRONTEND=noninteractive apt-get install unzip && \
    /root/setup/build_pie_extensions.sh 'kjdev/brotli kjdev/zstd extport/protobuf'

ARG UBUNTU_VERSION=resolute
FROM ubuntu:${UBUNTU_VERSION}

ARG APT_PACKAGE_PROXY=none
ARG PHP_VERSION=default
ARG UBUNTU_VERSION=resolute
ARG WEBSERVER_TYPE=all

# @todo are we sure that all php versions we support use `/usr/lib/php/20250925/` for .so files? If not, we need an ARG
COPY --from=pie_build --chmod=644 /usr/lib/php/20250925/brotli.so /usr/lib/php/20250925/zstd.so /usr/lib/php/20250925/protobuf.so /usr/lib/php/pie/
##COPY --from=frankenphp_build --chmod=644 /usr/local/lib/php/extensions/no-debug-zts-20250925/brotli.so /usr/local/lib/php/extensions/no-debug-zts-20250925/zstd.so /usr/lib/php/frankenphp/

COPY --chmod=755 setup/*.sh /root/setup/
COPY config /root/config/

RUN /root/setup/build.sh

COPY --chmod=755 docker/entrypoint.sh /root/entrypoint.sh

# @todo find what causes the need for thi and fix it!
RUN chmod 755 /usr/lib/php && chmod 755 /usr/lib/php/20250925

EXPOSE 80 81 1082 8080 8081 8082

# @todo can we avoid hardcoding this here? We can f.e. get it passed down as ARG...
WORKDIR /home/docker/workspace

ENTRYPOINT ["/root/entrypoint.sh"]
