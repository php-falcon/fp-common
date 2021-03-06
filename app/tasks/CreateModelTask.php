<?php
namespace PhalconPlus\DevTools\Tasks;

use Zend\Code\Generator\ClassGenerator;
use Zend\Code\Generator\DocBlockGenerator;
use Zend\Code\Generator\PropertyGenerator;
use Zend\Code\Generator\FileGenerator;
use Zend\Code\Generator\MethodGenerator;
use Zend\Code\Generator\DocBlock\Tag;
use Zend\Code\Reflection\ClassReflection;

use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local as LocalAdapter;

class CreateModelTask extends \Phalcon\CLI\Task
{
    public function mainAction()
    {
        $this->cli->info('现在开始引导您创建Phalcon+ ORM模型...');
        $that = $this;

        // ------ 获取模块的名字 -------
        $input = $this->cli->input("<green><bold>Step 1 </bold></green>请输入该模块的名称，如\"api\"" . PHP_EOL. "[Enter]:");
        $input->accept(function($response) use ($that){
            $filesystem = new Filesystem(new LocalAdapter(APP_ROOT_DIR));
            if (!$filesystem->has($response)) {
                $that->cli->backgroundRed("模块{$response}不存在，请更换名称再试！");
                return false;
            }
            return !empty($response);
        });
        $name = $input->prompt();

        // ------ 获取DB连接服务名 -------
        $input = $this->cli->input("<green><bold>Step 2 </bold></green>请输入DI容器中连接DB的服务名称，如'dbWrite'" . PHP_EOL. "[Enter]:");
        $input->accept(function($response) {
            return !empty($response);
        });
        $dbService = $input->prompt();

        $this->cli->br()->info('正在为您生成代码 ...');

        $this->generate($name, $dbService);
    }

