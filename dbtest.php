<?php
require_once __DIR__ . '/vendor/autoload.php';

use Amp\MySQL\Driver;
use Amp\MySQL\Pool;
use Amp\MySQL\Result;
use Amp\Promise;
use function Amp\async;

class AsyncMySQL
{
    private $pool;

    public function __construct($host, $port, $user, $password, $dbname)
    {
        $this->pool = new Pool(new Driver(), [
            'host' => $host,
            'port' => $port,
            'user' => $user,
            'password' => $password,
            'dbname' => $dbname,
        ]);
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
        return async(function () use ($sql, $params) {
            $result = $this->pool->query($sql, $params)
                ->then(function (Result $result) {
                    return $result->fetchAll();
                });

            return $result;
        });
    }

    public function close()
    {
        $this->pool->close();
    }
}

// 使用示例
$mysql = new AsyncMySQL('127.0.0.1', 3306, 'root', 'your_password', 'test_db');

// 执行查询并获取结果
$results = $mysql->querySync("SELECT * FROM users WHERE id = ?", [1]);

// 确保所有的异步操作都完成
foreach ($results as $row) {
    echo "Row: " . json_encode($row) . "\n";
}

echo "All done.\n";
