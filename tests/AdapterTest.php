<?php

namespace Tests;

use Casbin\Enforcer;
use Casbin\Model\Model;
use Casbin\Persist\Adapter;
use PhpCasbinMysqliAdapter\Database\Adapter as DatabaseAdapter;
use PHPUnit\Framework\TestCase;

class AdapterTest extends TestCase
{
    protected $config = [];
    protected $connection;

    protected $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initConfig();
        $this->connection = $this->createMysqliConnection();
    }

    protected function createMysqliConnection()
    {
        $conn = new \mysqli(
            $this->config['hostname'],
            $this->config['username'],
            $this->config['password'],
            $this->config['database'],
            $this->config['hostport']
        );
        if ($conn->connect_error) {
            throw new \RuntimeException('Connection failed: ' . $conn->connect_error);
        }
        return $conn;
    }

    protected function initConfig()
    {
        $this->config = [
            'type' => 'mysql',
            'hostname' => $this->env('DB_HOST', '127.0.0.1'),
            'database' => $this->env('DB_DATABASE', 'casbin'),
            'username' => $this->env('DB_USERNAME', 'root'),
            'password' => $this->env('DB_PASSWORD', ''),
            'hostport' => $this->env('DB_PORT', 3306),
        ];
    }

    protected function initDb($connection = null)
    {
        $conn = $connection ?: $this->connection;
        $tableName = 'casbin_rule';
        $conn->query('DELETE FROM ' . $tableName);
        $conn->query('INSERT INTO ' . $tableName . ' (ptype, v0, v1, v2) VALUES (\'p\', \'alice\', \'data1\', \'read\')');
        $conn->query('INSERT INTO ' . $tableName . ' (ptype, v0, v1, v2) VALUES (\'p\', \'bob\', \'data2\', \'write\')');
        $conn->query('INSERT INTO ' . $tableName . ' (ptype, v0, v1, v2) VALUES (\'p\', \'data2_admin\', \'data2\', \'read\')');
        $conn->query('INSERT INTO ' . $tableName . ' (ptype, v0, v1, v2) VALUES (\'p\', \'data2_admin\', \'data2\', \'write\')');
        $conn->query('INSERT INTO ' . $tableName . ' (ptype, v0, v1) VALUES (\'g\', \'alice\', \'data2_admin\')');
    }

    protected function getEnforcer()
    {
        $this->adapter = DatabaseAdapter::newAdapter($this->connection);
        $this->initDb($this->connection);

        return new Enforcer(__DIR__ . '/rbac_model.conf', $this->adapter);
    }

    protected function getEnforcerWithAdapter(Adapter $adapter): Enforcer
    {
        $this->adapter = $adapter;
        $this->initDb($this->connection);
        $model = Model::newModelFromString(
            <<<'EOT'
[request_definition]
r = sub, obj, act

[policy_definition]
p = sub, obj, act

[role_definition]
g = _, _

[policy_effect]
e = some(where (p.eft == allow))

[matchers]
m = g(r.sub, p.sub) && r.obj == p.obj && r.act == p.act
EOT
        );
        return new Enforcer($model, $this->adapter);
    }

    // ... All test methods remain unchanged ...

    // (Copy all test methods from your original code here, unchanged)

    protected function env($key, $default = null)
    {
        $value = getenv($key);
        if (is_null($default)) {
            return $value;
        }
        return false === $value ? $default : $value;
    }
}
