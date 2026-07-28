<div style="text-align: center;">

![Logo](https://nixphp.github.io/docs/assets/nixphp-logo-small-square.png)

[![NixPHP ORM Plugin](https://github.com/nixphp/orm/actions/workflows/php.yml/badge.svg)](https://github.com/nixphp/orm/actions/workflows/php.yml)

</div>

[← Back to NixPHP](https://github.com/nixphp/framework)

---

# nixphp/orm

> **Minimalistic object mapper for your NixPHP application.**

This plugin adds basic ORM support to NixPHP:  
lightweight, readable, and ideal for small to medium use cases.

It supports nested entity saving (including pivot tables),  
auto-discovery of related entities, and repository-based lazy-loading.

> 🧩 Part of the official NixPHP plugin collection.  
> Use it if you want structured object handling – but without the complexity of full-stack ORM systems.

---

## 📦 Features

* ✅ Save any entity using `em()->save($entity)`
* ✅ Detects and stores relations automatically
* ✅ Supports `Many-to-One`, `One-to-Many`, and `Many-to-Many` out of the box
* ✅ Uses simple PHP classes, no annotations or metadata
* ✅ Makes repository-based lazy-loading easy to implement in regular `getX()` methods
* ✅ Comes with a clean `AbstractRepository` for queries

---

## 📥 Installation

```bash
composer require nixphp/orm
```

`nixphp/database` is installed automatically as a dependency.

---

## 🛠 Configuration

This plugin uses the shared PDO instance from [`nixphp/database`](https://github.com/nixphp/database).
Make sure your `/app/config.php` contains a working `database` section.

### Example: MySQL

```php
return [
    // ...
    'database' => [
        'driver'   => 'mysql',
        'host'     => '127.0.0.1',
        'database' => 'myapp',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ]
];
```

### Example: SQLite

```php
return [
    // ...
    'database' => [
        'driver'   => 'sqlite',
        'database' => __DIR__ . '/../storage/database.sqlite',
    ]
];
```

Or for in-memory usage (great for testing):

```php
return [
    // ...
    'database' => [
        'driver'   => 'sqlite',
        'database' => ':memory:',
    ]
];
```

---

## 🧩 Usage

### Define your models

Models extend `AbstractModel`, which already implements `EntityInterface` via
`EntityTrait`.

```php
use NixPHP\ORM\Model\AbstractModel;
use function NixPHP\ORM\repo;

class Product extends AbstractModel
{
    protected ?int $id = null;
    protected string $name = '';
    protected ?int $category_id = null;
    protected ?Category $category = null;
    protected array $tags = [];

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setCategory(Category $category): void
    {
        $this->category = $category;
    }

    public function addTag(Tag $tag): void
    {
        $this->tags[] = $tag;
    }

    public function getTags(): array
    {
        if ($this->tags === [] && $this->id !== null) {
            $this->tags = repo(TagRepository::class)
                ->findByPivot(Product::class, $this->id);
        }

        return $this->tags;
    }

    public function getCategory(): ?Category
    {
        if ($this->category === null && $this->category_id !== null) {
            $this->category = repo(CategoryRepository::class)
                ->findOneBy('id', $this->category_id);
        }

        return $this->category;
    }
}
```

### Saving data

```php
use function NixPHP\ORM\em;
use function NixPHP\ORM\repo;

$category = repo(CategoryRepository::class)->findOrCreateBy('name', 'Books');
$tagA     = repo(TagRepository::class)->findOrCreateBy('name', 'Bestseller');
$tagB     = repo(TagRepository::class)->findOrCreateBy('name', 'Limited');

$product = new Product();
$product->setName('NixPHP for Beginners');
$product->setCategory($category);
$product->addTag($tagA);
$product->addTag($tagB);

em()->save($product);
```

### Reading data

```php
use function NixPHP\ORM\repo;

$product = repo(ProductRepository::class)->findOneBy('id', 1);

if ($product !== null) {
    echo $product->getName();
    print_r($product->getCategory());
    print_r($product->getTags());
}
```

The getters above implement lazy-loading explicitly through repositories.
When saving, entity-object properties and arrays of entities are discovered
automatically. A child foreign key follows the `<parent>_id` convention; pivot
table names are built from the two singular table names in alphabetical order.
For a custom pivot name, define a public mapping on either entity:

```php
public array $pivotTables = [
    Tag::class => 'article_tags',
];
```

---

## 📚 Philosophy

This ORM is intentionally small and predictable.
It provides just enough structure to manage entities and relations –
without introducing complex abstractions or hidden behavior.

If you need validation, eager loading, event hooks, or advanced query building,
you can integrate any larger ORM of your choice alongside it.

---

## ✅ Requirements

* PHP >= 8.3
* `nixphp/framework` ^0.1.2
* `nixphp/database` ^0.1.1

---

## 📄 License

MIT License.
