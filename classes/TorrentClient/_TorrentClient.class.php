<?php
class TorrentClient{
    private $ResponseCode = null,
            $Configuration = array(
                'ListenIP' => '0.0.0.0',    // IP на котором слушаем
                'ListenPort' => '20001',    // Порт на котором слушаем
                'LogErrors' => true,        // Логировать ли ошибки
                'LogOutput' => true,        // Выводить ли сообщения лога
                'PeerId' => '-PT0001-%b2W%d6U%b2%ceX%93%969%b5%ad',// ID пира
                'QueueListFile' => './queue.inf', // Путь к файлу очереди
                'TorrentsDir' => 'torrents/' // Директория торрентов
            ),
            $ListenSocket = null,
            $Connections = array(),
            $Errors = array(),
            $Queue = array(),
            $CommandsQueue = array(),
            $Log = array();
    const
            CONNECTION_TYPE_INCOMING = 1,
            CONNECTION_TYPE_OUTGOING = 2,
            CONNECTION_STATUS_CLOSED = 1,
            CONNECTION_STATUS_OPENED = 2;
    public function __construct($file=null){
        $this->setConfig('QueueListFile',dirname(__FILE__).'/queue.inf');
        if ( is_null($file) )
            return false;
        if ( is_array($file) )
        {
            foreach ( $file as $f )
                $this->addTorrent($f);
        }else
        {
            $this->addTorrent($file);
        }
    }
    public function openSocket(){
        if ( !$this->ListenSocket ){
            
            // Создаем сокет для семейства IPv4 протоколов (AF_INET), типа SOCK_STREAM, протокола TCP (SOL_TCP)
            if( false === ($socket=@socket_create(AF_INET, SOCK_STREAM, 0)) ){
               $this->Error("Unable to create socket: " .socket_strerror(socket_last_error()));
               return false;
            }   
            // Биндим сокет на определённый адрес и порт
            if( false === @socket_bind($socket, $this->Configuration['ListenIP'], $this->Configuration['ListenPort']) )
            {
                $this->Error("Unable to bind socket:" .socket_strerror(socket_last_error()));
                return false;
            }

            // Прослушиваем сокет
            if( false === @socket_listen($socket, 5) )
            {
                $this->Error("Enable to listen socket: " .socket_strerror(socket_last_error()));
                return false;
            }
            
            $this->ListenSocket = $socket;
            return true;
        }
    }
    public function closeSocket(){
        for( $i=0,$c=count($this->Connections);$i<$c;$i++ ){
            if ( $this->Connections[$i]['Status']==self::CONNECTION_STATUS_OPENED ){
                $this->closeConnection($i);
            }
        }
        if ( $this->ListenSocket ){
            // Закрываем сокет
            socket_close($this->ListenSocket);
        }
    }
    public function readQueue(){
        if ( file_exists($this->getConfig('QueueListFile')) ){
            $data = parse_ini_file($this->getConfig('QueueListFile'),true);
            if ( !empty($data['Queue']['Torrent'])&&is_array($data['Queue']['Torrent']) ){
                $this->emptyQueue();
                foreach ( $data['Queue']['Torrent'] as $File ){
                    $this->addToQueue($File);
                }
            }
        }
    }
    public function emptyQueue(){
        $this->Queue = array();
    }
    public function addToQueue($File){
        if ( file_exists($this->getConfig('TorrentsDir').$File) ){
            $MetaData = new TorrentMeta($this->getConfig('TorrentsDir').$File);
            $this->Queue[] = array(
                'MetaFile' => $File,
                'MetaData' => $MetaData->getMetaInfo(),
                'TorrentHash' => $MetaData->getHash(),
                'TorrentHashHex' => $MetaData->getHash(true),
                'Status' => 0,
                'AnnounceUrl' => '',
                'Peers' => array()
            );
            $this->Log('Torrent added: '.$File.', hash '.$MetaData->getHash(true));
        }
    }
    public function getPeers($File){
        if ( isset($this->Queue[$File]) && $this->Queue[$File]['Status'] ){
            return $this->Queue[$File]['Peers'];
        } 
        return false;
    }
    public function waitCommand(){
        if ( !$this->ListenSocket )
            return false;
        
        socket_set_nonblock($this->ListenSocket);    
        while ( TRUE ){
            $read = array(
                0 => $this->ListenSocket
            );
            $write = array();
            $except = null;
            for ( $i=0,$c=count($this->Connections);$i<$c;$i++ ){
                if ( $this->Connections[$i]['Status']==self::CONNECTION_STATUS_OPENED )
                    $read[$i+1] = $this->Connections[$i]['Resource'];
            }
            for ( $i=0,$c=count($this->CommandsQueue);$i<$c;$i++ ){
                if ( !isset($write[$this->CommandsQueue[$i]['ConnectionIndex']]) )
                    $write[$this->CommandsQueue[$i]['ConnectionIndex']] = $this->Connections[$this->CommandsQueue[$i]['ConnectionIndex']]['Resource'];
            }
            foreach($write as $index=>$socket){
                if ( !$socket )
                    continue;
                for ( $i=0,$c=count($this->CommandsQueue);$i<$c;$i++ ){
                    if ( !$this->CommandsQueue[$i] )continue;
                    if ( $index==$this->CommandsQueue[$i]['ConnectionIndex'] ){
                        $length = strlen($this->CommandsQueue[$i]['Command']);
                        $offset = 0;
                        while ($offset < $length ) {
                            $sent = socket_write($socket, substr($this->CommandsQueue[$i]['Command'], $offset), $length-$offset);
                            if ($sent === false) {
                                // Error occurred, break the while loop
                                break;
                            }
                            $offset += $sent;
                        }
                        if ( $offset<$length ){
                            $this->Error('Error while writing "'.$this->CommandsQueue[$i]['Command'].'" to '.$this->getPeerName($this->CommandsQueue[$i]['ConnectionIndex']).': '.socket_strerror(socket_last_error()));
                            $this->closeConnection($index);
                        }else
                            $this->Log('Command successfully sent to '.$this->getPeerName($index).': '.$this->CommandsQueue[$i]['Command']);
                        $this->CommandsQueue[$i] = null;
                        break; 
                    }
                }
                $commands = array();
                for ( $i=0,$c=count($this->CommandsQueue);$i<$c;$i++ ){
                    if ( $this->CommandsQueue[$i] != null )
                        $commands[] = $this->CommandsQueue[$i];
                }
                $this->CommandsQueue = $commands;
                
            }
            $write = array();
            
            if ( false===($num_socket_selected=socket_select($read,$write,$except,null)) ){
                $this->Error("Unable to select sockets: ".socket_strerror(socket_last_error()));
            }
            
            foreach($read as $i=>$socket){
                if ( !$i && false !== ($connection=@socket_accept($read[$i])) ){
                    if ($this->createIncomingConnection($connection) )
                        $read[$i] = $connection;
                }
                    
                $line = '';
                do{
                    $string = @socket_read($read[$i], 4096,PHP_BINARY_READ);
                    if( $string===false ){
                        $this->Error('Unable to read from socket by '.$this->getPeerName($i-1));
                        $this->closeConnection($i-1);
                        break;
                    }else{
                        $line .= $string;
                    }
                }while( $string!=='' && $string!==NULL );
                
                $line = trim($line);
                if ( $line ){
                    $this->Log('Command from '.$this->getPeerName($i-1).': '.$line);
                    if ( $line=='bye bye' )
                        break 2;
                    elseif ( $line=='bye' )
                        $this->closeConnection($i-1);
                    else{
                        $this->responseCommand($i-1,$line);
                    }
                }
                
            }
            
            
        }
            
    }
    private function responseCommand($ConnectionIndex,$Command){
        $handshake_start = pack('CA*',19,'BitTorrent protocol');
        if ( substr($Command,0,strlen($handshake_start))==$handshake_start ){
            $this->Connections[$ConnectionIndex]['TorrentHash'] = substr($Command,21,20);
            $this->Connections[$ConnectionIndex]['PeerId'] = substr($Command,41,20);
            if ( !$this->Connections[$ConnectionIndex]['HandShakeSent'] ){
                $this->peerRequest($this->Connections[$ConnectionIndex]['PeerIp'],$this->Connections[$ConnectionIndex]['PeerPort'],$this->getMessage('handshake',array('hash'=>$this->Connections[$ConnectionIndex]['TorrentHash'])));
                $this->Connections[$ConnectionIndex]['HandShakeSent'] = true;
                return true;
            }
        }
        $length = bindec(substr($Command,0,4));
        $id = bindec(substr($Command,4,1));
        var_dump($length);
        var_dump($id);
    }
    private function createIncomingConnection($connection){
        return $this->createConnection(self::CONNECTION_TYPE_INCOMING,$connection);
    }
    private function createOutgoingConnection($Ip,$Port){
        return $this->createConnection(self::CONNECTION_TYPE_OUTGOING,null,$Ip,$Port);
    }
    private function createConnection($connection_type,$connection_resource=null,$remote_ip=null,$remote_port=null){
        $connection = array(
            'Resource' => null,
            'PeerIp' => null,
            'PeerPort' => null,
            'Status' => self::CONNECTION_STATUS_OPENED,
            'Type' => $connection_type,
            'TorrentHash' => '',
            'HandShakeSent' => false,
            'PeerId' => ''
        );
        if ( $connection_type == self::CONNECTION_TYPE_INCOMING ){
            if ( !$connection_resource ){
                return false;
            }
            $connection['Resource'] = $connection_resource;
            
            if ( !$remote_ip || !$remote_port ){
                $ip = '';
                $port = '';
                if( @socket_getpeername($connection['Resource'],$ip,$port) ){
                    if ( !$remote_ip )
                        $remote_ip = $ip;
                    if ( !$remote_port )
                        $remote_port = $port;
                }
            }
            
            $connection['PeerIp'] = $remote_ip;
            $connection['PeerPort'] = $remote_port;
            
        }
        if ( $connection_type == self::CONNECTION_TYPE_OUTGOING ){
            if ( !$remote_ip || !$remote_port )
                return false;
            if ( !$connection_resource ){
                $connection_resource = socket_create(AF_INET,SOCK_STREAM,SOL_TCP);
                socket_bind($connection_resource,$this->getConfig('ListenIP'));
                if ( !@socket_connect($connection_resource,$remote_ip,$remote_port) )
                    return false;
            }
            $connection['Resource'] = $connection_resource;
            $connection['PeerIp'] = $remote_ip;
            $connection['PeerPort'] = $remote_port;
        }
        //socket_set_nonblock($connection['Resource']);
        $this->Connections[] = $connection;
        $this->Log('Created '.($connection_type==self::CONNECTION_TYPE_INCOMING?'incoming':'outgoing').' connection with '.$connection['PeerIp'].':'.$connection['PeerPort']);
        return ( count($this->Connections)-1 );
    
    }
    private function connectionCreated($Ip,$Port){
        for( $i=0,$c=count($this->Connections);$i<$c;$i++ ){
            if ( $this->Connections[$i]['PeerIp']==$Ip && $this->Connections[$i]['PeerPort']==$Port )
                return true;
        }
        return false;
    }
    private function closeConnection($N){
        if( isset($this->Connections[$N])&&$this->Connections[$N]['Status']==self::CONNECTION_STATUS_OPENED ){
            socket_close($this->Connections[$N]['Resource']);
            $this->Connections[$N]['Status'] = self::CONNECTION_STATUS_CLOSED;
            $this->Log('Closed connection with '.$this->Connections[$N]['PeerIp'].':'.$this->Connections[$N]['PeerPort']);
        }
    }
    private function getPeerName($ConnectionIndex){
        return isset($this->Connections[$ConnectionIndex]) ? $this->Connections[$ConnectionIndex]['PeerIp'].':'.$this->Connections[$ConnectionIndex]['PeerPort'] : null;
    }
    private function getConnectionIndex($Ip,$Port){
        for( $i=0,$c=count($this->Connections);$i<$c;$i++ ){
            if ( $this->Connections[$i]['PeerIp']==$Ip && $this->Connections[$i]['PeerPort']==$Port )
                return $i;
        }
        return false;
    }
    public function startTorrent($File){
        if ( isset($this->Queue[$File])&&$this->Queue[$File]['Status']==0 ){
           
            $resultAnnounceUrl = ''; 
            if ( !isset($this->Queue[$File]['MetaData']['announce-list']) ){
                $ResponseData = $this->announceTorrent($File,$this->Queue[$File]['MetaData']['announce']);
                $resultAnnounceUrl = $this->Queue[$File]['MetaData']['announce'];
            }else{
                for ( $i=0;$i<count($this->Queue[$File]['MetaData']['announce-list']);$i++ ){
                    if ( is_array($this->Queue[$File]['MetaData']['announce-list'][$i]) ){
                        for( $n=0;$n<count($this->Queue[$File]['MetaData']['announce-list'][$i]);$n++ ){
                            $ResponseData = $this->announceTorrent($File,$this->Queue[$File]['MetaData']['announce-list'][$i][$n]);
                            if ( $ResponseData ){
                                $resultAnnounceUrl = $this->Queue[$File]['MetaData']['announce-list'][$i][$n];
                                break;
                            }
                        }
                    }else{
                        $ResponseData = $this->announceTorrent($File,$this->Queue[$File]['MetaData']['announce-list'][$i]);
                        if ( $ResponseData ){
                            $resultAnnounceUrl = $this->Queue[$File]['MetaData']['announce-list'][$i];
                        }
                    }
                    if ( $ResponseData )break;
                }
            }
            
            if ( empty($resultAnnounceUrl) ){
                $this->Error('Failed to start torrent '.$this->Queue[$File]['TorrentHashHex']);
                return false;
            }
            
            if ( !is_array($ResponseData['peers']) ){
                $peers = bin2hex($ResponseData['peers']);
                $PeersArray = array();
                
                for( $offset=0;$offset<strlen($peers);$offset+=12 ){
                    $PeersArray[] = array(
                        'ip' => hexdec(substr($peers,$offset,2)).'.'.hexdec(substr($peers,$offset+2,2)).'.'.hexdec(substr($peers,$offset+4,2)).'.'.hexdec(substr($peers,$offset+6,2)),
                        'port' => hexdec(substr($peers,$offset+8,4))
                    );
                }
            }else
                $PeersArray = $ResponseData['peers'];
            
            $this->Queue[$File]['Status'] = 1;
            $this->Queue[$File]['AnnounceUrl'] = $resultAnnounceUrl;
            $this->Queue[$File]['Peers'] = $PeersArray;
            $this->connectToPeers($File);
            $this->Log('Torrent '.$this->Queue[$File]['TorrentHashHex'].' started on '.$resultAnnounceUrl.' with '.count($PeersArray).' peers');
        }
    }
    private function hasTorrent($TorrentHash,$ignoreStatus=false){
        for($i=0,$c=count($this->Queue);$i<$c;$i++){
            if ( $this->Queue[$i]['TorrentHash']==$TorrentHash || $this->Queue[$i]['TorrentHash']==$TorrentHashHex ){
                if ( $ignoreStatus || $this->Queue[$i]['Status'] )
                    return true;
            }
        }
    }
    public function stopTorrent($File){
        if ( isset($this->Queue[$File])&&$this->Queue[$File]['Status']==1 ){
            $this->announceTorrent($File,$this->Queue[$File]['AnnounceUrl'],'stopped');
            $this->Queue[$File]['Status'] = 0;
            $this->Queue[$File]['AnnounceUrl'] = '';
            $this->Queue[$File]['Peers'] = array();
            $this->Log('Torrent '.$this->Queue[$File]['TorrentHashHex'].' stopped');
        }
    }
    public function connectToPeers($File){
        
        if ( isset($this->Queue[$File])&&$this->Queue[$File]['Status']==1 ){
            for( $i=0;$i<count($this->Queue[$File]['Peers']);$i++ ){
                if ( $this->Queue[$File]['Peers'][$i]['port']!=$this->getConfig('ListenPort') && $this->Queue[$File]['Peers'][$i]['ip']!=$this->getConfig('ListenIp') ){
                    if ( $this->peerRequest($this->Queue[$File]['Peers'][$i]['ip'],$this->Queue[$File]['Peers'][$i]['port'],$this->getMessage('handshake',array('hash'=>$this->Queue[$File]['MetaData']['info_hash']))) )
                        $this->Connections[$this->getConnectionIndex($this->Queue[$File]['Peers'][$i]['ip'],$this->Queue[$File]['Peers'][$i]['port'])]['HandShakeSent'] = true;
                    break;
                }
            }
        }
        
    }
    private function getMessage($type,$data){
        switch($type){
            case 'handshake':
                return pack('CA*cA*A*',19,'BitTorrent protocol',0,$data['hash'],$this->Configuration['PeerId']);
            break;
        }
    }
    private function announceTorrent($File,$AnnounceTracker,$event='started'){
        if ( !isset($this->Queue[$File]) )
            return false;
        $Response = $this->request($AnnounceTracker,array(
            'info_hash' => rawurlencode($this->Queue[$File]['MetaData']['info_hash']),
            'peer_id' => $this->Configuration['PeerId'],
            'port' => $this->Configuration['ListenPort'],
            'uploaded' => 0,
            'downloaded' => 0,
            'compact' => '1',
            'event' => $event,
            'corrupt' => '0',
            'numwant' => 200,
            'no_peer_id' => '1',
            'left' => $this->Queue[$File]['MetaData']['total_size']
        ));
        if ( !$Response )
            return false;
        $Response = TorrentMeta::parseByteString($Response);
        if( !empty($Response['failure reason']) ){
            $this->Error('Error while announcing '.bin2hex($this->Queue[$File]['MetaData']['info_hash']).' on '.$AnnounceTracker.': '.$Response['failure reason']);
            return false;
        }
        return $Response;
    }
    public function TorrentFile($File){
        if ( is_int($File) && isset($this->Queue[$File]) ){
            return ( new TorrentFile($this->Queue[$File]) );
        }
    }
    
