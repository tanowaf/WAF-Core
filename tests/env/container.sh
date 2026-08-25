#!/bin/sh

# @todo support getting the various settings as cli options as well as / instead of via env vars? (using getopts)

set -e

ACTION="${1}"

# Valid values: 'default', 5.4 .. 5.6, 7.0 .. 7.4, 8.0 .. 8.5 (but the php app requires php 8.2 and up!)
export PHP_VERSION=${PHP_VERSION:-default}
# Valid values: precise (12), trusty (14), xenial (16), bionic (18), focal (20), jammy (22), noble (24), resolute (26)
# For end of support dates, see: https://wiki.ubuntu.com/Releases
export UBUNTU_VERSION=${UBUNTU_VERSION:-resolute}
export APT_PACKAGE_PROXY=${APT_PACKAGE_PROXY:-none}
export WEBSERVER_TYPE=${WEBSERVER_TYPE:-all}
export VERBOSITY=${VERBOSITY:-1}

# Webserver ports exposed to the host. Set to 'no' for no port mapping
HOST_HTTPPORT_APACHE="${HOST_HTTPPORT_APACHE:-81}"
HOST_HTTPPORT_FRANKENPHP="${HOST_HTTPPORT_FRANKENPHP:-1082}"
HOST_HTTPPORT_NGINX="${HOST_HTTPPORT_NGINX:-80}"
HOST_HTTPPORT_ROADRUNNER="${HOST_HTTPPORT_ROADRUNNER:-1083}"
HOST_HTTPPORT_SWOOLE="${HOST_HTTPPORT_SWOOLE:-1084}"
#HOST_HTTPSPORT="${HOST_HTTPSPORT:-443}"
HOST_PROXYPORT_APACHE="${HOST_PROXYPORT_APACHE:-8081}"
HOST_PROXYPORT_FRANKENPHP="${HOST_PROXYPORT_FRANKENPHP:-8082}"
HOST_PROXYPORT_NGINX="${HOST_PROXYPORT_NGINX:-8080}"
HOST_PROXYPORT_ROADRUNNER="${HOST_PROXYPORT_ROADRUNNER:-8083}"
HOST_PROXYPORT_SWOOLE="${HOST_PROXYPORT_SWOOLE:-8084}"
#HOST_PROXYPORT_HTTPS="${HOST_PROXYPORT_HTTPS:-8443}"

COMPOSER_INSTALL_ON_START="${COMPOSER_INSTALL_ON_START:-false}"
START_WEBSERVER="${START_WEBSERVER:-all}"
CONTAINER_NAME_PREFIX="${CONTAINER_NAME_PREFIX:-tw_wafcore_}"
CONTAINER_IMAGE_PREFIX="${CONTAINER_IMAGE_PREFIX:-tw-wafcore}"
CONTAINER_USER=docker
CONTAINER_WORKSPACE_DIR="/home/${CONTAINER_USER}/workspace"
DOCKER_CMD="${DOCKER_CMD:-docker}"

IMAGE_NAME="${CONTAINER_IMAGE_PREFIX}:${UBUNTU_VERSION}-${PHP_VERSION}-${WEBSERVER_TYPE}"
CONTAINER_NAME="${CONTAINER_NAME_PREFIX}${UBUNTU_VERSION}_${PHP_VERSION}_${WEBSERVER_TYPE}"

ROOT_DIR="$(dirname -- "$(dirname -- "$(dirname -- "$(readlink -f "$0")")")")"

cd "$(dirname -- "$(readlink -f "$0")")"

