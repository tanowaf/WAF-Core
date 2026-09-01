#!/bin/bash

USERNAME="${1:-docker}"
INSTALL_ON_START="${INSTALL_ON_START:-false}"
START_WEBSERVER="${START_WEBSERVER:-nginx}"

echo "[$(date)] Bootstrapping the Test container..."

# load values for UBUNTU_VERSION, PHP_VERSION
# @todo instead of relying on . /etc/build-info, determine those in the same way setup_php.sh does
if [ -f /etc/build-info ]; then
    . /etc/build-info
else
    echo "WARNING: file /etc/build-info not found. It is used to set env variables such as UBUNTU_VERSION, PHP_VERSION" >&2
fi
# NB: the following line does not account for 'default'
#PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
#UBUNTU_VERSION="$(fgrep DISTRIB_CODENAME /etc/lsb-release | sed 's/DISTRIB_CODENAME=//')"

if [ -z "${TESTS_ROOT_DIR}" ] || [ -z "${UBUNTU_VERSION}" ] || [ -z "${PHP_VERSION}" ]; then
    echo "ERROR: empty value not supported for env vars TESTS_ROOT_DIR, UBUNTU_VERSION, PHP_VERSION" >&2
    exit 1
fi

BOOTSTRAP_OK_FILE="${TESTS_ROOT_DIR}/tests/env/var/bootstrap_ok_${UBUNTU_VERSION}_${PHP_VERSION}_${WEBSERVER_TYPE}"

if [ -f "${BOOTSTRAP_OK_FILE}" ]; then
    rm "${BOOTSTRAP_OK_FILE}"
fi

clean_up() {
    # Perform program exit housekeeping

    echo "[$(date)] Stopping the Web server"
    if [ "$START_WEBSERVER" = apache ] || [ "$START_WEBSERVER" = apache2 ] || [ "$START_WEBSERVER" = all ]; then
        if [ -d /etc/apache2 ]; then
            service apache2 stop
        fi
    fi
    if [ "$START_WEBSERVER" = nginx ] || [ "$START_WEBSERVER" = all ]; then
        if [ -d /etc/nginx ]; then
            service nginx stop
        fi
    fi
    if [ "$START_WEBSERVER" = frankenphp ] || [ "$START_WEBSERVER" = all ]; then
        if [ -d /etc/frankenphp ]; then
            service frankenphp stop
        fi
    fi
    echo "[$(date)] Stopping FPM"
    service php-fpm stop

    if [ -f "${BOOTSTRAP_OK_FILE}" ]; then
        rm "${BOOTSTRAP_OK_FILE}"
    fi

    echo "[$(date)] Exiting"
    exit
}

# Fix UID & GID for user

echo "[$(date)] Fixing filesystem permissions..."

ORIG_UID="$(id -u "${USERNAME}")"
ORIG_GID="$(id -g "${USERNAME}")"
CONTAINER_USER_HOME="$(grep "^${USERNAME}:" /etc/passwd | cut -f6 -d:)"
CONTAINER_USER_UID="${CONTAINER_USER_UID:=$ORIG_UID}"
CONTAINER_USER_GID="${CONTAINER_USER_GID:=$ORIG_GID}"

if [ "$CONTAINER_USER_UID" != "$ORIG_UID" ] || [ "$CONTAINER_USER_GID" != "$ORIG_GID" ]; then
    groupmod -g "$CONTAINER_USER_GID" "${USERNAME}"
    usermod -u "$CONTAINER_USER_UID" -g "$CONTAINER_USER_GID" "${USERNAME}"
fi
if [ "$(stat -c '%u' "${CONTAINER_USER_HOME}")" != "${CONTAINER_USER_UID}" ] || [ "$(stat -c '%g' "${CONTAINER_USER_HOME}")" != "${CONTAINER_USER_GID}" ]; then
    chown "${CONTAINER_USER_UID}":"${CONTAINER_USER_GID}" "${CONTAINER_USER_HOME}"
    chown -R "${CONTAINER_USER_UID}":"${CONTAINER_USER_GID}" "${CONTAINER_USER_HOME}"/.*
    if [ -d /usr/local/php ]; then
        chown -R "${CONTAINER_USER_UID}":"${CONTAINER_USER_GID}" /usr/local/php
    fi
