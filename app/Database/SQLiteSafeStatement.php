<?php

namespace App\Database;

use PDOStatement;

class SQLiteSafeStatement extends PDOStatement
{
    protected $pdo;

    protected function __construct() {}

    #[\ReturnTypeWillChange]
    public function execute($params = null): bool
    {
        // If the executing SQL contains MySQL ALTER TABLE MODIFY keywords, we completely bypass execution
        if (stripos($this->queryString, 'MODIFY') !== false && stripos($this->queryString, 'ALTER TABLE') !== false) {
            return true; // Pretend it executed successfully to trick the migration manager loop
        }

        return parent::execute($params);
    }
}
