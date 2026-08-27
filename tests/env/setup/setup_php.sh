#!/bin/sh

# Has to be run as root

# @todo make it optional to disable xdebug ?
# @todo allow to force usage of ondrej repos regardless of php version in use

set -e

PHP_EXTENSIONS="${PHP_EXTENSIONS:-cli curl dom fpm mbstring xdebug}"
PIE_EXTENSIONS=''
DISABLE_EXTENSIONS=''
INSTALL_FPM=false
INSTALL_XDEBUG=false

while getopts ":d:e:p:" opt
do
    case $opt in
        d)
            DISABLE_EXTENSIONS="$OPTARG"
            ;;
        e)
            PHP_EXTENSIONS="$OPTARG"
            ;;
        p)
            PIE_EXTENSIONS="$OPTARG"
            ;;
        \?)
          echo "Invalid option: -$OPTARG" >&2
          exit 1
          ;;
    esac
done
shift $((OPTIND-1))

PHP_VERSION="$1"

if [ -z "$PHP_VERSION" ]; then
    echo "PHP version has to be specified as 1st argument" >&2
    exit 1
fi

case " $PHP_EXTENSIONS " in
    *" fpm "*) INSTALL_FPM=true ;;
    *) . ;;
esac
case " $PHP_EXTENSIONS " in
    *" xdebug "*) INSTALL_XDEBUG=true ;;
    *) . ;;
esac

install_native() {
    echo "Using native PHP packages..."

    if [ "${DEBIAN_VERSION}" = jessie ] || [ "${DEBIAN_VERSION}" = precise ] || [ "${DEBIAN_VERSION}" = trusty ]; then
        PHPSUFFIX=5
    else
        PHPSUFFIX=
    fi
    # @todo check for mbstring presence in php5 (jessie) packages
    PHP_PACKAGES="php${PHPSUFFIX}"
    for EXT in $PHP_EXTENSIONS; do
        PHP_PACKAGES="$PHP_PACKAGES php${PHPSUFFIX}-${EXT}"
    done

    echo "Php packages to install: ${PHP_PACKAGES}..."
    apt-get install -y ${PHP_PACKAGES}
}

install_ondrej() {
    echo "Using PHP packages from ondrej/php..."

    # if ubuntu is version is 26 or greater, the installation instructions are different. See: https://codeberg.org/oerdnj/deb.sury.org/issues/91
    if [ "${DEBIAN_VERSION}" = 'resolute' ]; then
        apt-get install -y lsb-release ca-certificates
        # assumes curl is already installed
        curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
        dpkg -i /tmp/debsuryorg-archive-keyring.deb
        echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list
        apt-get update --allow-releaseinfo-change
        rm /tmp/debsuryorg-archive-keyring.deb
    else
        apt-get install -y language-pack-en-base software-properties-common
        LC_ALL=en_US.UTF-8 add-apt-repository ppa:ondrej/php
        apt-get update --allow-releaseinfo-change
    fi

    PHP_PACKAGES="php${PHP_VERSION}"
    for EXT in $PHP_EXTENSIONS; do
        PHP_PACKAGES="$PHP_PACKAGES php${PHP_VERSION}-${EXT}"
    done

    echo "Php packages to install: ${PHP_PACKAGES}..."
    apt-get install -y ${PHP_PACKAGES}

    update-alternatives --set php "/usr/bin/php${PHP_VERSION}"
}

# @todo... allow setting up a php.ini file for cli, now that we run cli-based daemons such as swoole

configure_php_ini() {
    echo "Configuring php.ini..."

    # note: these settings are not required for cli config
    if [ -f "$SCRIPT_DIR/../config/php.append.ini" ]; then
        cat "$SCRIPT_DIR/../config/php.append.ini" >> "$1"
    fi

    if [ "$INSTALL_XDEBUG" = true ]; then
        # we disable xdebug for speed for both cli and web mode
        if which phpdismod >/dev/null 2>/dev/null; then
            phpdismod xdebug
        elif [ -f "/etc/php/$PHP_VERSION/mods-available/xdebug.ini" ]; then
            mv "/etc/php/$PHP_VERSION/mods-available/xdebug.ini" "/etc/php/$PHP_VERSION/mods-available/xdebug.ini.bak"
        elif [ -f "/usr/local/php/$PHP_VERSION/etc/conf.d/20-xdebug.ini" ]; then
            mv "/usr/local/php/$PHP_VERSION/etc/conf.d/20-xdebug.ini" "/usr/local/php/$PHP_VERSION/etc/conf.d/20-xdebug.ini.bak"
        else
            echo "Could not disable loading of xdebug - xdebug.ini file not found" >&2
        fi
    fi
}