    private function request($Address,$Params=array()){
        if ( !empty($Params)&&is_array($Params) )
        {
            if ( strpos($Address,'?')!==false ){
                $Address .= '&';
            }else
                $Address .= '?';
            $Params_ = array();
            foreach($Params as $k=>$v){
                $Params_[] = $k.'='.$v;
            }
            $Address .= implode('&',$Params_);
        }
        $curl = curl_init($Address);
        curl_setopt_array($curl,array(
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => array($this,'readResponseHeaders')
        ));
        $content = curl_exec($curl);
        curl_close($curl);
        return $this->ResponseCode==200 ? $content : false;
    }
    private function peerRequest($ip,$port,$message){
        $Address = $ip.':'.$port;
        $this->Log('Command to '.$Address.': '.$message);
        
        if( $this->connectionCreated($ip,$port) || $this->createOutgoingConnection($ip,$port) )
            $this->addCommandToQueue($ip,$port,$message);
        else
            $this->Log('Cannot send command to '.$Address.': connection can not be established');
    }
    private function addCommandToQueue($ip,$port,$command){
        $this->CommandsQueue[] = array(
            'Ip' => $ip,
            'Port' => $port,
            'Command' => $command,
            'ConnectionIndex' => $this->getConnectionIndex($ip,$port)
        );
    }
    private function readResponseHeaders($curl,$string){
        if ( strpos($string,'HTTP')!==false ){
            if ( preg_match('/HTTP\/1\.(0|1) ([0-9]{3})/i',$string,$m) ){
                $this->ResponseCode = $m[2];
            }
        }
        return strlen($string);
    }
    public function Error($errstr){
        $this->Errors[] = $errstr;
        if ( $this->Configuration['LogErrors']==true ){
            $this->Log('Error: '.$errstr);
        }
    }
    public function Log($str){
        $str = date('d.m.Y H:i:s').' '.$str;
        $this->Log[] = $str;
        if ( $this->Configuration['LogOutput']==true ){
            echo $str."\n";
        }
    }
    public function getError(){
        return implode(' ',$this->Errors);
    }
    public function getConfig($key){
        return isset($this->Configuration[$key]) ? $this->Configuration[$key] : null;
    }
    public function setConfig($key,$value){
        if ( isset($this->Configuration[$key]) )
            $this->Configuration[$key] = $value;
        else
            return false;
        return true;
    }
}
?>
