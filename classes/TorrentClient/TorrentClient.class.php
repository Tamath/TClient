<?php
class TorrentClient{
    private 
        /* Настройки конфигурации */
        $Configuration = array(
            'ListenIP' => '0.0.0.0',    // IP на котором слушаем
            'ListenPort' => '20000',    // Порт на котором слушаем
            'LogErrors' => true,        // Логировать ли ошибки
            'LogOutputOnScreen' => true,       // Выводить ли сообщения лога на экран
            'LogOutputToFile' => false,        // Выводить ли сообщения лога в файл
            'PeerId' => '-PT0001-%b2W%d6U%b2%ceX%93%969%b5%ad',// ID пира
            'QueueListFile' => './queue.inf', // Путь к файлу очереди
            'TorrentsDir' => 'torrents/' // Директория торрентов
        ),
        /* Соединения */
        $Connections = array(),     // Массив соединений
        $ConnectionIndex = array(), // Индекс соединений: IP:Port => номер соединения
        /* Торренты */
        $Torrents = array(),
        /* Лог */
        $Errors = array(),
        $Log = array();
            
    /* --------------------   Методы общего использования   ------------------- */
    
    /**
    * Конструктор класса
    * 
    * @param mixed $file - .torrent-файл(ы) для добавления в очередь заказчки
    * @return TorrentClient
    */
    public function __construct($file=null){
        $this->setConfig('QueueListFile',dirname(__FILE__).'/queue.inf');
        include_once('PeerConnection.class.php');
        
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
    /**
    * Деструктор класса
    * 
    */
    public function __destruct(){
        $this->stopTorrent();
        $this->closeListenConnection();
    }
    /**
    * Инициализирует клиент
    * 
    */
    public function initialise(){
        if ( !$this->createListenConnection() )
            return false;
        $this->Log('Client successfully initialised on '.$this->getConfig('ListenIp').', port '.$this->getConfig('ListenPort'));
        $this->readQueue();
        $this->startTorrents();
        $this->waitCommand();
    }
    
    /* --------------------   Методы для работы с соединениями   ------------------- */
    /**
    * Создает новое соединение. Для TCLIENT_CONNECTION_TYPE_LISTEN и TCLIENT_CONNECTION_TYPE_OUTGOING
    * необязательным является только параметр $ConnectionResource
    * 
    * @param int $ConnectionType - Тип соединения
    * @param mixed $ConnectionResource - Ресурс для соединения
    * @param mixed $ConnectionIp - IP для соединения
    * @param mixed $ConnectionPort - Порт для соединения
    */
    private function createConnection($ConnectionType,$ConnectionResource=null,$ConnectionIp=null,$ConnectionPort=null){
        $Connection = new PeerConnection($this,array(
            'Type' => $ConnectionType,
            'Resource' => $ConnectionResource,
            'Ip' => $ConnectionIp,
            'Port' => $ConnectionPort
        ));
        $result = $Connection->connect();
        if ( is_numeric($result) ){
            $this->Error(socket_strerror($result));
        }
        if ( $result === true ){
            $this->Connections[] = $Connection;
            $this->ConnectionIndex[$ConnectionIp.':'.$ConnectionPort] = count($this->Connections)-1;
        }
        return $result===true ? true : false;
    }
    /**
    * Создает слушающий (listen) сокет
    * 
    */
    private function createLsitenConnection(){
        return $this->createConnection(TCLIENT_CONNECTION_TYPE_LISTEN,null,$this->getConfig('ListenIp'),$this->getConfig('ListenPort'));
    }
    
    /* --------------------   Методы для работы с торрентами   ------------------- */
    /**
    * Читает файл очереди торрентов
     * 
     * @return true|false
    */
    public function readQueue(){
        $Queue = parse_ini_file($this->getConfig('QueueListFile'),true);
        if ( !empty($Queue['Queue']['Torrent']) ){
            foreach( $Queue['Queue']['Torrent'] as $Torrent ){
                $this->addTorrent($Torrent['metafile']);
            }
            return true;
        }
        return false;
    }
    /**
     * Добавляет торрент-файл в список
     * @param string $TorrentFile - имя .torrent-файла
     * @return bool 
     */
    public function addTorrent($TorrentFile){
        if ( file_exists($this->getConfig('TorrentsDir').$TorrentFile) ){
            $Data = new TorrentMeta($this->getConfig('TorrentsDir').$TorrentFile);
            $this->Torrents[] = new TorrentFile($Data->getMetaInfo());
            return true;
        }
        return false;
    }
    
    /* --------------------   Методы для работы с конфигурацией   ------------------- */
    /**
    * Возвращает значение параметра конфигурации
    * 
    * @param string $key - Параметр конфигурации
    */
    public function getConfig($key){
        return isset($this->Configuration[$key]) ? $this->Configuration[$key] : null;
    }
    /**
    * Устанавливает значение параметра конфигурации
    * 
    * @param string $key - Параметр конфигурации
    * @param mixed $value - Значение
    */
    public function setConfig($key,$value){
        if ( isset($this->Configuration[$key]) )
            $this->Configuration[$key] = $value;
        else
            return false;
        return true;
    }
    
    /* --------------------   Методы для работы с логированием   ------------------- */
    /**
    * Выводит ошибку в лог
    * 
    * @param string $errstr - Строка ошибки
    */
    public function Error($errstr){
        $this->Errors[] = $errstr;
        if ( $this->Configuration['LogErrors']==true ){
            $this->Log('Error: '.$errstr);
        }
    }
    /**
    * Добавляет данные в лог
    * 
    * @param string $str - Строка
    */
    public function Log($str){
        $str = date('d.m.Y H:i:s').' '.$str;
        $this->Log[] = $str;
        if ( $this->Configuration['LogOutputOnScreen']==true ){
            echo $str."\n";
        }
    }
    /**
    * Возвращает все ошибки
    * 
    */
    public function getErrors(){
        return implode(' ',$this->Errors);
    }
}
?>