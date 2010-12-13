<?php
class PeerConnection{
    private 
        $Type = 0,          // Тип соединения
        $Resource = null,   // Ресурс соединения
        $Ip = '',           // IP соединения
        $Port = '',         // Порт соединения
        $TorrentClient = null;  // Ссылка на объект-клиент, инициализирующий соединение
    /**
    * Конструктор класса
    * 
    * @param object-reference $TorrentClient - Ссылка на объект-клиент, инициализирующий соединение
    * @param array $Config - Массив конфигурации соединения
    * @return PeerConnection
    */
    public function __construct(&$TorrentClient,$Config=array()){
        $this->TorrentClient = $TorrentClient;
        foreach ( $Config as $k=>$v ){
            if ( isset($this->$k) )
                $this->$k = $v;
        }
    }
    /**
    * Создает соединение
    *  
    * @return int|bool - возвращает FALSE если не указаны необходимые параметры, TRUE если соединение установлено, номер ошибки, если произошла ошибка
    */
    public function connect(){
        switch( $this->Type ){
            // слушающий сокет
            case TCLIENT_CONNECTION_TYPE_LISTEN:
                if ( !$this->Ip || !$this->Port )
                    return false;
                if ( false === ($socket=@socket_create(AF_INET,SOCK_STREAM,SOL_TCP)) )
                    return socket_last_error();
                if ( false === @socket_bind($socket,$this->Ip,$this->Port) )
                    return socket_last_error();
                if( false === @socket_listen($socket, 5) )
                    return socket_last_error();
                $this->Resource = $socket;                    
            break;
            //исходящее соединение
            case TCLIENT_CONNECTION_TYPE_OUTGOING:
                if ( !$this->Ip || !$this->Port )
                    return false;
                if ( false === ($socket=@socket_create(AF_INET,SOCK_STREAM,SOL_TCP)) )
                    return socket_last_error();
                if ( false === @socket_bind($socket,$this->TorrentClient->getConfig('ListenIp'),$this->TorrentClient->getConfig('ListenPort')) )
                    return socket_last_error();
                if( false === @socket_connect($socket,$this->Ip,$this->Port) )
                    return socket_last_error();
                $this->Resource = $socket;
            break;
            // входящее соединение
            case TCLIENT_CONNECTION_TYPE_INCOMING:
                if ( !$this->Resource )
                    return false;
                if ( !$this->Ip || !$this->Port ){
                    $ip = '';
                    $port = '';
                    if( @socket_getpeername($this->Resource,$ip,$port) ){
                        if ( !$this->Ip )
                            $this->Ip = $ip;
                        if ( !$this->Port )
                            $this->Port = $port;
                    }
                }
            break;
        }
        return true;
    }
}
?>
