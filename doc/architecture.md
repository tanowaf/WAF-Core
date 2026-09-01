# WAF Architecture

```mermaid
architecture-beta
    group client(cloud)[Client]
        service request(disk)[Request] in client
        service fail(disk)[Fail Response] in client
        service response(disk)[Pass response] in client
    group waf(server)[WAF]
        group firewall(server)[Firewall] in waf
            service freq(disk)[Filter] in firewall
            service fresp(disk)[Filter] in firewall
        group proxy(server)[Proxy] in waf
            service preq(disk)[Filter] in proxy
            service presp(disk)[Filter] in proxy
    group upstream(cloud)[Upstream]
        service ureq(disk)[Request] in upstream
        service uresp(disk)[Response] in upstream

    request:R --> L:freq
    freq:R --> L:preq
    freq:L --> R: fail
    preq:R --> L:ureq
    preq:L --> R: fail
    ureq:B --> T:uresp
    uresp:L --> R:presp
    presp:L --> R:fresp
    presp:L --> R: fail
    fresp:L --> R:response
    fresp:L --> R: fail

    align row request freq preq ureq
    align row response fresp presp uresp
    align column request fail response
    align column freq fresp
    align column preq presp
    align column ureq uresp
```