help() {
    printf "Usage: container.sh [OPTIONS] ACTION [OPTARGS]

Manages the Test Environment (a Docker Container)

Main actions:
    build             build or rebuild the container image with the test env
    cleanup           remove the container and its image
    enter             start a shell session in the running container
    exec [\$command]   run a single command in the running container
    runtests [\$suite] execute the test suite using the test container (or a single test scenario eg. tests/02ValueTest.php);
                      build and start the container if required
    runcoverage       execute the test suite and generate a code coverage report (in build/coverage);
                      build and start the container if required
    start             start the container; build it if required
    stop              stop the container
    top

Actions for troubleshooting the container:
    inspect, logs, port, ps, stats, top

Options:
    -h                print help

Environment variables:
  used by the 'build' action
    APT_PACKAGE_PROXY default value: 'none'. Use eg. 'http://127.0.0.1:3142' and run apt-cacher-rs as sidecar container
    PHP_VERSION       default value: 'default'. Use 'default' for the stock php version from the Ubuntu version in use. Other possible values: 8.2 .. 8.5
    UBUNTU_VERSION    default value: 'resolute'. Other possible values: xenial, bionic, focal, jammy, noble
    WEBSERVER_TYPE    default value: 'all'. Other possible values: apache, nginx, frankenphp
  used by the 'start' action
    COMPOSER_INSTALL_ON_START default value: false. Set to true to run a 'composer install' on container start
    START_WEBSERVER        default value: 'all'. Can be set to apache, nginx, frankenphp
    HOST_HTTPPORT_\$SRV     default value: 80/81/1082. Set to 'no' to disable exposing the container port to the host
    HOST_PROXYPORT_\$SRV    default value: 8080/8081/8082. Set to 'no' to disable exposing the container port to the host
  used by both build and start:
    CONTAINER_IMAGE_PREFIX default value: 'tw-wafcore'. Change if you build/run many containers in parallel
    CONTAINER_NAME_PREFIX  default value: 'tw_wafcore_'. Change if you build/run many containers in parallel
  used by the 'runtests' and 'runcoverage' actions:
    TEST_WEBSERVER         default value: nginx. Can be set to apache, frankenphp, swoole
    TEST_WAF               when null, the same value as TEST_WEBSERVER is used
"
}

check_requirements() {
    type "${DOCKER_CMD}" >/dev/null 2>&1
    # shellcheck disable=SC2181
    if [ $? -ne 0 ]; then
        printf "\n\e[31mPlease install docker & add it to \$PATH\e[0m\n\n" >&2
        exit 1
    fi

    # If user is not root and not in docker group, we will need sudo for other docker commands than `compose version`
    # podman does not require this
    if ! echo "${DOCKER_CMD}" | grep -q podman; then
        if ! id -Gn | grep -q 'docker'; then
            if [ "$(id -u)" != 0 ]; then
                 case "$DOCKER_CMD" in
                   *sudo*) ;;
                   *) DOCKER_CMD="sudo --preserve-env=APT_PACKAGE_PROXY,PHP_VERSION,UBUNTU_VERSION,VERBOSITY,WEBSERVER_TYPE ${DOCKER_CMD}" ;;
                 esac

            fi
        fi
    fi
}


build() {
    if ${DOCKER_CMD} build --build-arg APT_PACKAGE_PROXY --build-arg PHP_VERSION --build-arg UBUNTU_VERSION --build-arg WEBSERVER_TYPE -t "${IMAGE_NAME}" .; then
        if [ "$1" = '-r' ]; then
            # stop and remove existing containers built from a previous version of this image
            if ${DOCKER_CMD} inspect "${CONTAINER_NAME}" >/dev/null 2>/dev/null; then
                stop -q
                ${DOCKER_CMD} rm "${CONTAINER_NAME}"
            fi
        fi
    fi
}

