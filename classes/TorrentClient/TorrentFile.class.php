<?php
class TorrentFile{
    /**
     * Метаинформация торрент-файла
     * @var array
     */
    protected $Data = array();
    /**
     * Конструктор класса
     * 
     * @param array $Data - meta-информация
     */
    public function __construct($Data){
        $this->Data = $Data;
    }
    /**
     * Возвращает метаинформацию
     * 
     * @return array - содержимое meta-информации
     */
    public function getMetaInfo(){
        return $this->Data['MetaData'];
    }
    /**
     * Вовзращает хэш торрента
     * 
     * @return string - hash торрента
     */
    public function getHash(){
        return $this->Data['MetaData']['info_hash'];
    }
}
?>
