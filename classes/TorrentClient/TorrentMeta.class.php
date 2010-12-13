<?php
define('TORRENTMETA_ERROR_NO_INPUT_FILE',1);
class TorrentMeta{
	
	protected 
			$file = '',
			$errorCode = 0,
			$meta = array(),
            $hash = '';
	
	public function __construct($metaFrom='',$useString=''){
		if ( $useString ){
			$this->meta = TorrentMeta::parseByteString($metaFrom);
		}elseif($metaFrom){
			$this->file = $metaFrom;
			if ( $metaFrom ){
				$this->parseMetaFile($metaFrom);
			}
		}
	}
	public function getErrorCode(){
		return $this->errorCode;
	}
	public function getMetaInfo(){
		return $this->meta;
	}
    public function getHash($returnHexVal=false){
        return $returnHexVal ? bin2hex($this->meta['info_hash']) : $this->meta['info_hash'];
    }
	
	protected function parseMetaFile($file){
		if ( file_exists($file) ){
			$this->meta = TorrentMeta::parseByteString(file_get_contents($file));
			if( !empty($this->meta) ){
				$this->meta['pieces_array'] = $this->parsePiecesHashes();
                $this->meta['total_size'] = 0;
                if ( isset($this->meta['info']['files']) ){
                    for( $i=0;$i<count($this->meta['info']['files']);$i++ )
                        $this->meta['total_size'] += $this->meta['info']['files'][$i]['length'];
                }else
                    $this->meta['total_size'] = $this->meta['info']['length'];
            }
		}else
		    $this->Error(TORRENTMETA_ERROR_NO_INPUT_FILE);
		return false;
	}
	protected function parsePiecesHashes(){
		if ( !empty($this->meta['info']['pieces']) ){
			$pieces = array();
            $pieces_str = bin2hex($this->meta['info']['pieces']);
			$len = $pieces_str;
			for( $offset=0;$offset<$len;$offset+=40 ){
				$pieces[] = substr($pieces_str,$offset,40);
			}
            //var_dump($pieces);
			return $pieces;
		}
		return false;
	}
	public static function parseByteString($string){
        $data = array();
		TorrentMeta::parseByteStringRecursive($string,0,$data);
        return $data;
	}
	protected static function parseByteStringRecursive($string,$offset,&$data){
		$modifier = substr($string,$offset,1);
		$len = strlen($string);
		if ( (string)intval($modifier)===$modifier ){
			$pos = strpos($string,':',$offset);
			$length = intval(substr($string,$offset,$pos-$offset));
			$data = substr($string,$pos+1,$length);
			$offset = $pos+1+$length;
		}else{
			$offset++;
			switch ($modifier){
				case 'd':
					$data = array();
					do{
						$key = '';
						$offset = TorrentMeta::parseByteStringRecursive($string,$offset,$key);
						if ( $offset>=$len )
							break;
						$value = '';
						$offset_new = TorrentMeta::parseByteStringRecursive($string,$offset,$value);
                        if ( $key=='info' ){
                            $toHash = substr($string,$offset,$offset_new-$offset);
                            $data['info_hash'] = sha1($toHash,true);
                        }
                        $offset = $offset_new;
						$data[$key] = $value;
					}while( substr($string,$offset,1)!='e' && $offset<$len );
					$offset++;
				break;
				case 'l':
					$data = array();
					do{
						$value = '';
						$offset = TorrentMeta::parseByteStringRecursive($string,$offset,$value);
						$data[] = $value;
					}while( substr($string,$offset,1)!='e' && $offset<$len );
					return $offset+1;
				break;
				case 'i':
					$pos = strpos($string,'e',$offset);
					$data = intval(substr($string,$offset,$pos-$offset));
					return $pos+1;
				break;
			}
		}
		return $offset;
	}
	protected function Error($errorCode){
		$this->errorCode = $errorCode;
	}
	
}
?>