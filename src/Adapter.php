<?php

namespace PhpCasbinMysqliAdapter\Database;

use Casbin\Model\Model;
use Casbin\Persist\Adapter as AdapterContract;
use Casbin\Persist\AdapterHelper;
use Casbin\Persist\FilteredAdapter as FilteredAdapterContract;
use Casbin\Persist\Adapters\Filter;
use Casbin\Exceptions\InvalidFilterTypeException;
use Casbin\Persist\BatchAdapter as BatchAdapterContract;
use Casbin\Persist\UpdatableAdapter as UpdatableAdapterContract;
use Closure;
use Throwable;

/**
 * DatabaseAdapter.
 *
 * @author techlee@qq.com
 */
class Adapter implements AdapterContract, FilteredAdapterContract, BatchAdapterContract, UpdatableAdapterContract
{
    use AdapterHelper;

    protected $filtered;

    protected \mysqli $connection;

    public $policyTableName = 'casbin_rule';

    public $rows = [];

    public function __construct(\mysqli $connection, $policy_table_name = 'casbin_rule')
    {
        if ($connection->connect_error) {
            throw new \Exception('MySQLi connection failed: ' . $connection->connect_error);
        }
        $this->connection = $connection;
        $this->filtered = false;
        $this->policyTableName = $policy_table_name;
        $this->initTable();
    }

    /**
     * Returns true if the loaded policy has been filtered.
     *
     * @return bool
     */
    public function isFiltered(): bool
    {
        return $this->filtered;
    }

    /**
     * Sets filtered parameter.
     *
     * @param bool $filtered
     */
    public function setFiltered(bool $filtered): void
    {
        $this->filtered = $filtered;
    }

    /**
     * Filter the rule.
     *
     * @param array $rule
     * @return array
     */
    public function filterRule(array $rule): array
    {
        $rule = array_values($rule);

        $i = count($rule) - 1;
        for (; $i >= 0; $i--) {
            if ($rule[$i] != '' && $rule[$i] !== null) {
                break;
            }
        }

        return array_slice($rule, 0, $i + 1);
    }

    public static function newAdapter(\mysqli $connection, string $policy_table_name = 'casbin_rule'): static
    {
        return new static($connection, $policy_table_name);
    }

    public function initTable()
    {
        $sql = file_get_contents(__DIR__ . '/../migrations/mysql.sql');
        $sql = str_replace('%table_name%', $this->policyTableName, $sql);
        $this->connection->query($sql);
    }

    public function savePolicyLine($ptype, array $rule)
    {
        $col['ptype'] = $ptype;
        foreach ($rule as $key => $value) {
            $col['v' . strval($key) . ''] = $value;
        }

        $colStr = implode(', ', array_keys($col));

        $name = rtrim(str_repeat('?, ', count($col)), ', ');

        $sql = 'INSERT INTO ' . $this->policyTableName . '(' . $colStr . ') VALUES (' . $name . ') ';

        $stmt = $this->connection->prepare($sql);
        if (!$stmt) {
            throw new \Exception('Failed to prepare statement: ' . $this->connection->error);
        }

        $values = array_values($col);
        $stmt->bind_param(str_repeat('s', count($col)), ...$values);

        $stmt->execute();

        if ($stmt->error) {
            throw new \Exception('Failed to execute statement: ' . $stmt->error);
        }

        $stmt->close();
    }

    /**
     * loads all policy rules from the storage.
     *
     * @param Model $model
     */
    public function loadPolicy(Model $model): void
    {
        $result = $this->connection->query('SELECT ptype, v0, v1, v2, v3, v4, v5 FROM ' . $this->policyTableName);
        if (!$result) {
            throw new \Exception('Failed to execute query: ' . $this->connection->error);
        }
        while ($row = $result->fetch_assoc()) {
            $this->loadPolicyArray($this->filterRule($row), $model);
        }
        $result->free();
    }

    /**
     * saves all policy rules to the storage.
     *
     * @param Model $model
     */
    public function savePolicy(Model $model): void
    {
        if (!$this->connection->begin_transaction()) {
            throw new \Exception('Failed to start transaction: ' . $this->connection->error);
        }
        try {
            foreach ($model['p'] as $ptype => $ast) {
                foreach ($ast->policy as $rule) {
                    $this->savePolicyLine($ptype, $rule);
                }
            }
            foreach ($model['g'] as $ptype => $ast) {
                foreach ($ast->policy as $rule) {
                    $this->savePolicyLine($ptype, $rule);
                }
            }
            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollback();
            throw $e;
        }
    }

    /**
     * adds a policy rule to the storage.
     * This is part of the Auto-Save feature.
     *
     * @param string $sec
     * @param string $ptype
     * @param array  $rule
     */
    public function addPolicy(string $sec, string $ptype, array $rule): void
    {
        $this->savePolicyLine($ptype, $rule);
    }