start() {
    if [ "$(${DOCKER_CMD} inspect --format '{{.State.Status}}' "${CONTAINER_NAME}" 2>/dev/null)" = running ]; then
        # @todo we should check that the env vars have not changed since cont. start, and give a warning if so.
        #       Doable using `docker container cp` to retrieve the /etc/build-info file...
        echo "${CONTAINER_NAME} already started..."
    else
        if ${DOCKER_CMD} inspect "${CONTAINER_NAME}" >/dev/null 2>/dev/null; then
            echo "starting existing container ${CONTAINER_NAME}..."
            # @todo we should check that the env vars have not changed since cont. creation, and give a warning if so.
            #       That should be doable using `docker container inspect`.
            #       We now put UBUNTU_VERSION and PHP_VERSION in the cont. name, but there are other env vars used at
            #       container `run` time, such as f.e. CONTAINER_USER.
            #       But also, what if the container (and image) were built by a different version of the ci codebase?
            #       That should not be a case happening frequently. But if we really wanted to protect against it, we
            #       would have to create a checksum of the contents of tests/env and it store somehow as container metadata...
            if ${DOCKER_CMD} start "${CONTAINER_NAME}"; then
                wait_for_bootstrap
            fi
        else
            build

            PORTMAPPING=''
            # @todo improve error message and abort in case any port is not an integer or negative
            # @todo depending on the value of $START_WEBSERVER we could map fewer ports to just 80+8080
            if [ "$HOST_HTTPPORT_APACHE" != no ] && [ "$HOST_HTTPPORT_APACHE" != '' ]; then
                PORTMAPPING="${PORTMAPPING}-p $((HOST_HTTPPORT_APACHE-0)):81 "
            fi
            if [ "$HOST_HTTPPORT_FRANKENPHP" != no ] && [ "$HOST_HTTPPORT_FRANKENPHP" != '' ]; then
                PORTMAPPING="${PORTMAPPING}-p $((HOST_HTTPPORT_FRANKENPHP-0)):1082 "
            fi
            if [ "$HOST_HTTPPORT_NGINX" != no ] && [ "$HOST_HTTPPORT_NGINX" != '' ]; then
                PORTMAPPING="${PORTMAPPING}-p $((HOST_HTTPPORT_NGINX-0)):80 "
            fi
            if [ "$HOST_HTTPPORT_ROADRUNNER" != no ] && [ "$HOST_HTTPPORT_ROADRUNNER" != '' ]; then
                PORTMAPPING="${PORTMAPPING}-p $((HOST_HTTPPORT_ROADRUNNER-0)):1083 "
            fi
            if [ "$HOST_HTTPPORT_SWOOLE" != no ] && [ "$HOST_HTTPPORT_SWOOLE" != '' ]; then
                PORTMAPPING="${PORTMAPPING}-p $((HOST_HTTPPORT_SWOOLE-0)):1084 "
            fi
            if [ "$HOST_PROXYPORT_APACHE" != no ] && [ "$HOST_PROXYPORT_APACHE" != '' ]; then
                PORTMAPPING="${PORTMAPPING}-p $((HOST_PROXYPORT_APACHE-0)):8081 "
            fi
            if [ "$HOST_PROXYPORT_FRANKENPHP" != no ] && [ "$HOST_PROXYPORT_FRANKENPHP" != '' ]; then
                PORTMAPPING="${PORTMAPPING}-p $((HOST_PROXYPORT_FRANKENPHP-0)):8082 "
            fi
            if [ "$HOST_PROXYPORT_NGINX" != no ] && [ "$HOST_PROXYPORT_NGINX" != '' ]; then
                PORTMAPPING="${PORTMAPPING}-p $((HOST_PROXYPORT_NGINX-0)):8080 "
            fi
            if [ "$HOST_PROXYPORT_ROADRUNNER" != no ] && [ "$HOST_PROXYPORT_ROADRUNNER" != '' ]; then
                PORTMAPPING="${PORTMAPPING}-p $((HOST_PROXYPORT_ROADRUNNER-0)):8083 "
            fi
            if [ "$HOST_PROXYPORT_SWOOLE" != no ] && [ "$HOST_PROXYPORT_SWOOLE" != '' ]; then
                PORTMAPPING="${PORTMAPPING}-p $((HOST_PROXYPORT_SWOOLE-0)):8084 "
            fi
            if [ ! -d "${ROOT_DIR}/tests/env/var/composer_cache" ]; then mkdir -p "${ROOT_DIR}/tests/env/var/composer_cache"; fi

            # shellcheck disable=SC2086
            if ${DOCKER_CMD} run -d $PORTMAPPING \
                --name "${CONTAINER_NAME}" \
                --env "CONTAINER_USER_UID=$(id -u)" --env "CONTAINER_USER_GID=$(id -g)" \
                --env "TESTS_ROOT_DIR=${CONTAINER_WORKSPACE_DIR}" \
                --env "INSTALL_ON_START=${COMPOSER_INSTALL_ON_START}" \
                --env "START_WEBSERVER=${START_WEBSERVER}" \
                -v "${ROOT_DIR}:${CONTAINER_WORKSPACE_DIR}" \
                -v "${ROOT_DIR}/tests/env/var/composer_cache:/home/${CONTAINER_USER}/.cache/composer" \
                 "${IMAGE_NAME}"; then
                wait_for_bootstrap
            fi
        fi
    fi
}

