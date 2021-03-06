namespace {{rootNs}}\{{moduleName}};

use PhalconPlus\Base\AbstractModule as PlusModule;

class Srv extends PlusModule
{
    public function registerAutoloaders()
    {
        $loader = new \Phalcon\Loader();
        $loader->registerNamespaces(array(
            __NAMESPACE__.'\\Services' => __DIR__.'/services/',
            __NAMESPACE__.'\\Models'   => __DIR__.'/models/',
            __NAMESPACE__.'\\Tasks'    => __DIR__.'/tasks/tasks/',
            "Common\\Protos"           => APP_ROOT_COMMON_DIR.'/protos/',
        ))->register();
    }

    public function registerServices()
    {
        // get di
        $di = $this->di;
        // get bootstrap obj
        $bootstrap = $di->get('bootstrap');
        // get config
        $config = $di->get('config');

        // register db service
        $di->setShared('db', function() use ($di) {
            $mysql = new \PhalconPlus\Db\Mysql($di, "db");
            return $mysql->getConnection();
        });
        // for tasks
        $di->set('dispatcher', function() {
            $dispatcher = new \Phalcon\Cli\Dispatcher();
            $dispatcher->setDefaultNamespace(__NAMESPACE__."\\Tasks\\");
            $dispatcher->setDefaultTask("hello");
            return $dispatcher;
        });
    }
}