    protected function generate($module, $dbService)
    {
        $bootstrap = $this->getDI()->get('bootstrap');

        // 依赖该模块
        $bootstrap->dependModule($module);

        $moduleConfig = $this->getDI()->getModuleConfig();

        $namespace = $moduleConfig->application->ns . 'Models';
        $modelDir = APP_ROOT_DIR . $module . "/app/models/";

        // 如果models目录不存在，则创建它
        if(!is_dir($modelDir)) {
            mkdir($modelDir, 0777, true);
        }

        $connection = $this->di->get($dbService);
        $tables = $connection->listTables();

        $padding = $this->cli->padding(26);

        foreach($tables as $table) {
            $className = \Phalcon\Text::camelize($table);
            $filePath = $modelDir. $className . $bootstrap::PHP_EXT;
            $fullClassName = $namespace . '\\' . $className;

            $padding->label("  " . $fullClassName)->result($filePath);

            if (class_exists($fullClassName)) {
                $cr = new ClassReflection(new $fullClassName);
                $generator = ClassGenerator::fromReflection($cr);
                $constants = $generator->getConstants();
                foreach ($constants as $key => $val) {
                    if ($cr->getParentClass()->hasConstant($key)) {
                        $generator->removeConstant($key);
                    }
                }
            } else {
                $generator = new ClassGenerator();
            }

            $docblock = DocBlockGenerator::fromArray(array(
                'shortDescription' => 'Phalcon Model: ' . $className,
                'longDescription'  => '此文件由代码自动生成，代码依赖PhalconPlus和Zend\Code\Generator',
                'tags'             => array(
                    array(
                        'name'        => 'namespace',
                        'description' => rtrim($namespace, "\\"),
                    ),
                    array(
                        'name'        => 'version',
                        'description' => '$Rev:'. date("Y-m-d H:i:s") .'$',
                    ),
                    array(
                        'name'        => 'license',
                        'description' => 'PhalconPlus( http://plus.phalconphp.org/license-1.0.html )',
                    ),
                ),
            ));

            $generator->setName($className)
                ->setDocblock($docblock)
                ->setExtendedClass("\\PhalconPlus\Base\Model");

            $columns = $connection->fetchAll("DESC $table", \Phalcon\Db::FETCH_ASSOC);
            $columnsDefaultMap = $this->getDefaultValuesMap($columns);

            $onConstructBody = "";
            $columnMapBody = "return array(\n";

            foreach($connection->describeColumns($table) as $columnObj) {
                $columnName = $columnObj->getName();
                $camelizeColumnName = lcfirst(\Phalcon\Text::camelize($columnName));
                $onConstructBody .= '$this->'.$camelizeColumnName
                                 . ' = ' . var_export($columnsDefaultMap[$columnName], true)
                                 . ";\n";
                $columnMapBody .= "    '{$columnName}' => '{$camelizeColumnName}', \n";
                $property = PropertyGenerator::fromArray(array(
                    'name' => $columnName,
                    'defaultvalue' => $columnsDefaultMap[$columnName],
                    'flags' => PropertyGenerator::FLAG_PUBLIC,
                    'docblock' => array(
                        'shortDescription' => '',
                        'tags' => array(
                            array(
                                'name' => 'var',
                                'description' => $this->getTypeString($columnObj->getType()),
                            ),
                            array(
                                'name' => 'table',
                                'description' => $table,
                            ),
                        )
                    ),
                ));
                $generator->removeProperty($columnName);
                $generator->addPropertyFromGenerator($property);
            }

            $columnMapBody .= ");\n";

            $generator->hasMethod("onConstruct") && $generator->removeMethod("onConstruct");
            $generator->hasMethod("columnMap") && $generator->removeMethod("columnMap");
            $generator->hasMethod("getSource") && $generator->removeMethod("getSource");

            $generator->addMethod(
                    'onConstruct',
                    array(),
                    MethodGenerator::FLAG_PUBLIC,
                    $onConstructBody,
                    DocBlockGenerator::fromArray(array(
                        'shortDescription' => 'When an instance created, it would be executed',
                        'longDescription'  => null,

                    ))
            );

            $generator->addMethod(
                    'columnMap',
                    array(),
                    MethodGenerator::FLAG_PUBLIC,
                    $columnMapBody,
                    DocBlockGenerator::fromArray(array(
                        'shortDescription' => 'Column map for database fields and model properties',
                        'longDescription'  => null,
                    ))
                );


            if(!$generator->hasMethod("initialize")) {
                $generator->addMethod(
                    'initialize',
                    array(),
                    MethodGenerator::FLAG_PUBLIC,
                    'parent::initialize();' . "\n" . '$this->setConnectionService("'. $dbService .'");' . "\n"
                );
            }

            $generator->addMethod(
                'getSource',
                array(),
                MethodGenerator::FLAG_PUBLIC,
                "return '{$table}';\n",
                DocBlockGenerator::fromArray(array(
                    'shortDescription' => 'return related table name',
                    'longDescription'  => null,

                ))
            );

            $file = new FileGenerator();
            $file->setFilename($filePath);
            $file->setNamespace($namespace);
            $file->setClass($generator);
            $file->write();
        }

        $this->cli->br()->info("... 恭喜您，创建成功！");
    }

    private function getTypeString($type)
    {
        switch($type) {
        case \Phalcon\Db\Column::TYPE_BIGINTEGER:
        case \Phalcon\Db\Column::TYPE_INTEGER:
            return "integer";
        case \Phalcon\Db\Column::TYPE_DATE:
            return "date";
        case \Phalcon\Db\Column::TYPE_CHAR:
        case \Phalcon\Db\Column::TYPE_TEXT:
        case \Phalcon\Db\Column::TYPE_VARCHAR:
            return "string";
        case \Phalcon\Db\Column::TYPE_DATETIME:
            return "datetime";
        case \Phalcon\Db\Column::TYPE_FLOAT:
            // phalcon 2.0 not support this type
            // case \Phalcon\Db\Column::TYPE_DOUBLE:
        case \Phalcon\Db\Column::TYPE_DECIMAL:
            return "float";
        case \Phalcon\Db\Column::TYPE_BOOLEAN:
            return "bool";
        default:
            return "unknown";
        }
    }

    private function getDefaultValuesMap($columns)
    {
        $ret = array();
        foreach ($columns as $item) {
            if($item['Type'] == 'timestamp' && $item['Default'] == 'CURRENT_TIMESTAMP') {
                $item['Default'] = '1800-01-01 00:00:00';
            }
            $ret[$item['Field']] = $item['Default'];
        }
        return $ret;
    }
}