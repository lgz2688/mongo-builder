# mongo-builder

一个链式调用的 MongoDB 构造器，提供类 MySQL 的 API，支持可插拔的自增 ID 生成和可选的关系型数据库双写。

## 安装

```bash
composer require lgz2688/mongo-builder
```

## 基本用法

```php
// 查询
$row  = $mongo->table('users')->where(['id' => 1])->find();
$list = $mongo->table('users')->where([['age', '>', 18]])->order(['id' => 'desc'])->limit(10)->select();

// 写入
$id = $mongo->table('users')->insert(['name' => 'Tom', 'age' => 20]);
```