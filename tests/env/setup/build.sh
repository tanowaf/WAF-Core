#!/bin/sh

# Builds the container image
#
# Uses env vars: PHP_VERSION, UBUNTU_VERSION, WEBSERVER_TYPE, APT_PACKAGE_PROXY

set -e

if [ ! -f /.dockerenv ]; then touch /.dockerenv; fi

# Allow the user to specify a proxy for speeding up downloading of apt packages
if [ -n "${APT_PACKAGE_PROXY}" ] && [ "${APT_PACKAGE_PROXY}" != "none" ]; then
    printf "Acquire::http::Proxy \"${APT_PACKAGE_PROXY}\";\nAcquire::https::Proxy \"DIRECT\";\n" > /etc/apt/apt.conf.d/00proxy
fi

cd /root/setup

./install_packages.sh

./create_user.sh

if [ "$WEBSERVER_TYPE" = apache ] || [ "$WEBSERVER_TYPE" = all ]; then
    ./setup_apache.sh;
fi
if [ "$WEBSERVER_TYPE" = nginx ] || [ "$WEBSERVER_TYPE" = all ]; then
    ./setup_nginx.sh;
fi
if [ "$WEBSERVER_TYPE" = frankenphp ] || [ "$WEBSERVER_TYPE" = all ]; then
    if [ "${PHP_VERSION}" = default ]; then
        # there is no way to have fp using the same php version as found on the system, except for noble (8.3)
        # @todo can we find a better fallback logic?
        FP_VERSION=85
    else
        FP_VERSION="$(echo "${PHP_VERSION}" | tr -d '.')"
    fi
    ./setup_frankenphp.sh "${FP_VERSION}";
fi

# @todo move the list of php extensions to a cli option / env var
./setup_php.sh -p 'brotli zstd protobuf' -d 'amqp calendar dba enchant exif fileinfo ftp gd gettext gmp imagick intl ldap memcache memcached mongodb msgpack mysqli pdo pdo_dblib pdo_firebird pdo_mysql pdo_odbc pdo_pgsql pdo_sqlite pgsql pspell readline redis snmp soap sqlite3 tidy zmq' "${PHP_VERSION}"

./setup_composer.sh

if [ "$WEBSERVER_TYPE" = roadrunner ] || [ "$WEBSERVER_TYPE" = all ]; then
    ./setup_roadrunner.sh;
fi

if [ "$WEBSERVER_TYPE" = swoole ] || [ "$WEBSERVER_TYPE" = all ]; then
    ./setup_swoole.sh;
fi

# @todo... find what causes the need for this and fix it! (also, move this to setup_php.sh in the meantime?)
chmod 755 /usr/lib/php && chmod 755 "$(php -r "echo ini_get('extension_dir');")"

apt-get -y autoremove && apt-get -y autoclean && apt-get -y clean

echo "PHP_VERSION=${PHP_VERSION}" > /etc/build-info
echo "UBUNTU_VERSION=${UBUNTU_VERSION}" >> /etc/build-info
echo "WEBSERVER_TYPE=${WEBSERVER_TYPE}" >> /etc/build-info