configure_php_fpm() {
    echo "Configuring PHP-FPM..."

    service "php${PHPVER}-fpm" stop || true

    if [ -d "/etc/php/${PHPVER}/fpm" ]; then
        configure_php_ini "/etc/php/${PHPVER}/fpm/php.ini"
    elif [ -f "/usr/local/php/${PHPVER}/etc/php.ini" ]; then
        configure_php_ini "/usr/local/php/${PHPVER}/etc/php.ini"
    fi

    # @todo is the default pool always named www.conf?
    if [ -f "/etc/php/${PHPVER}/fpm/pool.d/www.conf" ]; then
        #configure_php_fpm "/etc/php/${PHPVER}/fpm/pool.d/www.conf"
        sed -e "s|^pm.max_children .*|pm.max_children = 30|g" --in-place "/etc/php/${PHPVER}/fpm/pool.d/www.conf"
    fi

    # use a nice name for the php-fpm service, so that it does not depend on php version running. Try to make that work
    # both for docker and VMs
    if [ -f "/etc/init.d/php${PHPVER}-fpm" ]; then
        ln -s "/etc/init.d/php${PHPVER}-fpm" /etc/init.d/php-fpm
    fi
    if [ -f "/lib/systemd/system/php${PHPVER}-fpm.service" ]; then
        ln -s "/lib/systemd/system/php${PHPVER}-fpm.service" /lib/systemd/system/php-fpm.service
        if [ ! -f /.dockerenv ]; then
            systemctl daemon-reload
        fi
    fi

    service php-fpm start

    if [ -n "$(dpkg --list | grep apache)" ]; then
        configure_apache
    fi
}

# reconfigure apache (if installed). Sadly, php will switch on mod-php and mpm_prefork at install time...
configure_apache() {
    echo "Reconfiguring Apache..."

    # @todo... allow having both mod-php and fpm running at the same time, on different vhosts...
    if [ -n "$(ls /etc/apache2/mods-enabled/php* 2>/dev/null)" ]; then
        rm /etc/apache2/mods-enabled/php*
    fi
    a2dismod mpm_prefork
    a2enmod mpm_event
    a2enconf "php${PHPVER}-fpm"

    #service apache2 restart
}