wait_for_bootstrap() {
    I=0
    while [ $I -le 60 ]; do
        if [ -f "${ROOT_DIR}/tests/env/var/bootstrap_ok_${UBUNTU_VERSION}_${PHP_VERSION}_${WEBSERVER_TYPE}" ]; then
            echo ''
            break;
        fi
        printf '.'
        sleep 1
        I=$((I+1))
    done
    if [ $I -gt 60 ]; then
        echo ''
        echo "ERROR: Container did not finish bootstrapping within 60 seconds..." >&2
        return 1
    fi
    return 0
}

stop() {
    if [ "$(${DOCKER_CMD} inspect --format '{{.State.Status}}' "${CONTAINER_NAME}" 2>/dev/null)" = exited ]; then
        if [ "$1" != -q ]; then
            echo "${CONTAINER_NAME} already stopped"
        fi
    else
        if ${DOCKER_CMD} inspect "${CONTAINER_NAME}" >/dev/null 2>/dev/null; then
            if [ "$1" != -q ]; then
                echo "stopping ${CONTAINER_NAME}..."
            fi
            ${DOCKER_CMD} stop "${CONTAINER_NAME}"
        fi
    fi
}

runtests() {
    # @todo allow auto-deleting the container after execution - use either a cli option or an env var?
    if [ "$(${DOCKER_CMD} inspect --format '{{.State.Status}}' "${CONTAINER_NAME}" 2>/dev/null)" != running ]; then
        start
    fi
    if [ -z "$1" ]; then
        TESTSUITE=tests
    else
        TESTSUITE="$*"
    fi
    test -t 1 && USE_TTY="-t"
    if [ "$WEBSERVER_TYPE" != all ]; then
        if [ -z "$TEST_WEBSERVER" ]; then
            TEST_WEBSERVER="$WEBSERVER_TYPE"
        else
            if [ "$WEBSERVER_TYPE" != "$TEST_WEBSERVER" ]; then
                echo "WARNING: the test container in use was built with webserver '$WEBSERVER_TYPE', but the webserver requested for running the test is '$TEST_WEBSERVER'"
            fi
        fi
    fi
    ENV_VAR_INJECTION="VERBOSITY=$VERBOSITY"
    if [ -n "$TEST_WEBSERVER" ]; then
        ENV_VAR_INJECTION="$ENV_VAR_INJECTION SERVER_TYPE=$TEST_WEBSERVER"
    fi
    if [ -n "$TEST_WAF" ]; then
            ENV_VAR_INJECTION="$ENV_VAR_INJECTION WAF_TYPE=$TEST_WAF"
        fi
    lock
    trap unlock INT
    RETCODE=0
    {
        ${DOCKER_CMD} exec $USE_TTY "${CONTAINER_NAME}" /root/setup/setup_app.sh "${CONTAINER_WORKSPACE_DIR}"
        if [ "$TEST_WEBSERVER" = swoole ] || [ "$TEST_WAF" = swoole ]; then
            # reload the swoole workers, in case this is a dev env and code was recently changed
            ${DOCKER_CMD} exec $USE_TTY "${CONTAINER_NAME}" /etc/init.d/swoole_server restart
            ${DOCKER_CMD} exec $USE_TTY "${CONTAINER_NAME}" /etc/init.d/swoole_waf restart
        fi
        ${DOCKER_CMD} exec -i $USE_TTY \
            "${CONTAINER_NAME}" su "${CONTAINER_USER}" -c "$ENV_VAR_INJECTION ./vendor/bin/phpunit --log-junit ./tests/env/var/test-results.xml $TESTSUITE"
    } || {
        RETCODE="$?"
    }
    unlock
    return $RETCODE
}

