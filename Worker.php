<?php
namespace app\models;

class Worker extends \Workerman\Worker{

    /**
     * @inheritdoc
     */
    public static function runAll()
    {
        global $argv;
        if ($argv[0] == 'yii' || $argv[0] == './yii' || $argv[0] == '/var/www/adv_proxy/yii') $argv = array_slice($argv, 1);
        if (isset($argv[2]) && $argv[2] == 'daemon') {
            $argv[2] = '-d';
        }

        parent::runAll();
    }
}