setup_pie_exts() {
    echo "Setting up php extensions built with PIE..."

    # @todo fix: this will fail if /usr/lib/php/pie exists but is empty
    for EXT in /usr/lib/php/pie/*.so; do
        FILENAME="$(basename "$EXT")"
        EXTNAME="$(echo "$FILENAME" | sed 's/\.so//')"
        EXTDIR=$(php -r 'echo ini_get("extension_dir");')
        mv "$EXT" "$EXTDIR"
        echo "extension=$FILENAME" > "/etc/php/$PHPVER/mods-available/${EXTNAME}.ini"
        # @todo support enabling extensions without having phpenmod
        case " $PIE_EXTENSIONS " in
            *" ${EXTNAME} "*)
                phpenmod "$EXTNAME"
                ;;
            *) . ;;
        esac
    done
}

disable_exts() {
    echo "Disabling php extensions not required: $DISABLE_EXTENSIONS"

    for EXT in $DISABLE_EXTENSIONS; do
        phpdismod "$EXT" || true
    done
}

echo "Installing PHP version '${PHP_VERSION}'..."

SCRIPT_DIR="$(dirname -- "$(readlink -f "$0")")"

export DEBIAN_FRONTEND=noninteractive

# use native packages if requested for a specific version and that is the same as available in the os repos

# `lsb-release` is not necessarily onboard. We parse /etc/os-release instead
DEBIAN_VERSION=$(grep 'VERSION_CODENAME=' /etc/os-release | sed 's/VERSION_CODENAME=//')
if [ -z "${DEBIAN_VERSION}" ]; then
    # Example strings:
    # VERSION="14.04.6 LTS, Trusty Tahr"
    # VERSION="8 (jessie)"
    DEBIAN_VERSION=$(grep 'VERSION=' /etc/os-release | grep 'VERSION=' | sed 's/VERSION=//' | sed 's/"[0-9.]\+ *(\?//' | sed 's/)\?"//' | tr '[:upper:]' '[:lower:]' | sed 's/lts, *//' | sed 's/ \+tahr//')
fi

# Eg. 24.04
DEBIAN_VERSION_NR=$(grep 'VERSION_ID=' /etc/os-release | sed 's/VERSION_ID=//' | tr -d '"')

DEFAULT_PHP_VERSION=
if [ "${DEBIAN_VERSION}" = 'precise' ]; then
    # aka. ubuntu 12.04
    DEFAULT_PHP_VERSION=5.3
elif [ "${DEBIAN_VERSION}" = 'trusty' ]; then
    # aka. ubuntu 14.04
    DEFAULT_PHP_VERSION=5.5
elif [ "${DEBIAN_VERSION}" = 'xenial' ]; then
    # aka. ubuntu 16.04
    DEFAULT_PHP_VERSION=7.0
elif [ "${DEBIAN_VERSION}" = 'bionic' ]; then
    # aka. ubuntu 18.04
    DEFAULT_PHP_VERSION=7.2
elif [ "${DEBIAN_VERSION}" = 'focal' ]; then
    # aka. ubuntu 20.04
    DEFAULT_PHP_VERSION=7.4
elif [ "${DEBIAN_VERSION}" = 'jammy' ]; then
    # aka. ubuntu 22.04
    DEFAULT_PHP_VERSION=8.1
elif [ "${DEBIAN_VERSION}" = 'noble' ]; then
    # aka. ubuntu 24.04
    DEFAULT_PHP_VERSION=8.3
elif [ "${DEBIAN_VERSION}" = 'resolute' ]; then
    # aka. ubuntu 26.04
    DEFAULT_PHP_VERSION=8.5
fi

if [ "${PHP_VERSION}" = default ] || [ "${PHP_VERSION}" = "${DEFAULT_PHP_VERSION}" ]; then
    install_native
else
    # on GHA runners ubuntu version, php 7.4 and 8.0 seem to be preinstalled. Remove them if found
    for PHP_CURRENT in $(dpkg -l | grep -E 'php.+-common' | awk '{print $2}'); do
        if [ "${PHP_CURRENT}" != "php${PHP_VERSION}-common" ]; then
            apt-get purge -y "${PHP_CURRENT}"
        fi
    done

    # @todo test usage of ondrej packages for php 8.5
    #if [ "${PHP_VERSION}" = 5.3 ] || [ "${PHP_VERSION}" = 5.4 ] || [ "${PHP_VERSION}" = 5.5 ] || \
    if [ "${DEBIAN_VERSION}" = focal ] || [ "${DEBIAN_VERSION}" = bionic ] || [ "${DEBIAN_VERSION}" = xenial ] || [ "${DEBIAN_VERSION}" = trusty ]; then
        # @todo... bring this back and test that it works
        #install_shivammatur
        echo "Setting up PHP ${PHP_VERSION} on Ubuntu version ${DEBIAN_VERSION} is not supported atm" >&2
        exit 1
    else
        install_ondrej
    fi
fi

PHPVER=$(php -r 'echo implode(".",array_slice(explode(".",PHP_VERSION),0,2));' 2>/dev/null)

# we have to set up pie extensions, as we use a 2 stage build and their build process leaves them in a different dir
if [ -d /usr/lib/php/pie/ ]; then
    setup_pie_exts
else
    if [ -n "$PIE_EXTENSIONS" ]; then
        echo "There were no extensions built with PIE, can not enable $PIE_EXTENSIONS" >&2
    fi
fi

if [ -n "$DISABLE_EXTENSIONS" ]; then
    disable_exts
fi

if [ "$INSTALL_FPM" = true ]; then
    configure_php_fpm
fi

php -v
echo
echo "Done installing PHP"
