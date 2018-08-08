<?php
namespace app\models;

use yii\base\Model;
use app\models\Worker;
use app\models\Apps;
use app\models\Clients;
use app\models\ClientsData;
use app\models\ConIdToApp;
use Workerman\Lib\Timer;
use Channel\Server;
use Channel\Client;
use Yii;

class Proxy extends Model
{
    const APP_STATUS = 100;
    const APP_STATUS_RESPONSE = 110;
    const APP_REQUEST = 200;
    const APP_REQUEST_RESPONSE = 210;
    const CLIENT_CLOSE = 300;
    const APP_ERROR = 500;
    const SEND_TO_APP = 'send_to_app';
    const CON_TO_APP = 'conToApp';
    const CLIENT_DATA = 'clientData';
    const APP_DATA = 'appData';
    public static $proxy_data;
    /**
     * 
     * 
     */
    
    public function serviceWorker() {
        $service_worker = new Worker('websocket://0.0.0.0:8002');
        $service_worker->name = 'service';
        $service_worker->user = 'www-data';
        $service_worker->group = 'www-data';
        $service_worker->reusePort - true;
        $service_worker->onWorkerStart = function($service_worker){
            Apps::updateAll(['status' => 0]);
            Clients::updateAll(['connections' => 0]);
            ConIdToApp::deleteAll();
            Client::connect('127.0.0.1', 2206);
            Client::on(self::CON_TO_APP, function($event_data){
                $this->conToApp($event_data[self::CON_TO_APP],$event_data['closed']);
            });
            Client::on(self::CLIENT_DATA, function($event_data){
                 $this->clientData($event_data[self::CLIENT_DATA],$event_data['closed']);
            });
            Client::on(self::APP_DATA, function($event_data){
                 $this->appData($event_data[self::APP_DATA],$event_data['closed']);
            });
        };
        $service_worker->onConnect = function($connection){
            $connection->send(self::get_sysinfo());
            $connection->send(self::getStatus());
            $connection->send(json_encode([
                'event' => 'proxy_data',
                'proxy_data' => $this->getData()
            ]));
            self::$proxy_data['service'][$connection->id]['timer'] = Timer::add(15, 
                function()use($connection)
                {
                   $connection->send(self::get_sysinfo());
                   $connection->send(self::getStatus());
                   $connection->send(json_encode([
                        'event' => 'proxy_data',
                        'proxy_data' => $this->getData()
                   ]));
                }
            );
        };
        $service_worker->onClose = function($connection){
            if(isset(self::$proxy_data['service'][$connection->id]['timer']))Timer::del(self::$proxy_data['service'][$connection->id]['timer']);
        };
    }
    public function wsWorker() {
        $ws_worker = new Worker("tcp://0.0.0.0:8001");
        $ws_worker->reusePort = true;
        $ws_worker->count = 4;
        $ws_worker->name = 'client';
        $ws_worker->user = 'www-data';
        $ws_worker->group = 'www-data';
        $ws_worker->onWorkerStart = function($ws_worker)
        {
            // Channel client.
            Client::connect('127.0.0.1', 2206);
            $event_name = $ws_worker->id;

            Client::on($event_name, function($event_data)use($ws_worker){
                $to_connection_id = $event_data['to_connection_id'];
                $message = $event_data['content'];
                if(!isset($ws_worker->connections[$to_connection_id]))
                {
                    $message = [
                        'client' => [
                            'ip' => $event_data['ip'],
                            'con_id' => $to_connection_id,
                            'worker_id' => $ws_worker->id,
                            'c' => true
                        ],
                        'event' => self::CLIENT_CLOSE
                    ];
                    $message = json_encode($message)."\r\n\r\n";
//                    self::echo_line(__LINE__, "onStart - $message\r\n");
                    Client::publish(self::SEND_TO_APP, [
                        'content' => $message,
                        'ip' => $event_data['ip'],
                    ]);
                    return;
                }
                $to_connection = $ws_worker->connections[$to_connection_id];
                $is_sent = $to_connection->send($message);
                if(isset($event_data['close']) && $event_data['close'] == true) {
                    $to_connection->close();
                    Client::publish(self::CON_TO_APP, [
                        self::CON_TO_APP => [
                            'worker_id' => $ws_worker->id,
                            'con_id' => $to_connection_id
                        ],
                        'closed' => true,
                    ]);
                }
            });
        };

        $ws_worker->onConnect = function($connection)use($ws_worker)
        {
            $connection->maxSendBufferSize = 5*1024*1024;
            Client::publish(self::CLIENT_DATA, [
                self::CLIENT_DATA => [
                    'client_ip' => $connection->getRemoteIp(),
                    'worker_id' => $ws_worker->id,
                    'connections' => count($ws_worker->connections)
                ],
                'closed' => false,
            ]);
//            self::clientData([
//                'client_ip' => $connection->getRemoteIp(),
//                'worker_id' => $ws_worker->id,
//                'connections' => count($ws_worker->connections)
//            ]);
        };
        $ws_worker->onClose = function($connection)use($ws_worker)
        {
            $message = [
                'client' => [
                    'ip' => $connection->getRemoteIp(),
                    'con_id' => $connection->id,
                    'worker_id' => $ws_worker->id,
                    'c' => true
                ],
                'event' => self::CLIENT_CLOSE
            ];
            $message = json_encode($message)."\r\n\r\n";
//            self::echo_line(__LINE__, "client close - worker {$ws_worker->id}; con {$connection->id}");
            Client::publish(self::SEND_TO_APP, [
                'content'          => $message,
                'ip' => $connection->getRemoteIp(),
            ]);
            Client::publish(self::CLIENT_DATA, [
                self::CLIENT_DATA => [
                    'client_ip' => $connection->getRemoteIp(),
                    'worker_id' => $ws_worker->id,
                    'connections' => count($ws_worker->connections)
                ],
                'closed' => false,
            ]);
//            self::clientData([
//                'client_ip' => $connection->getRemoteIp(),
//                'worker_id' => $ws_worker->id,
//                'connections' => count($ws_worker->connections)
//            ]);
            Client::publish(self::CON_TO_APP, [
                self::CON_TO_APP => [
                    'worker_id' => $ws_worker->id,
                    'con_id' => $connection->id
                ],
                'closed' => true,
            ]);
        };
        $ws_worker->onError = function($connection, $code, $msg)use($ws_worker)
        {
            self::echo_line(__LINE__,"error $code $msg Client Connection ".$connection->id." worker id: ".$ws_worker->id);
        };
        $ws_worker->onBufferFull = function($connection){
            $connection->pauseRecv();
            Timer::add(1, function($connection){
                $connection->resumeRecv();
            }, array($connection), false);
        };
        $ws_worker->onMessage = function($connection, $data)use($ws_worker) {
            $cl_ip = $connection->getRemoteIp();
            $message = '{"event":'.self::APP_REQUEST.','
                    . '"client":'
                    . '{'
                    . '"ip":"'.$cl_ip.'",'
                    . '"con_id":"'.$connection->id.'",'
                    . '"worker_id":"'.$ws_worker->id.'",'
                    . '"c":false'
                    . '},'
                    . '"header":"'.base64_encode($data).'"}'."\r\n\r\n";
            $send_arr = [
                    'content'          => $message,
                    'ip' => $cl_ip,
                    'worker_id' => $ws_worker->id,
                    'con_id' => $connection->id
                ];
//            self::echo_line(__LINE__, "client app_id - ". self::conToApp(['worker_id' => $ws_worker->id, 'con_id' => $connection->id]));
//            self::echo_line(__LINE__, "send_to_app - ". json_encode($send_arr));
            $get_app = [
                'worker_id' => $ws_worker->id,
                'con_id' => $connection->id,
                'client_ip' => $cl_ip,
            ];
            $pattern = '/connect|get|post/ui';
            if(preg_match($pattern, $data)) {
                $re = '/host:\s*(.*)/uim';
                preg_match($re, $data, $matches);
                if(isset($matches[1])){
                    $host = str_replace(["\r\n", "\n", "\r"], '', $matches[1]);
//                    self::echo_line(__LINE__, "app request host - ". $host);
                    $send_arr['host'] = $get_app['dest_host'] = $host;
                }
            } else {
//                self::echo_line(__LINE__, "No host requested");
            }
//            self::echo_line(__LINE__, " get app id");
            if($app_id = $this->conToApp($get_app)){
                $send_arr['to_connection_id'] = $app_id;
//                self::echo_line(__LINE__, "client app_id - $app_id");
            }
//            self::echo_line(__LINE__, "send_to_app - ". json_encode($send_arr));
            Client::publish(self::SEND_TO_APP, $send_arr);
        };
    }
    public function tcpWorker() {
        $timer_arr = [];
        $inner_tcp_worker = new Worker("websocket://0.0.0.0:8003");
        $inner_tcp_worker->reusePort = true;
        $inner_tcp_worker->name = 'app';
        $inner_tcp_worker->user = 'www-data';
        $inner_tcp_worker->group = 'www-data';
        $inner_tcp_worker->onWorkerStart = function($inner_tcp_worker)
        {
            Client::connect('127.0.0.1', 2206);
            $event_name = self::SEND_TO_APP;
            
            Client::on($event_name, function($event_data)use($inner_tcp_worker){
                $message = $event_data['content'];
//                self::echo_line(__LINE__, "client app_id - $message\r\n");
                $response = json_decode($message);
                $sent = false;
                if(isset($event_data['to_connection_id']) && isset($inner_tcp_worker->connections[$event_data['to_connection_id']])) {
                    $sent = ($inner_tcp_worker->connections[$event_data['to_connection_id']]->send($message))?true:false;
//                    self::echo_line(__LINE__, "app id set - ". ($event_data['to_connection_id']));
//                    self::echo_line(__LINE__, "is sent - $sent");
//                    if(!$sent){
//                        Client::publish(self::CON_TO_APP, [
//                            self::CON_TO_APP => [
//                                'worker_id' => $response->client->worker_id,
//                                'con_id' => $response->client->con_id
//                            ],
//                            'closed' => true,
//                        ]);
//                    }
//                    self::echo_line(__LINE__, "client app_id - {$event_data['to_connection_id']}");
                }
                $apps_connected = count($inner_tcp_worker->connections);
//                self::echo_line(__LINE__, "client app_id - $apps_connected");
                $apps_con_list = $this->getApps();
                $i = 0;
                while(!$sent && $apps_connected>0){
//                    self::echo_line(__LINE__, "app id check - ".(isset($event_data['to_connection_id'])?'yes':'no'));
                    if(isset($apps_con_list[$i]) && isset($inner_tcp_worker->connections[$apps_con_list[$i]['connection_id']]))
                        {
                            $index = $apps_con_list[$i++]['connection_id'];
                        } else break;
                    if(isset($inner_tcp_worker->connections[$index])){
//                        $index = array_rand($inner_tcp_worker->connections);
                        $status = $inner_tcp_worker->connections[$index]->send($message);
                        if($status){
                            $sent = true;
//                            self::echo_line(__LINE__, "app request host - ". json_encode($event_data));
                            if(!$response->client->c){
                                Client::publish(self::CON_TO_APP, [
                                    self::CON_TO_APP => [
                                        'worker_id' => $response->client->worker_id,
                                        'con_id' => $response->client->con_id,
                                        'client_ip' => $event_data['ip'],
                                        'dest_host' => isset($event_data['host'])?$event_data['host']:'',
                                        'app_id' => $index
                                    ],
                                    'closed' => false,
                                ]);
                            }
                            break;
                        } else {
                            $inner_tcp_worker->connections[$index]->close();
                            $apps_connected--;
                        }
                    }
                }
                if(!$sent && $apps_connected < 1 && !$response->client->c){
                    Client::publish($response->client->worker_id, array(
                        'content'          => "HTTP/1.1 500 \r\n\r\nConnection broken",
                        'to_connection_id' => $response->client->con_id,
                        'ip'               => $event_data['ip'],
                        'close'            => true
                    ));
                }
            });
        };
        $inner_tcp_worker->onConnect = function($connection)use(&$timer_arr)
        {
            $connection->send(json_encode([
                'event' => self::APP_STATUS,
                'time' => microtime()
            ]));
            $timer_arr[$connection->id] = Timer::add(10, 
                function($connection){
                   $connection->send(json_encode([
                        'event' => self::APP_STATUS,
                        'time' => microtime()
                    ]));
                },
                [$connection]
            );
//            self::$proxy_data['apps'][$connection->getRemoteIp()] = $connection->id;
//            $this->setData('apps',$connection->getRemoteIp(),$connection->id);
             self::echo_line(__LINE__," line: app connected id: {$connection->id}; ip - {$connection->getRemoteIp()}");
        };
        $inner_tcp_worker->onMessage = function($connection, $data)
        {
                $block_size = $connection->getRecvBufferQueueSize();
//                self::echo_line(__LINE__, "app response - $data");
//                self::echo_line(__LINE__, "app proxy_data - ". json_encode(self::$proxy_data));
                if($response = json_decode($data)){
                    if(!property_exists($response,'event') || $response->event == self::APP_REQUEST_RESPONSE){
                        if(property_exists($response,'in')){
                            if($response->in->ih){
                                Client::publish($response->cl->worker_id, array(
                                    'content'          => base64_decode(str_replace(["\r\n", "\n", "\r"],"",$response->re->he)),
                                    'to_connection_id' => $response->cl->con_id,
                                    'ip'               => $response->cl->ip,
                                ));
                            } else {
                                Client::publish($response->cl->worker_id, [
                                    'content'          => '',
                                    'to_connection_id' => $response->cl->con_id,
                                    'ip'               => $response->cl->ip,
                                    'close'            => isset($response->in->il)?$response->in->il:false
                                ]);
                            }
                        }
                    } else {
                        switch ($response->event){
                            case self::APP_STATUS_RESPONSE:
//                                self::echo_line(__LINE__, "app response - ". json_encode($response));
                                Client::publish(self::APP_DATA, [
                                    self::APP_DATA => [
                                        'dev_id' => $response->data->dev_id,
                                        'dev_name' => $response->data->dev_name,
                                        'current_ip' => $connection->getRemoteIp(),
                                        'requests' => $response->data->requests,
                                        'wifi' => isset($response->data->wifi)?(($response->data->wifi)?1:0):0,
                                        'mobile' => isset($response->data->mobile)?(($response->data->mobile)?1:0):0,
                                        'connection_id' => $connection->id,
                                        'latency' => self::microtime_float($response),
                                        'status' => 1,
                                        "is_idle" => isset($response->data->is_idle)?(($response->data->is_idle)?1:0):0,
                                        "battery_level" => isset($response->data->battery_level)?(int)$response->data->battery_level:0,
                                        "battery_charging" => isset($response->data->battery_charging)?(($response->data->battery_charging)?1:0):0,
                                        "connected_to_usb" => isset($response->data->connected_to_usb)?(($response->data->connected_to_usb)?1:0):0,
                                        "connected_to_ac" => isset($response->data->connected_to_ac)?(($response->data->connected_to_ac)?1:0):0,
                                        "cpu" => isset($response->data->cpu)?json_encode($response->data->cpu):"[]",
                                        "hardware" => isset($response->data->hardware)?$response->data->hardware:"",
                                        "cpu_model" => isset($response->data->cpu_model)?$response->data->cpu_model:""
                                    ],
                                    'closed' => false
                                ]);
                                break;
                            case self::APP_ERROR:
                                    Client::publish($response->cl->worker_id, array(
                                        'content'          => "HTTP/1.1 500 \r\n\r\nConnection broken",
                                        'to_connection_id' => $response->cl->con_id,
                                        'ip'               => $response->cl->ip,
                                        'close'            => true
                                    ));
                                break;
                        }
                    }
                } else {
                    echo date("Y-m-d H:i:s")." log in ".__LINE__." line - Error: Not json format\r\n";
                }
        };
        $inner_tcp_worker->onClose = function($connection)use(&$timer_arr)
        {
            Client::publish(self::APP_DATA, [
                self::APP_DATA => [
                    'current_ip' => $connection->getRemoteIp(),
                    'connection_id' => $connection->id,
                    'status' => 0
                ],
                'closed' => true
            ]);
            self::echo_line(__LINE__, "App Connection #".$connection->id." closed");
            if(isset($timer_arr[$connection->id])){
                Timer::del($timer_arr[$connection->id]);
                unset($timer_arr[$connection->id]);
            }
        };
        $inner_tcp_worker->onError = function($connection, $code, $msg)
        {
            echo date("Y-m-d H:i:s")." log in ".__LINE__." line app id ".$connection->id." error $code $msg\n";
        };
    }
    public function workersInit()
    {
        Worker::$stdoutFile = 'socket.log';
        Worker::$pidFile = Yii::$app->basePath.'/adv_proxy.pid';
        $channel_server = new Server('0.0.0.0', 2206);
        $this->serviceWorker();
        $this->wsWorker();
        $this->tcpWorker();
        Worker::runAll();
    }
    public static function echo_line($line,$data){
        try {
            echo date("Y-m-d H:i:s")." log in $line line: $data\r\n";
        } catch (Exception $ex) {
            self::echo_line(__LINE__, "errors - ". $ex->getMessage());
        }
    }
    public static function get_sysinfo(){
        $exec_loads = sys_getloadavg();
        $exec_cores = trim(shell_exec("grep -P '^processor' /proc/cpuinfo|wc -l"));
        $cpu = round($exec_loads[1]/($exec_cores+1)*100, 0) . '%';
        $exec_free = explode("\n", trim(shell_exec('free')));
        $get_mem = preg_split("/[\s]+/", $exec_free[1]);
        $mem = round($get_mem[2]/$get_mem[1]*100, 0) . '%';
        $exec_uptime = preg_split("/[\s]+/", trim(shell_exec('uptime')));
        $uptime = $exec_uptime[2] . ' Days';
        return json_encode([
            'event' => 'sys_info',
            'cpu' => $cpu,
            'mem' => $mem,
            'uptime' => $uptime
        ]);
    }
    public static function getStatus() {
        $file = Yii::$app->basePath."/test.log";
        $stat = 'No statistic';
        pclose(popen(Yii::$app->basePath."/start.sh status > ".$file, 'r'));
//        sleep(2);
        if(file_exists($file)){
            $stat = file_get_contents($file);
            $stat = nl2br($stat,false);
        } 
        return json_encode([
            'event' => 'proxy_stat',
            'stat' => $stat
        ]);
    }
    public function getData() {
        $clients = (new \yii\db\Query())
                ->select("COUNT(client_ip) clients,SUM(connections) connections")
                ->from("(SELECT client_ip,SUM(connections) connections FROM clients WHERE connections > 0 GROUP BY client_ip) clients_tb")
                ->one();
        $apps = (new \yii\db\Query())
                ->select("COUNT(dev_id) devices, SUM(requests) queue")
                ->from("apps")
                ->where("status = 1")
                ->one();
        $result = ['clients' => $clients, 'apps' => $apps];
        return $result;
    }
    public static function microtime_float($response)
    {
        list($usec, $sec) = explode(" ", $response->time);  
        list($usec_now, $sec_now) = explode(" ", microtime());
        $start = ((float)$usec + (float)$sec);
        $end = ((float)$usec_now + (float)$sec_now);
        return round(($end-$start)*1000);
    }
    public function clientData($data,$closed = false) {
        $client = Clients::find()
                ->where(['client_ip' => $data['client_ip'], 'worker_id' => $data['worker_id']])
                ->one();
        if($client && $closed){
            return $client->delete()?true:false;
        }
        if(!$client && !$closed) {
            $client = new Clients();
        }
        foreach ($data as $key => $value) {
            if($client->hasAttribute($key)) $client->$key = $value;
        }
        return $client->save()?true:false;
    }
    public function conToApp($data, $closed = false) {
//        self::echo_line(__LINE__, " get app id in func");
        $record = ConIdToApp::find()
                ->where(['worker_id' => $data['worker_id'],'con_id' => $data['con_id']])
                ->one();
//        self::echo_line(__LINE__, "app id");
        if($closed) {
            if($record) {
                $record->delete();
            }
            return;
        }
        if(!isset($data['app_id'])){
            if(!$record && isset($data['dest_host'])) {
                $record = ConIdToApp::find()
                        ->where(['client_ip' => $data['client_ip'],'dest_host' => $data['dest_host']])
                        ->one();
            }
//            self::echo_line(__LINE__, "app id - ". ($record ? $record->app_id : 'no id'));
            return $record ? $record->app_id : false;
        }
        if(!$record) $record = new ConIdToApp();
        foreach ($data as $key => $value) {
            if($record->hasAttribute($key)) $record->$key = $value;
//            $record->$key = $value;
        }
        try {
            $record->save();
        } catch (Exception $ex) {
            self::echo_line(__LINE__, "conToApp save errors - ". $ex->getMessage());
            return false;
        }
        return true;
    }
    public function getApps() {
        $result = Apps::find()
                ->select('connection_id')
                ->where("status = 1")
                ->orderBy("requests, latency")
                ->asArray()
                ->all();
        return $result? $result: false;
    }
    public function appData($data,$closed = false) {
//        self::echo_line(__LINE__, "app response - ". json_encode($data));
        if(!$closed){
            $app = Apps::find()
                    ->where(['dev_id' => $data['dev_id']])
                    ->one();
            if(!$app) {
                $app = new Apps();
            }
        } else {
            $app = Apps::find()
                    ->where(['current_ip' => $data['current_ip'],'connection_id' => $data['connection_id']])
                    ->one();
            $cleared = ConIdToApp::deleteAll("app_id = {$data['connection_id']}");
//            self::echo_line(__LINE__, "app_id clear - $cleared");
            if(!$app) {
                return false;
            }
        }
        $app_data = new ClientsData();
        
        foreach ($data as $key => $value) {
            if($app->hasAttribute($key)) $app->$key = $value;
            if($app_data->hasAttribute($key)) $app_data->$key = $value;
        }
        $app->status = (!$closed)?1:0;
        try {
            $app_data->save();
            $result = $app->save()?true:false;
        } catch (Exception $ex) {
            self::echo_line(__LINE__, "conToApp save errors - ". $ex->getMessage());
            return false;
        } 
//        self::echo_line(__LINE__, "app errors - ". json_encode($app->getErrors()));
//        self::echo_line(__LINE__, "app_data errors - ". json_encode($app_data->getErrors()));
        return $result;
    }

}