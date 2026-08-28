- Firewall
  - matching requests/responses
    - req. body: regexp
    - req. body: jsonpath-like matching
    - support other wildcards besides the `*`?
      - glob has: ? for one char, [...] for char ranges, [!...] for negated char ranges
      - sql LIKE has `%` and `_`
      - we most likely should just allow full regexp instead, as it is quite useful at least for forbidden char ranges...
      - for which matcher a 'literal' version is importamt? body, header, user_agent, query_string, ...
    - client_address: support v6 IPs without forcing users to write a complex regexp
    - other?
      - add a `/trim` modifier, similar to `/case_insensitive` and `/no_wildcards`, and document which chars get trimmed
        (note that http headers, unless using double-quote spans, already get trimmed values to match upon)
      - a `valid_json` matcher for both body, headers and query string elements
      - eg. ssl on
  - implement filtering support
      - check: can we make filters add "tags" to both requests and responses, to ease later processing? See psr 'attributes'
  - allow 'restart' as action for (Request) rules
    - allow setting a maxRestarts limit
    - q: should we remove from the current rule chain a rule, after it did trigger a restart? (possibly use 2 `restart` types?)
  - allow NO-OP as action for rules?
  - review: can we do the same (but better) as all the haproxy rules in NC-AIO haproxy.cfg?
  - review: can we implement all rules from OWASP Top 10?
    Also, take a look at OWASP Coraza:
    - can we automatically transform WAF-Core rules into Coraza ones? And vice-versa?
    - take hints from features supported by Coraza, eg. setting resource limits (eg. on resp body size etc),
      have 'log' as rule actions, have a do-not-deny-but-log-violations mode, etc...
  - xml req./resp. body with xpath/css matchers
  - specific matchers for soap, xmlrpc, graphql, grpc
  - allow failures of the MethodMatcher to generate a 501 response instead of the default 403?
  - API reworking:
    - clean up the `*MatcherInterface` mess: drop MatcherInterface; move Logic/* matchers to MessageInterface?
    - check: could we use the firewall filters to implement something like https://github.com/terrylinooo/shieldon instead
      of a waf to remote apps, or would it need some api changes?
    - do we need to keep *Filter as an alternative to Middleware?
    - make it easy to install a (resp) tracer as 1st middleware in the chain that does not get bypassed in case other MWs throw

- Proxy
  - add by default (or via a filter?) the http headers telling upstream about real-ip and x-forwarded-protocol, patch hop-by-hop headers
    see fe. https://docs.google.com/document/d/1rJRV3s_Kto9_nx-ROjwG0ncA8JNeKz8xaaJXdrbJx7s/edit?pli=1&tab=t.0
  - finish support for setting timeouts (connect, read? and total)
    - also, other options? see the ones present both in Symfony\Contracts\HttpClient\HttpClientInterface and GuzzleHttp\requestOptions
  - finish support for `tcp://` upstreams
  - tls & https support
  - figure out if we can make it easy to allow using the existing "client middlewares" from other libraries, to allow
    adding behaviour such as caching, throttling, etc...
    - when using the Sf HTTP Client: this is possible via creating an Sf HTTP Client that "wraps" the base one
    - when using Guzzle: this is possible by passing a 'handler' option to the Client creator, and adding
      middleware handlers to it (see https://docs.guzzlephp.org/en/stable/handlers-and-middleware.html)
    - HTTPlug: middlewares are called 'plugins', implementing https://github.com/php-http/client-common/blob/2.x/src/Plugin.php
    -> could we wrap Guzzle handlers and HTTPlug plugins in a way to make them satisfy the *Filter interfaces?
    -> could we implement adapters that do the opposite, with our filters?
    -> how does our code fare in the context of async clients?
  - make it easy to implement a reverse proxy too + add tests + give examples on how to do that
  - add a middleware that follows redirections (up to N)
  - add a dedicated middleware that does not force an accept-encoding upstream, but adds compression downstream
  - add http client adapters for php-http/curl-client (see https://docs.php-http.org/en/latest/clients/curl-client.html)
    and other "well known" psr-18 http clients (there are eg. a plethora of them in httplug's client-common package,
    including the PluginClient, which allows to add further processing to the request before it hits upstream, but that
    one does not allow access to its wrapped client in any way, so we can not push down options to it...), as well
    as async http clients from swoole/openswoole/amphp/workerman

- Docs
  - document all the supported matchers
  - create diagram for proxy / middlewares / handlers (use Mermaid?)
  - create flow diagram with firewall rules req/resp matching and filtering
  - add config examples for common use-cases, eg. 'all readonly', 'redact secrets', 'inject headers', 'fix Host', etc...
    see fe. all cases listed at https://codingchallenges.fyi/challenges/challenge-forward-proxy/

- Loggers
  - improve message formatting: add context

- Testing
  - add more tests which try to exploit issues in http parsers, see f.e. https://hostoftroubles.com/
  - see all the tests run by https://www.http-probe.com/
  - on GH, run tests on a matrix of all supported php, ubuntu but also webserver versions
    - add one test using frankenphp worker mode
    - test also against: apache+mod_php, php-http-server, lighttpd, openlitespeed, roadrunner, openswoole, workerman
      - use a cloud-based platform that provides those ready-built, rather than installing each one by ourselves?
        Either that, or move to a multi-container setup for testing...
  - add tests which make use of middleware from other projects, eg. rate-limiting and caching

- Misc
  - introduce more structured exceptions
  - allow fine-tuning resource usage: max concurrent conns, etc... (here on in downstream projects?)
  - perf: allow streaming compression/decompression of message bodies
  - perf: save decompressed version of message bodies for reuse in further filters/matchers

- Maybe?
  - create our own implementation of a psr-compliant http upstream client used by the proxy,
    as that might lead to easier-to-debug-and-maintain code than Sf and Guzzle...
  - create our own webserver as stand-alone php cli app, as that gives absolute control on how http headers are parsed
    (see Qbix, AppserverIo, Workerman for examples of reasonably performing and complete implementations)