    public function addPolicies(string $sec, string $ptype, array $rules): void
    {
        $table = $this->policyTableName;
        $columns = ['ptype', 'v0', 'v1', 'v2', 'v3', 'v4', 'v5'];
        $values = [];
        $sets = [];
        $columnsCount = count($columns);
        foreach ($rules as $rule) {
            array_unshift($rule, $ptype);
            $values = array_merge($values, array_pad($rule, $columnsCount, null));
            $sets[] = array_pad([], $columnsCount, '?');
        }
        $valuesStr = implode(', ', array_map(function ($set) {
            return '(' . implode(', ', $set) . ')';
        }, $sets));
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ')' .
            ' VALUES' . $valuesStr;

        $stmt = $this->connection->prepare($sql);
        if (!$stmt) {
            throw new \Exception('Failed to prepare statement: ' . $this->connection->error);
        }
        $types = str_repeat('s', count($values));
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        if ($stmt->error) {
            throw new \Exception('Failed to execute statement: ' . $stmt->error);
        }
        $stmt->close();
    }

    public function removePolicies(string $sec, string $ptype, array $rules): void
    {
        if (!$this->connection->begin_transaction()) {
            throw new \Exception('Failed to start transaction: ' . $this->connection->error);
        }
        try {
            foreach ($rules as $rule) {
                $this->removePolicy($sec, $ptype, $rule);
            }
            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollback();
            throw $e;
        }
    }

    /**
     * This is part of the Auto-Save feature.
     *
     * @param string $sec
     * @param string $ptype
     * @param array  $rule
     */
    public function removePolicy(string $sec, string $ptype, array $rule): void
    {
        $where = ['ptype' => $ptype];
        $condition = ['ptype = ?'];
        $types = 's'; // ptype is a string
        $values = [$ptype];

        foreach ($rule as $key => $value) {
            $where['v' . $key] = $value;
            $condition[] = 'v' . $key . ' = ?';
            $types .= 's'; // Assuming all rule values are strings
            $values[] = $value;
        }

        $sql = 'DELETE FROM ' . $this->policyTableName . ' WHERE ' . implode(' AND ', $condition);

        $stmt = $this->connection->prepare($sql);
        if (!$stmt) {
            throw new \Exception('Failed to prepare statement: ' . $this->connection->error);
        }

        $stmt->bind_param($types, ...$values);

        if (!$stmt->execute()) {
            throw new \Exception('Failed to execute statement: ' . $stmt->error);
        }

        $stmt->close();
    }

    /**
     * @param string $sec
     * @param string $ptype
     * @param int $fieldIndex
     * @param string|null ...$fieldValues
     * @return array
     * @throws Throwable
     */
    public function _removeFilteredPolicy(string $sec, string $ptype, int $fieldIndex, ?string ...$fieldValues): array
    {
        $removedRules = [];
        $where = ['ptype' => $ptype];
        $condition = ['ptype = ?'];
        $types = 's';
        $values = [$ptype];

        foreach (range(0, 5) as $value) {
            if ($fieldIndex <= $value && $value < $fieldIndex + count($fieldValues)) {
                if ($fieldValues[$value - $fieldIndex] !== '') {
                    $where['v' . $value] = $fieldValues[$value - $fieldIndex];
                    $condition[] = 'v' . $value . ' = ?';
                    $types .= 's';
                    $values[] = $fieldValues[$value - $fieldIndex];
                }
            }
        }

        // Select old policies
        $selectSql = "SELECT ptype, v0, v1, v2, v3, v4, v5 FROM {$this->policyTableName} WHERE " . implode(' AND ', $condition);
        $stmt = $this->connection->prepare($selectSql);
        if (!$stmt) {
            throw new \Exception('Failed to prepare select statement: ' . $this->connection->error);
        }

        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            unset($row['ptype']);
            unset($row['id']);
            $removedRules[] = $this->filterRule($row);
        }
        $stmt->close();

        // Delete policies
        $deleteSql = "DELETE FROM {$this->policyTableName} WHERE " . implode(' AND ', $condition);
        $stmt = $this->connection->prepare($deleteSql);
        if (!$stmt) {
            throw new \Exception('Failed to prepare delete statement: ' . $this->connection->error);
        }

        $stmt->bind_param($types, ...$values);
        if (!$stmt->execute()) {
            throw new \Exception('Failed to execute delete statement: ' . $stmt->error);
        }
        $stmt->close();

