<?php
error_reporting(E_ALL);
ob_implicit_flush();

$host = '127.0.0.1';
$port = '8888';
$socket = new SocketServer($host, $port);
$socket->run();
 
class SocketServer{
    protected $master;
    protected $users;  //用户信息
    protected $sockets;  //监听的socket

	public function __construct($host, $port){
		$this->master = $this->createSocket($host, $port);
		$this->sockets[] = $this->master;
	}

    public function run() {
        while(1){
            $sockets = $this->sockets;

            //多路选择，监听哪些socket有状态变化，返回时将有状态变化的保留在$sockets中，其他都删除之
            socket_select($sockets, $write=NULL, $except=NULL, NULL);
            foreach($sockets as $socket){
                //监听主机端口的socket有状态变化，说明有新用户
                if($socket == $this->master){
                    //创建新socket负责该用户通信
                    $client = socket_accept($this->master);
                    if ($client===false){
                        $error =  'socket_accept() failed:'.socket_strerror(socket_last_error());
                        echo $error;
                        $this->log($error);
                    }else{
                    	$str = "client info\t".serialize($client);
                        $this->log(__LINE__."\t".$str);
                        $uid = $this->generateId();
                        $this->sockets[] = $client;//加入用户列表
                        $this->users[] = array('socket'=>$client, 'isHandShake'=>false);
                    }
                }else{
                    $length = socket_recv($socket, $buffer, 2048, 0);
                    /*if($length===false){
                        $error = 'socket_recv() failed:'.socket_strerror(socket_last_error());
                        echo $error."\n";
                        $this->log($error."\n");
                        continue;
                    }*/

                    $index = $this->searchSocketFromUsers($socket);
                    $data = $this->decode($buffer);
                    $this->log($data);
                    var_dump($index);
                    $user = $this->users[$index];
                    if($length < 7){
                        $this->closeSocket($socket);
                    }elseif(!$user['isHandShake'] ||empty($data)){
                        $this->handshake($index, $buffer);//进行握手
                    }else{
                        
                        $data = json_decode($data, true);
                        if($data['type']==0){ //加入聊天
                            $this->addUser($index, $data);
                        }elseif($data['type']==1){ //聊天
                        	$str = "接收到的聊天数据：".json_encode($data, JSON_UNESCAPED_UNICODE);
                        	$this->log($str);
                        	$this->chat($socket, $data);
                        }
                    }

                }
            }
        }
    }

    private function chat($socket, $data){
    	$index = $this->searchSocketFromUsers($socket);
    	$response = array('type'=>$data['type'], 'msg'=>$data['msg'], 'fromUser'=>$this->users[$index]['user']);
    	$response = json_encode($response, JSON_UNESCAPED_UNICODE);

    	$this->log('返回的聊天数据：'.$response);

        $response = $this->encode($response);
        $userSocket = '';
        if($data['toUser']!='all'){
        	$userSocket = $this->users[$data['toUser']]['socket'];
        }
        $this->responseMsg($response, $userSocket);

    }

    private function addUser($index, $data){
        $this->users[$index]['user']['username'] = $data['username'];
        $this->users[$index]['user']['uid'] = $index;
        $response = array('type'=>0, 'users'=>$this->getusers(), 'addUser'=>$data['username'], 'uid'=>$index);
        $response = json_encode($response, JSON_UNESCAPED_UNICODE);
        $response = $this->encode($response);
        $this->responseMsg($response);
    }

    private function closeSocket($socket){
		$index = $this->searchSocketFromUsers($socket);
		$logoutUser = $this->users[$index]['user'];
		$response = array('type'=>2, 'user'=>$this->users[$index]['user']);
		$response = json_encode($response, JSON_UNESCAPED_UNICODE);
		$this->log('退出返回的信息logoutUser：'.json_encode($logoutUser, JSON_UNESCAPED_UNICODE));
		$this->log('退出返回的信息index：'.$index);
		$this->log('退出返回的信息：'.$response);
        socket_close($socket);
        unset($this->sockets[$index]);
        unset($this->users[$index]);

        $response = $this->encode($response);
        $this->responseMsg($response);
    }

