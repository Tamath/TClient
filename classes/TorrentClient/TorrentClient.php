<?php
/* --- константы --- */

// Типы соединений 
define('TCLIENT_CONNECTION_TYPE_LISTEN',1);
define('TCLIENT_CONNECTION_TYPE_INCOMING',2);
define('TCLIENT_CONNECTION_TYPE_OUTGOING',4);
//Статусы соединений
define('TCLIENT_CONNECTION_STATUS_CLOSED',1);
define('TCLIENT_CONNECTION_STATUS_OPENED',2);

/* --- классы --- */

include_once('TorrentMeta.class.php');
include_once('TorrentFile.class.php');
include_once('TorrentClient.class.php');
?>