runcoverage() {
    # @todo allow auto-deleting the container after execution - use either a cli option or an env var?
    if [ "$(${DOCKER_CMD} inspect --format '{{.State.Status}}' "${CONTAINER_NAME}" 2>/dev/null)" != running ]; then
        start
    fi
    # @todo double-check if setup_code_coverage.sh does always need a tty (`-t`). If so, abort if `test -t 1` fails
    test -t 1 && USE_TTY="-t"
    RETCODE=0
    lock
    trap unlock INT
    {
        # @todo clean up /tmp/phpxmlrpc_coverage and .phpunit.result.cache (in setup_code_coverage.sh?)
        ${DOCKER_CMD} exec $USE_TTY "${CONTAINER_NAME}" /root/setup/setup_app.sh "${CONTAINER_WORKSPACE_DIR}" || true
        if [ ! -d ./var/coverage ]; then mkdir -p ./var/coverage; fi
        ${DOCKER_CMD} exec -t "${CONTAINER_NAME}" /root/setup/setup_code_coverage.sh enable
        ${DOCKER_CMD} exec -i $USE_TTY \
            "${CONTAINER_NAME}" su "${CONTAINER_USER}" -c "./vendor/bin/phpunit --coverage-html tests/env/var/coverage tests"
        ${DOCKER_CMD} exec -t "${CONTAINER_NAME}" /root/setup/setup_code_coverage.sh disable
    } || {
       RETCODE="$?"
    }
    unlock
    return $RETCODE
}

lock() {
    if [ -f ./var/tests_executing.lock ]; then
        echo "ERROR: tests are already running - or there is a leftover lock file. Use 'unlock' action to remove it" >&2
        exit 1
    else
        touch ./var/tests_executing.lock
    fi
}

unlock() {
    if [ -f ./var/tests_executing.lock ]; then
        rm ./var/tests_executing.lock;
    fi
}

check_requirements

case "${ACTION}" in

    build)
        build -r
        ;;

    cleanup)
        # @todo allow to cleanup tests/env/var completely - use either a cli option or a separate action?
        # @todo allow to only remove the container but not the image - use either a cli option or a separate action?
        if ${DOCKER_CMD} inspect "${CONTAINER_NAME}" >/dev/null 2>/dev/null; then
            stop -q
            ${DOCKER_CMD} rm "${CONTAINER_NAME}"
        fi
        ${DOCKER_CMD} rmi "${IMAGE_NAME}"
        ;;

    enter | shell | cli)
        # @todo allow login as root - use either a cli option or a separate action?
        ${DOCKER_CMD} exec -it \
            "${CONTAINER_NAME}" su "${CONTAINER_USER}"
        ;;

    exec)
        shift
        test -t 1 && USE_TTY="-t"
        # shellcheck disable=SC2016
        ${DOCKER_CMD} exec -i $USE_TTY "${CONTAINER_NAME}" su "${CONTAINER_USER}" -c '"$0" "$@"' -- "$@"

            # @todo which one is better? test with a command with spaces in options values, and with a composite command such as cd here && do that
            #"${CONTAINER_NAME}" sudo -iu "${CONTAINER_USER}" -- "$@"
        ;;

    restart)
        stop
        start
        ;;

    runcoverage)
        runcoverage
        ;;

    runtests)
        shift
        runtests "$@"
        ;;

    start)
        start
        ;;

    stop)
        stop
        ;;

    ps)
        ${DOCKER_CMD} ps --filter "name=${CONTAINER_NAME}"
        ;;

    diff | inspect | kill | logs | pause | port | stats | top | unpause)
        ${DOCKER_CMD} container "${ACTION}" "${CONTAINER_NAME}"
        ;;

    unlock)
        unlock
        ;;

    -h)
        help
        exit 0
        ;;

    *)
        printf "\n\e[31mERROR:\e[0m unknown action '%s'\n\n" "${ACTION}" >&2
        help
        exit 1
        ;;
esac
