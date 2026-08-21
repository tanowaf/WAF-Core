# TanoWAF - WAF core

A PHP library for building Web API Firewalls - and other assorted HTTP Proxies.

Primary finished-product targets are forward proxies for filtering the requests and responses of calls to HTTP APIs to
only let through what you want to expose.

Example use-cases:
- reducing the surface of an API, eg. only allowing READ requests or access to specific URLs
- removing sensitive data from an API's responses
- adding/modifying/removing http headers
- tracing of requests and responses
- rate limiting (not implemented, but should be implementable with existing components from other packages)
- caching (not implemented, but should be implementable with existing components from other packages)

Similar software:
- OWASP Coraza (written in Go, can be run as Caddy/Nginx/HAProxy module; uses more-standard but less-readable configuration)

## Work In Progress

### Working

- Support for listening on http and on unix sockets, forwarding to http and unix sockets
- Matching requests and responses based on HTTP headers, request/response body and most other HTTP fields
- End-to-end testing of all implemented features using Apache, FrankenPHP and Nginx: locally via a container-based
  test environment and Continuous Integration on every push to GitHub

### In scope (to be implemented)

Main missing features:
- Matching request/response bodies using jsonpath/css/xpath expressions
- Filtering (modification of requests/responses)
- "restart the processing chain" as possible action for matching rules
- "increase/decrease a matching score" as possible action for matching rules
- HTTPS support
- HTTP2, HTTP3 support
- Documentation
- support for Swoole and OpenSwoole PHP runtimes is in progress...

See the [Roadmap](Roadmap.md) for a detailed list of features not yet implemented.

### Out of scope

Not in scope (yet?):
- a GUI
- dispatching requests to different upstream backends based on conditions
- filtering request/response bodies other than Json
- feature parity with Varnish or performance parity with HAProxy
- using async requests to connect to upstream servers
- implementing rate-limiting, caching (with own code - we should allow usage of PSR compliant external code for that)
- rules/filters targeted at protecting the client from rogue servers' responses

## Requirements:

* PHP 8.2 and up, with extensions: ctype, json and zlib.

  The php `curl` extension is recommended, as it is required for most of the complex http stuff when making requests to
  the upstream server.

* A webserver to run it.

  If running Nginx, please use the very latest version available, ideally > 1.29.0, as it comes with improvements in parsing
  of HTTP headers to better conform to RFC9110 (see f.e. bug #187).

  If you want to have the proxy listening on a unix socket instead of an http port, choose an http server which can do that:
  as of June 2026 Nginx and FrankenPHP can, while Apache can't.

  Note that this library is tested only with the following webservers, at least for the moment: Apache, FrankenPHP and Nginx.

* Either the `symfony/http-client` or `guzzlehttp/guzzle` (version 8.0 or later) php packages.

## Installation

Via Composer: `composer require tanowaf/waf-core:dev-main`

Then install either `symfony/http-client` or `guzzlehttp/guzzle`.

## Usage

More examples will come...

For the moment, see project https://github.com/tanowaf/Yet-Another-Docker-Socket-Proxy as example.

Or take a look at the proxies used for the unit testing suite and for load testing in [./tests/public/waf.php](./tests/public/waf.php),
[./tests/public/loadtest.php](./tests/public/loadtest.php)

## Design principles

1. Security first. No requests are allowed by default, everything has to be whitelisted.
2. Ease of use. Error messages should be clear and rather verbose than cryptic. Logging facilities should be extensive.
   Ambiguous configuration should be rejected.
3. Flexibility. The proxies should be easy to configure for common scenarios and extend to achieve uncommon ones.
   A Docker image shall be provided to get started running a "whitelabel" Firewall with no fuss.
4. Stability. No API breackage allowed after version 1.0 is released. Strict adherence to semantic versioning.
5. Performance. Maximum speed of execution and minimum cpu usage / memory usage are _important_. But not the main concern:
   safety, robustness and flexibility come first.
6. Versatility. Proxies and Firewalls built on this library should work the same regardless of the webserver used to
   run PHP, be it Apache, Nginx, FrankenPHP or something else. The library should interoperate seamlessly with 3rd-party
   components readily available in the php ecosystem.

Which translates into:
- PHP 8.2 and up
- strict typing everywhere
- using DI patterns as much as possible
- using the PSR-7, PSR-15, PSR-18 interfaces means it should be easy to extend/embed the Proxy classes in other middlewares
- avoid relying on too many, big dependencies - f.e. no Monolog, Symfony ConfigTreeBuilder
- delegate all possible processing to a 'bootstrap' phase, so that the processing loop can be as efficient as possible
  when used in eg. `worker` mode with FrankenPHP
- taking care about memory leaks
- prefer end-to-end testing to unit testing, as the specific webserver used to run php does have an impact on the
  processing by the WAF-Core code of http requests, esp. the ones which are not conforming to the http standard

## Testing

Given the non-trivial set of configuration required to carry out end-to-end tests, the recommended setup is to use
the provided docker-based stack to run the test suite

```shell
./tests/env/container.sh build
./tests/env/container.sh start
./tests/env/container.sh runtests
./tests/env/container.sh stop
```

The testsuite can be run using FrankenPHP or Apache as webserver with the following commands:

`TEST_WEBSERVER=frankenphp ./tests/env/container.sh runtests`

`TEST_WEBSERVER=apache ./tests/env/container.sh runtests`

## FAQ

* Why write this in PHP instead of Go or Rust?

  Because I would have had to learn those languages at the same time as learning all the fine details of parsing HTTP

* How fast is this? Can it scale?

  Preliminary load testing shows that, when running the WAF with FrankenPHP in worker mode, a delay of 0.3 ms per request
  is introduced, when using the smallest possible filtering ruleset. With Swoole, this is down to 0.1 ms per request.

  Further testing is planned, including optimizing php and webserver configuration and measuring cpu and memory usage.

* Why not reusing Symfony HTTP Foundation / another existing library?

  None of the existing PHP libraries that I am aware of are designed to be used for building proxies or firewalls.
  In fact, the PHP engine itself is very opinionated in the request data it makes available to php scripts. This has led
  to having to develop custom parser code for things such as HTTP Headers, Cookies and the URL Query String.

## License

Use of this software is subject to the terms in the [LICENSE](LICENSE) file


[![License](https://poser.pugx.org/tanowaf/WAF-Core/license)](https://packagist.org/packages/tanowaf/WAF-Core)
[![Latest Stable Version](https://poser.pugx.org/tanowaf/WAF-Core/v/stable)](https://packagist.org/packages/tanowaf/WAF-Core)
[![Total Downloads](https://poser.pugx.org/tanowaf/WAF-Core/downloads)](https://packagist.org/packages/tanowaf/WAF-Core)

[![Build Status](https://github.com/tanowaf/WAF-Core/actions/workflows/ci.yaml/badge.svg)](https://github.com/tanowaf/WAF-Core/actions/workflows/ci.yaml)
[![Code Coverage](https://codecov.io/github/tanowaf/WAF-Core/branch/main/graph/badge.svg)](https://app.codecov.io/github/tanowaf/WAF-Core)
