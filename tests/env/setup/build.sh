#!/bin/sh

# Uses env vars: PHP_VERSION, UBUNTU_VERSION, WEBSERVER_TYPE

set -e

if [ ! -f /.dockerenv ]; then touch /.dockerenv; fi

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

./setup_php.sh "${PHP_VERSION}"

./setup_composer.sh

if [ "$WEBSERVER_TYPE" = roadrunner ] || [ "$WEBSERVER_TYPE" = all ]; then
    ./setup_roadrunner.sh;
fi

apt-get -y autoremove && apt-get -y autoclean && apt-get -y clean

echo "PHP_VERSION=${PHP_VERSION}" > /etc/build-info
echo "UBUNTU_VERSION=${UBUNTU_VERSION}" >> /etc/build-info
echo "WEBSERVER_TYPE=${WEBSERVER_TYPE}" >> /etc/build-info
