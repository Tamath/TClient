<?php
error_reporting(E_ALL);
ini_set('display_errors',1);
set_time_limit(0);
setlocale(LC_ALL,'en_EN');
ob_implicit_flush(true);

include('classes/TorrentClient/TorrentClient.php');
$TorrentClient = new TorrentClient();

$TorrentClient->setConfig('TorrentsDir',dirname(__FILE__).'/torrents/');


if ( !$TorrentClient->initialise() ){
    // Ошибка инициализации сокета
    $TorrentClient->Error('Problem while initialising client');
}
?>
