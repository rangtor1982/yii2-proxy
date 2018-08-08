<?php
namespace app\commands;

date_default_timezone_set('UTC');
error_reporting(E_ALL);

use yii\console\Controller;
use app\models\Proxy;
use Yii;

class TestController extends Controller
{
//    public $proxy_data;
    /**
     * @property array $proxy_data
     * 
     */
    public function actionProxy() {
        $proxy = new Proxy();
        $proxy::$proxy_data = [];
        $proxy->workersInit();
//        $proxy->actionProxy();
    }
}