fi
# @todo do the same chmod for ${TESTS_ROOT_DIR}, if it's not within CONTAINER_USER_HOME
#       Also, the composer cache dir, while within the user home dir, is mounted (or might be) via docker and might have faulty ownership  or perms

# @todo the following snippet does not seem to be required on any vm - but we might want to run a chown/chmod on $TESTS_ROOT_DIR
#DIR="$(dirname "$TESTS_ROOT_DIR")"
#while "$DIR" != /; do
#    chmod o+rx "$DIR"
#    DIR="$(dirname "$DIR")"
#done

if [ -f /etc/apache2/envvars ]; then
    echo "[$(date)] Fixing Apache configuration..."

    sed -e "s|^export TESTS_ROOT_DIR=.*|export TESTS_ROOT_DIR=${TESTS_ROOT_DIR}|g" --in-place /etc/apache2/envvars
    sed -e "s|^export APACHE_RUN_USER=.*|export APACHE_RUN_USER=${USERNAME}|g" --in-place /etc/apache2/envvars
    sed -e "s|^export APACHE_RUN_GROUP=.*|export APACHE_RUN_GROUP=${USERNAME}|g" --in-place /etc/apache2/envvars
fi

PHPVER="$(php -r 'echo implode(".",array_slice(explode(".",PHP_VERSION),0,2));' 2>/dev/null)"

if [ -f /etc/nginx/sites-available/default ]; then
    echo "[$(date)] Fixing Nginx configuration..."

    PHP_FPM_SOCKET="unix:/run/php/php${PHPVER}-fpm.sock"
    sed -e "s?^ *set \\\$tests_root_dir .*?    set \$tests_root_dir ${TESTS_ROOT_DIR}/tests/public;?g" --in-place /etc/nginx/sites-available/default
    sed -e "s?^ *set \\\$php_fpm_socket .*?    set \$php_fpm_socket ${PHP_FPM_SOCKET};?g" --in-place /etc/nginx/sites-available/default
    sed -e "s?^ *user .*?user ${USERNAME};?g" --in-place /etc/nginx/nginx.conf
fi

if [ -f /etc/frankenphp/Caddyfile ]; then
    echo "[$(date)] Fixing FrankenPHP configuration..."

    # let frankenphp run using its own user and group, but make them share ids with the docker guy (yes, that's possible)
    groupmod -o -g "$CONTAINER_USER_GID" frankenphp
    usermod -o -u "$CONTAINER_USER_UID" -g "$CONTAINER_USER_GID" frankenphp
    chown frankenphp:frankenphp /run/frankenphp
    chown frankenphp:frankenphp /var/lib/frankenphp
    chown frankenphp /var/log/frankenphp

    sed -e "s|^ *root .*|    root ${TESTS_ROOT_DIR}/tests/public|g" --in-place /etc/frankenphp/Caddyfile
fi

if [ -f /etc/roadrunner/rr.yaml ]; then
    echo "[$(date)] Fixing RoadRunner configuration..."

    # let roadrunner run using its own user and group, but make them share ids with the docker guy
    groupmod -o -g "$CONTAINER_USER_GID" roadrunner
    usermod -o -u "$CONTAINER_USER_UID" -g "$CONTAINER_USER_GID" roadrunner
    chown roadrunner:roadrunner /run/roadrunner
    chown roadrunner:roadrunner /var/lib/roadrunner
    chown roadrunner /var/log/roadrunner

    sed -e "s|^ *command:.*|    command: php ${TESTS_ROOT_DIR}/tests/public/waf.php|g" --in-place /etc/roadrunner/rr.yaml
fi

if [ -f /etc/init.d/swoole ]; then
    echo "[$(date)] Fixing Swoole configuration..."

    # let swoole run using its own user and group, but make them share ids with the docker guy
    groupmod -o -g "$CONTAINER_USER_GID" swoole
    usermod -o -u "$CONTAINER_USER_UID" -g "$CONTAINER_USER_GID" swoole
    chown swoole:swoole /run/swoole
    chown swoole:swoole /var/lib/swoole
    chown swoole /var/log/swoole

    echo "WORKER_SCRIPT_DIR=${TESTS_ROOT_DIR}/tests/public" > /etc/default/swoole_server
    echo "WORKER_SCRIPT_DIR=${TESTS_ROOT_DIR}/tests/public" > /etc/default/swoole_waf
