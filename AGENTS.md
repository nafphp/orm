# Working on naf/orm

NAF is a small PHP framework with optional Composer plugins. Its core owns boot,
configuration, the service container, routing, events and PSR-7 responses. Prefer existing
NAF helpers, services and extension interfaces; keep application business rules in the host.
This package declares `type: naf-plugin` and is discovered after installation in a NAF host.
The plugin repository itself is not the application's web root.

Before changing code, read the [shared contribution workflow](https://github.com/nafphp/docs/blob/main/AGENT_WORKFLOW.md)
and [release procedure](https://github.com/nafphp/docs/blob/main/RELEASING.md).
In the multi-repository workspace, the same documents are in the sibling `docs/` checkout;
use the linked copies when working from a standalone clone. Preserve other contributors' work.
Review and update user documentation with every behavior change. Source fixes use an RC branch;
verified documentation-only changes can be merged and published by the agent.

## What this plugin does

`naf/orm` adds entities, repositories and an entity manager over `naf/database`/PDO. Install
with `composer require naf/orm`, configure the host database and migrate its schema. Import
`Naf\ORM\em` and `repo`; this is not Eloquent and does not provide static `Model::query()` APIs.

## Use it

For application-owned `Product` and `ProductRepository` classes implemented as in the guide:

```php
<?php
use App\Models\Product;
use App\Repositories\ProductRepository;
use function Naf\ORM\{em, repo};

$product = new Product(['name' => 'Notebook']);
em()->save($product);
$products = repo(ProductRepository::class)->findAll();
```

Those two application classes and their migrated table are prerequisites, not shipped plugin
classes. Models extend `AbstractModel`/implement the entity contract; repositories implement
`getEntityClass()`. Declare an explicit table when the default class-name plural is unsuitable.
Allow-list input fields instead of passing the complete HTTP request to an entity constructor.

## Change it here

[EntityManager](src/Core/EntityManager.php) owns persistence and transaction nesting;
[AbstractModel](src/Model/AbstractModel.php), [entity contracts](src/Core/EntityInterface.php),
[AbstractRepository](src/Repository/AbstractRepository.php) and
[RepositoryFactory](src/Repository/RepositoryFactory.php) own model/query behavior.
Reuse repositories for queries and services for domain workflows. Bind values and validate
column identifiers in dynamic queries. Preserve relation handling and transaction/savepoint
state when extending persistence. Prefer the configured connection over opening another PDO.

## Verify

Run `composer test` and `composer validate --strict`. Use [tests](tests/) for entity fixtures,
CRUD, query allow-lists, relationships and transaction rollback/nesting. Check engine-specific
SQL against that engine when relevant. No `analyse` script is declared.

User docs: [ORM](https://nafphp.github.io/docs/orm/).