        return $removedRules;
    }

    /**
     * RemoveFilteredPolicy removes policy rules that match the filter from the storage.
     * This is part of the Auto-Save feature.
     *
     * @param string $sec
     * @param string $ptype
     * @param int $fieldIndex
     * @param string ...$fieldValues
     * @throws Throwable
     */
    public function removeFilteredPolicy(string $sec, string $ptype, int $fieldIndex, string ...$fieldValues): void
    {
        $this->_removeFilteredPolicy($sec, $ptype, $fieldIndex, ...$fieldValues);
    }

    /**
     * Loads only policy rules that match the filter from storage.
     *
     * @param Model $model
     * @param mixed $filter
     *
     * @throws Throwable
     */
    public function loadFilteredPolicy(Model $model, $filter): void
    {
        $sql = 'SELECT ptype, v0, v1, v2, v3, v4, v5 FROM ' . $this->policyTableName . ' WHERE ';
        $types = '';
        $values = [];

        if (is_string($filter)) {
            $filter = str_replace(' ', '', $filter);
            $filter = str_replace('\'', '', $filter);
            $filter = explode('=', $filter);
            $sql .= "$filter[0] = ?";
            $types = 's';
            $values[] = $filter[1];
        } elseif ($filter instanceof Filter) {
            $conditions = [];
            foreach ($filter->p as $k => $v) {
                $conditions[] = $v . ' = ?';
                $types .= 's';
                $values[] = $filter->g[$k];
            }
            $sql .= implode(' AND ', $conditions);
        } elseif ($filter instanceof Closure) {
            $where = '';
            $filter($where);
            $where = str_replace(' ', '', $where);
            $where = str_replace('\'', '', $where);
            $where = explode('=', $where);
            $sql .= "$where[0] = ?";
            $types .= 's';
            $values[] = $where[1];
        } else {
            throw new InvalidFilterTypeException('invalid filter type');
        }

        $stmt = $this->connection->prepare($sql);
        if (!$stmt) {
            throw new \Exception('Failed to prepare statement: ' . $this->connection->error);
        }

        if (!empty($values)) {
            $stmt->bind_param($types, ...$values);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $row = array_filter($row, function ($value) {
                return !is_null($value) && $value !== '';
            });
            unset($row['id']);
            $line = implode(', ', array_filter($row, function ($val) {
                return '' != $val && !is_null($val);
            }));
            $this->loadPolicyLine(trim($line), $model);
        }

        $stmt->close();
        $this->setFiltered(true);
    }

    /**
     * Updates a policy rule from storage.
     * This is part of the Auto-Save feature.
     *
     * @param string $sec
     * @param string $ptype
     * @param string[] $oldRule
     * @param string[] $newPolicy
     */
    public function updatePolicy(string $sec, string $ptype, array $oldRule, array $newPolicy): void
    {
        $where = ['ptype' => $ptype];
        $condition = ['ptype = ?'];
        $types = 's';
        $values = [$ptype];

        foreach ($oldRule as $key => $value) {
            $where['w' . $key] = $value;
            $condition[] = 'v' . $key . ' = ?';
            $types .= 's';
            $values[] = $value;
        }

        $update = [];
        $updateValues = [];
        foreach ($newPolicy as $key => $value) {
            $update[] = 'v' . $key . ' = ?';
            $types .= 's';
            $updateValues[] = $value;
        }

        $sql = "UPDATE {$this->policyTableName} SET " . implode(', ', $update) . " WHERE " . implode(' AND ', $condition);

        $stmt = $this->connection->prepare($sql);
        if (!$stmt) {
            throw new \Exception('Failed to prepare statement: ' . $this->connection->error);
        }

        $stmt->bind_param($types, ...array_merge($updateValues, $values));

        if (!$stmt->execute()) {
            throw new \Exception('Failed to execute statement: ' . $stmt->error);
        }

        $stmt->close();
    }

    /**
     * UpdatePolicies updates some policy rules to storage, like db, redis.
     *
     * @param string $sec
     * @param string $ptype
     * @param string[][] $oldRules
     * @param string[][] $newRules
     * @return void
     */
    public function updatePolicies(string $sec, string $ptype, array $oldRules, array $newRules): void
    {
        if (!$this->connection->begin_transaction()) {
            throw new \Exception('Failed to start transaction: ' . $this->connection->error);
        }
        try {
            foreach ($oldRules as $i => $oldRule) {
                $this->updatePolicy($sec, $ptype, $oldRule, $newRules[$i]);
            }
            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollback();
            throw $e;
        }
    }

    /**
     * @param string $sec
     * @param string $ptype
     * @param array $newRules
     * @param int $fieldIndex
     * @param string ...$fieldValues
     * @return array
     * @throws Throwable
     */
    public function updateFilteredPolicies(string $sec, string $ptype, array $newRules, int $fieldIndex, ?string ...$fieldValues): array
    {
        $oldRules = [];
        if (!$this->connection->begin_transaction()) {
            throw new \Exception('Failed to start transaction: ' . $this->connection->error);
        }
        try {
            $oldRules = $this->_removeFilteredPolicy($sec, $ptype, $fieldIndex, ...$fieldValues);
            $this->addPolicies($sec, $ptype, $newRules);
            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollback();
            throw $e;
        }

        return $oldRules;
    }
}
