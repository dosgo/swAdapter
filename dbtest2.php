<?php
require_once __DIR__ . '/vendor/autoload.php';

use React\Promise;
use React\MySQL\Connection;
use React\MySQL\Factory;
use function React\Promise\resolve;

class AsyncMySQL
{
    private $connection;

    public function __construct($host, $port, $user, $password, $dbname)
    {
        $factory = new Factory($host, $port, $user, $password);
        $factory->connect($dbname)->then(function (Connection $connection) {
            $this->connection = $connection;
        }, function (\Exception $e) {
            throw $e;
        });
    }

    /**
     * 执行查询并返回结果。
     *
     * @param string $sql SQL 语句
     * @param array $params 参数列表
     * @return array 查询结果
     */
    public function querySync($sql, $params = [])
    {
        return resolve()->then(function () use ($sql, $params) {
            return $this->connection->query($sql, $params)
                ->then(function ($result) {
                    return $result;
                });
        })->done(function ($result) {
            return $result;
        }, function ($error) {
            throw $error;
        });
    }

    public function close()
    {
        $this->connection->close();
    }
}

// 使用示例
$mysql = new AsyncMySQL('127.0.0.1', 3306, 'root', 'your_password', 'test_db');

// 执行查询并获取结果
$results = $mysql->querySync("SELECT * FROM users WHERE id = ?", [1]);

foreach ($results as $row) {
    echo "Row: " . json_encode($row) . "\n";
}

echo "All done.\n";
