# 基于swoole4.6+的通用连接池


### 说明
> 基于swoole协程，需要在协程中使用，可以兼容laravel、lumen框架

### 使用方式


> 创建连接池

```php
<?php

\Swoole\Coroutine\run(function(){
    $pool = new \LinkPool\Database\PdoPool(function () {
        $dsn        = 'mysql:host=127.0.0.1;port=3306;dbname=demo;charset=utf8mb4';
        $options    = [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ];
        return new PDO($dsn,'root','root',$options);
    },64);
    
    // 初始化，且定时检测连接可用状态
    $pool -> init() -> handleSpare();
});
```

> 取一个连接

```php
<?php

    // 取出
    $pdo = $pool -> get(3);
    if($pdo instanceof PDO) {
        ...
    }

    // 用完记得归还
    $pool -> put($pdo);
```

> Laravel/Lumen中使用

> 由于框架中的连接是单例，需要将db改为非单例模式
```php
# Laravel 取消db单例模式同Lumen异同


# Lumen bootstrap/app.php
if(php_sapi_name() === 'cli' || app() -> environment() === 'local') {
    $app -> bind('db',function($app){
        return $app -> loadComponent(
            'database', ['App\Providers\CustomDatabaseServiceProvider'], 'db'
        );
    });
}


# App\Providers\CustomDatabaseServiceProvider
class CustomDatabaseServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        Model::setConnectionResolver($this->app['db']);

        Model::setEventDispatcher($this->app['events']);
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        Model::clearBootedModels();

        $this->registerConnectionServices();

        $this->registerEloquentFactory();

        $this->registerQueueableEntityResolver();
    }

    /**
     * Register the primary database bindings.
     *
     * @return void
     */
    protected function registerConnectionServices()
    {
        // The connection factory is used to create the actual connection instances on
        // the database. We will inject the factory into the manager so that it may
        // make the connections while they are actually needed and not of before.
        $this->app->bind('db.factory', function ($app) {
            return new ConnectionFactory($app);
        });

        // The database manager is used to resolve various connections, since multiple
        // connections might be managed. It also implements the connection resolver
        // interface which may be used by other components requiring connections.
        $this->app->bind('db', function ($app) {
            return new DatabaseManager($app, $app['db.factory']);
        });

        $this->app->bind('db.connection', function ($app) {
            return $app['db']->connection();
        });
    }

    /**
     * Register the Eloquent factory instance in the container.
     *
     * @return void
     */
    protected function registerEloquentFactory()
    {
        $this->app->singleton(FakerGenerator::class, function ($app) {
            return FakerFactory::create($app['config']->get('app.faker_locale', 'en_US'));
        });

        $this->app->singleton(EloquentFactory::class, function ($app) {
            return EloquentFactory::construct(
                $app->make(FakerGenerator::class), $this->app->databasePath('factories')
            );
        });
    }

    /**
     * Register the queueable entity resolver implementation.
     *
     * @return void
     */
    protected function registerQueueableEntityResolver()
    {
        $this->app->singleton(EntityResolver::class, function () {
            return new QueueEntityResolver;
        });
    }
}

``` 

> 具体使用
```php
<?php

// 
$pdo    = $pool -> get(3);
$db     = app('db');
$db -> setPdo($pdo);


// 用完记得归还
$pool -> put($db);
```
