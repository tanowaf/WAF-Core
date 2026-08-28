#!/bin/sh

# Builds custom php extensions using PIE

# @todo move to getopts, to allow callers to specify packages to install, build opts, etc...

set -e

# this list does not include the libraries needed at runtime by the php extensions we are building - we leave it to
# install_packages.sh to install them. Only "-dev" libs are listed here
# @todo which extensions require zlib1g-dev?
PACKAGES='autoconf make pkg-config zlib1g-dev unzip'
for EXTENSION in "$@"; do
    # @todo allow passing in optional packages instead of hardcoding them
    case "$EXTENSION" in
        #extport/protobuf)
        #    PACKAGES="$PACKAGES "
        #    ;;
        #kjdev/brotli)
        #    PACKAGES="$PACKAGES "
        #    ;;
        #kjdev/zstd)
        #    PACKAGES="$PACKAGES "
        #    ;;
        osmanov/pecl-event)
            PACKAGES="$PACKAGES libevent-dev"
            ;;
        openswoole/ext-openswoole*)
            PACKAGES="$PACKAGES g++ libcurl4-openssl-dev liburing-dev"
        ;;
        swoole/swoole)
            # does this also require libcurl4-openssl-dev?
            PACKAGES="$PACKAGES g++ liburing-dev"
        ;;
    esac
done

# assumes curl is already installed
apt-get install -y $PACKAGES

# @todo install the github cli to verify the pie download (see f.e. https://linuxcapable.com/how-to-install-github-cli-on-ubuntu-linux/)
#  && gh attestation verify --owner php /tmp/pie.phar \
curl -fL --output /tmp/pie.phar https://github.com/php/pie/releases/latest/download/pie.phar && \
    mv /tmp/pie.phar /usr/local/bin/pie && \
    chmod +x /usr/local/bin/pie

for EXTENSION in "$@"; do
    # @todo allow passing in optional build args instead of hardcoding them
    OPTS=
    case "$EXTENSION" in
        #extport/protobuf)
        #    OPTS=
        #    ;;
        #kjdev/brotli)
        #    OPTS=
        #    ;;
        #kjdev/zstd)
        #    OPTS=
        #    ;;
        osmanov/pecl-event)
            # [--with-event-openssl] [--with-event-pthreads]
            OPTS='--with-event-openssl'
            ;;
        openswoole/ext-openswoole*)
            # [--enable-cares] [--enable-hook-curl] [--enable-http2] [--enable-io-uring] [--enable-mysqlnd] [--enable-openssl] [--enable-sockets]
            OPTS='--enable-io-uring --enable-openssl  --enable-sockets --enable-hook-curl --enable-http2'
        ;;
        swoole/swoole)
            # also: --enable-cares --enable-mysqlnd --enable-swoole-ftp --enable-swoole-pgsql --enable-swoole-sqlite --enable-swoole-thread
            OPTS='--enable-brotli --enable-iouring --enable-sockets --enable-swoole-curl --enable-uring-socket --enable-zstd'
        ;;
    esac

    # @todo it might be a good idea not to use the `--auto-install-system-dependencies` option, as that would
    #       make it visible which libs are required, so that we can make sure we manually install them on the 'run'
    #       container (this script might be run in a 'build' container)

    if ! pie install --auto-install-build-tools --auto-install-system-dependencies --no-interaction "$EXTENSION" $OPTS; then
        cat /tmp/pie_make_output_*
        exit 1
    fi
done