    /**
     * 握手
     * @param $index
     * @param $buffer
     */
    private function handshake($index, $buffer){
        $buf  = substr($buffer, strpos($buffer, 'Sec-WebSocket-Key:')+18);
        $key  = trim(substr($buf,0,strpos($buf,"\r\n")));
        $newKey = base64_encode(sha1($key."258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));

        $upgrade = "HTTP/1.1 101 Switching Protocols\r\n";
        $upgrade .= "Upgrade: websocket\r\n";
        $upgrade .= "Sec-WebSocket-Version: 13\r\n";
        $upgrade .= "Connection: Upgrade\r\n";
        $upgrade .= "Sec-WebSocket-Accept: " . $newKey . "\r\n\r\n";

        $this->log('index:'.$index);
        $this->log($this->users);

        socket_write($this->users[$index]['socket'], $upgrade, strlen($upgrade));
        $this->users[$index]['isHandShake']=true;
    }

    /**
     * @param $sock
     * @return bool|int|string
     */
    private function searchSocketFromUsers($sock){
        foreach ($this->users as $k=>$v){
            if($sock==$v['socket'])
                return $k;
        }
        return false;
    }

    /**
     * 获取用户
     * @return array
     */
    private function getusers(){
        $user = array();
        foreach($this->users as $k=>$v){
            $user[$k] = $v['user'];
        }
        return $user;
    }

    private  function encode($msg){
        $msg = preg_replace(array('/\r$/','/\n$/','/\r\n$/',), '', $msg);
        $frame = array();
        $frame[0] = '81';
        $len = strlen($msg);
        $frame[1] = $len<16?'0'.dechex($len):dechex($len);
        $frame[2] = $this->ordHex($msg);
        $data = implode('',$frame);
        return pack("H*", $data);
    }

    private function ordHex($data)  {  
        $msg = '';  
        $l = strlen($data);  
        for ($i= 0; $i<$l; $i++) {  
            $msg .= dechex(ord($data{$i}));  
        }  
        return $msg;  
    }

    private function decode($str){
        $mask = array();
        $data = '';
        $msg = unpack('H*',$str);
        $head = substr($msg[1],0,2);
        if (hexdec($head{1}) === 8) {
            $data = false;
        }else if (hexdec($head{1}) === 1){
            $mask[] = hexdec(substr($msg[1],4,2));
            $mask[] = hexdec(substr($msg[1],6,2));
            $mask[] = hexdec(substr($msg[1],8,2));
            $mask[] = hexdec(substr($msg[1],10,2));

            $s = 12;
            $e = strlen($msg[1])-2;
            $n = 0;
            for ($i=$s; $i<= $e; $i+= 2) {
                $data .= chr($mask[$n%4]^hexdec(substr($msg[1],$i,2)));
                $n++;
            }
        }
        return $data;
    }

    /**
     * 发送消息
     * @param $msg
     * @param string $client
     */
    private function responseMsg($msg, $client=''){
        if ($client){
            socket_write($client, $msg, strlen($msg));
        }else{
            foreach($this->users as $v){
        		socket_write($v['socket'], $msg, strlen($msg));
            }
        }
    }

	private function createSocket($host, $port){
        $server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if($server === false){
            $error = 'socket_create() failed:'.socket_strerror(socket_last_error());
            $this->log($error);
            exit();
        }
        socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($server, $host, $port);
        socket_listen($server);
        $this->log('Server Started : '.date('Y-m-d H:i:s'));
        $this->log('Listening on   : '.$host.' port '.$port);
        return $server;
    }

    /**
     * @param $msg
     */
    private function log($msg){
        if(!$msg){
            return true;
        }
        if(!is_string($msg)){
            $msg = json_encode($msg);
        }
        $log = '['.date('Y-m-d H:i:s').']：'.$msg."\n";
        error_log($log, 3, 'log');
    }

    private function getResponseData($status='0', $msg='success', $data=array()){
        $arr = array('status'=>$status, 'msg'=>$msg, $data=>$data);
        return json_encode($arr, JSON_UNESCAPED_UNICODE );
    }

    private function generateId(){
    	return md5(uniqid());
    }
}