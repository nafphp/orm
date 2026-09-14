<div style="text-align: center;">

![NAF](assets/naf-logo-small-square.png)

[![NAF ORM Plugin](https://github.com/nafphp/orm/actions/workflows/php.yml/badge.svg)](https://github.com/nafphp/orm/actions/workflows/php.yml)

</div>

[← Back to NAF](https://github.com/nafphp/framework)

---

# naf/orm

> **Minimalistic object mapper for your NAF application.**

This plugin adds basic ORM support to NAF:  
lightweight, readable, and ideal for small to medium use cases.

It supports nested entity saving (including pivot tables),  
auto-discovery of related entities, and repository-based lazy-loading.

> 🧩 Part of the official NAF plugin collection.  
> Use it if you want structured object handling – but without the complexity of full-stack ORM systems.

## Documentation

**[ORM and repositories →](https://nafphp.github.io/docs/orm/)**

Everything about this package — what it does, how it is configured and what it needs — lives
in the [NAF documentation](https://nafphp.github.io/docs/). Not sure which packages you need?
[Start here](https://nafphp.github.io/docs/choosing-packages/).

## Install

```bash
composer require naf/orm
```

## License

MIT. Part of [NAF](https://github.com/nafphp/framework).


## Unreleased Nafinity integration candidate

Target branch: `v0.2.2-rc`. This behavior is not a published release yet.

Entity persistence and repositories both honor getTableName(bool $singular = false). EntityManager commits or rolls back only its own outer transaction; caller-owned and nested work uses savepoints. An external transaction therefore remains open after an application service completes.
