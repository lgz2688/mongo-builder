# mongo-builder

一个链式调用的 MongoDB 构造器，提供类 MySQL 的 API，支持可插拔的自增 ID 生成和可选的关系型数据库双写。

## 安装

```bash
composer require lgz2688/mongo-builder
```

## 基本用法

```php
use Lgz2688\MongoBuilder\Config;
use Lgz2688\MongoBuilder\Mongo;

$config = Config::make(
uri: 'mongodb://127.0.0.1:27017',
database: 'your_db',
username: 'user',
password: 'pass',
authSource: 'admin',
);

$mongo = new Mongo($config);

// 查询
$row  = $mongo->table('users')->where(['id' => 1])->find();
$list = $mongo->table('users')->where([['age', '>', 18]])->order(['id' => 'desc'])->limit(10)->select();

// 写入（第二个参数为是否双写到关系库，默认由注入的 SyncWriter 决定）
$id = $mongo->table('users')->insert(['name' => 'Tom', 'age' => 20]);
```