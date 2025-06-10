# MySQLi Adapter for php-casbin

> **Forked from [php-casbin/database-adapter](https://github.com/php-casbin/database-adapter)**

This is a database adapter for [PHP-Casbin](https://github.com/php-casbin/php-casbin) rewritten to use **MySQLi only**.  
It does **not** use PDO or [leeqvip/database](https://github.com/leeqvip/database).

## Supported Database

MySQL databases are supported by instantiating a native PHP MySQLi connection and passing it into the constructor.

## Installation

Use [Composer](https://getcomposer.org/) and add the following to your `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/Indexify-Finance/php-casbin-mysqli-adapter"
    }
  ],
  "require": {
    "indexify/php-casbin-mysqli-adapter": "dev-master"
  }
}
```

Then run:

```sh
composer update
```

## Usage

```php
require_once './vendor/autoload.php';

use Casbin\Enforcer;
use PhpCasbinMysqliAdapter\Database\Adapter as DatabaseAdapter;

$connection = new mysqli(
    'localhost',    // hostname
    'username',     // database username
    'password',     // database password
    'database_name' // database name
);
$adapter = DatabaseAdapter::newAdapter($connection, policy_table_name: 'casbin_rule');

$e = new Enforcer('path/to/model.conf', $adapter);

$sub = "alice"; // the user that wants to access a resource.
$obj = "data1"; // the resource that is going to be accessed.
$act = "read"; // the operation that the user performs on the resource.

if ($e->enforce($sub, $obj, $act) === true) {
    // permit alice to read data1
} else {
    // deny the request, show an error
}
```

## Getting Help

- [php-casbin](https://github.com/php-casbin/php-casbin)

## License

This project is licensed under the [Apache 2.0 license](LICENSE).