fi

echo "[$(date)] Fixing FPM configuration..."

if [ -f "/usr/local/php/${PHPVER}/etc/php-fpm.conf" ]; then
    # presumably a php installation from shivammathur/php5-ubuntu, which does not have separate files in a pool.d dir
    FPMCONF="/usr/local/php/${PHPVER}/etc/php-fpm.conf"
else
    FPMCONF="/etc/php/${PHPVER}/fpm/pool.d/www.conf"
fi
sed -e "s?^user =.*?user = ${USERNAME}?g" --in-place "${FPMCONF}"
sed -e "s?^group =.*?group = ${USERNAME}?g" --in-place "${FPMCONF}"
sed -e "s?^listen.owner =.*?listen.owner = ${USERNAME}?g" --in-place "${FPMCONF}"
sed -e "s?^listen.group =.*?listen.group = ${USERNAME}?g" --in-place "${FPMCONF}"

#  We make it optional to run composer at container start
# @todo should we always run `composer dump-autoload` when not running `composer install`? Also, what about composer update?
if [ "${INSTALL_ON_START}" = true ]; then
    /root/setup/setup_app.sh "${TESTS_ROOT_DIR}"
fi

trap clean_up TERM

# @todo exit/fail on failure to start nginx or php-fpm ?

echo "[$(date)] Starting FPM..."
service php-fpm start

echo "[$(date)] Starting the Web server(s(..."
if [ "$START_WEBSERVER" = apache ] || [ "$START_WEBSERVER" = apache2 ] || [ "$START_WEBSERVER" = all ]; then
    if [ -d /etc/apache2 ]; then
        service apache2 start
    else
        if [ "$START_WEBSERVER" = apache ] || [ "$START_WEBSERVER" = apache2 ]; then
            echo "Can not start apache: it was not installed in this container" >&2
            exit 1
        fi
    fi
fi
if [ "$START_WEBSERVER" = nginx ] || [ "$START_WEBSERVER" = all ]; then
    if [ -d /etc/nginx ]; then
        # @todo this should be moved to /etc/default/nginx so that it always runs on every service start and stop
        rm /run/nginx.*.sock 2>/dev/null || true
        service nginx start
    else
        if [ "$START_WEBSERVER" = nginx ]; then
            echo "Can not start nginx: it was not installed in this container" >&2
            exit 1
        fi
    fi
fi
if [ "$START_WEBSERVER" = frankenphp ] || [ "$START_WEBSERVER" = all ]; then
    if [ -d /etc/frankenphp ]; then
        service frankenphp start
    else
        if [ "$START_WEBSERVER" = frankenphp ]; then
            echo "Can not start frankenphp: it was not installed in this container" >&2
            exit 1
        fi
    fi
fi
if [ "$START_WEBSERVER" = roadrunner ] || [ "$START_WEBSERVER" = all ]; then
    if [ -d /etc/roadrunner ]; then
        service roadrunner start
    else
        if [ "$START_WEBSERVER" = roadrunner ]; then
            echo "Can not start roadrunner: it was not installed in this container" >&2
            exit 1
        fi
    fi
fi
if [ "$START_WEBSERVER" = swoole ] || [ "$START_WEBSERVER" = all ]; then
    if [ -f /etc/init.d/swoole ]; then
        service swoole_server start
        service swoole_waf start
    else
        if [ "$START_WEBSERVER" = swoole ]; then
            echo "Can not start swoole: it was not installed in this container" >&2
            exit 1
        fi
    fi
fi

echo "[$(date)] Bootstrap finished"

# Create the file which can be used by the container.sh script to check for end of bootstrap
if [ ! -d "${TESTS_ROOT_DIR}/tests/env/var" ]; then
    mkdir -p "${TESTS_ROOT_DIR}/tests/env/var"
    chown -R "${USERNAME}" "${TESTS_ROOT_DIR}/tests/env/var"
fi
# @todo save to bootstrap_ok an actual error code if any of the commands above failed
touch "${BOOTSTRAP_OK_FILE}" && chown "${USERNAME}" "${BOOTSTRAP_OK_FILE}"

tail -f /dev/null &
child=$!
wait "$child"
