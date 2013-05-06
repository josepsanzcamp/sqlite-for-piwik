<?php
function _mysql2sqlite_debug() {
	return false;
}

function _mysql2sqlite_connect($conn) {
	$conn->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
	$conn->setAttribute(PDO::ATTR_TIMEOUT,0);
	$conn->query("PRAGMA cache_size=2000");
	$conn->query("PRAGMA synchronous=OFF");
	$conn->query("PRAGMA foreign_keys=OFF");
	$conn->sqliteCreateFunction("GET_LOCK","_mysql2sqlite_get_lock");
	$conn->sqliteCreateFunction("RELEASE_LOCK","_mysql2sqlite_release_lock");
	$conn->sqliteCreateFunction("VARIABLE_EMULATION","_mysql2sqlite_variable_emulation");
	$conn->sqliteCreateAggregate("GROUP_CONCAT","_mysql2sqlite_group_concat_step","_mysql2sqlite_group_concat_finalize");
	$conn->sqliteCreateFunction("LPAD","_mysql2sqlite_lpad");
	$conn->sqliteCreateFunction("CONCAT","_mysql2sqlite_concat");
	$conn->sqliteCreateFunction("UNIX_TIMESTAMP","_mysql2sqlite_unix_timestamp");
	$conn->sqliteCreateFunction("YEAR","_mysql2sqlite_year");
	$conn->sqliteCreateFunction("MONTH","_mysql2sqlite_month");
	$conn->sqliteCreateFunction("WEEK","_mysql2sqlite_week");
	$conn->sqliteCreateFunction("TRUNCATE","_mysql2sqlite_truncate");
	$conn->sqliteCreateFunction("DAY","_mysql2sqlite_day");
	$conn->sqliteCreateFunction("DAYOFYEAR","_mysql2sqlite_dayofyear");
	$conn->sqliteCreateFunction("DAYOFWEEK","_mysql2sqlite_dayofweek");
	$conn->sqliteCreateFunction("HOUR","_mysql2sqlite_hour");
	$conn->sqliteCreateFunction("MINUTE","_mysql2sqlite_minute");
	$conn->sqliteCreateFunction("SECOND","_mysql2sqlite_second");
	$conn->sqliteCreateFunction("MD5","_mysql2sqlite_md5");
	$conn->sqliteCreateFunction("CRC32","_mysql2sqlite_crc32");
	register_shutdown_function("_mysql2sqlite_shutdown_handler");
}

function _mysql2sqlite_shutdown_handler() {
	_mysql2sqlite_semaphore_release();
}

function _mysql2sqlite_get_lock() {
	return 1;
}

function _mysql2sqlite_release_lock() {
	return 1;
}

function _mysql2sqlite_variable_emulation($arg) {
	$var=substr($arg,0,strpos($arg,"="));
	return eval("global $var; return $arg;");
}

function _mysql2sqlite_semaphore_acquire($file="tmp/sqlite.sem",$timeout=10000000) {
	return _mysql2sqlite_semaphore_helper(__FUNCTION__,$file,$timeout);
}

function _mysql2sqlite_semaphore_release($file="tmp/sqlite.sem") {
	return _mysql2sqlite_semaphore_helper(__FUNCTION__,$file,null);
}

function _mysql2sqlite_semaphore_helper($fn,$file,$timeout) {
	static $stack=array();
	$hash=md5($file);
	if(!isset($stack[$hash])) $stack[$hash]=null;
	if(stripos($fn,"acquire")!==false) {
		srand((float)microtime(true)*1000000);
		if($stack[$hash]) return false;
		while($timeout>=0) {
			$stack[$hash]=@fopen($file,"a");
			if(_mysql2sqlite_debug()) _mysql2sqlite_log(getmypid().": open");
			if($stack[$hash]) break;
			$timeout-=_mysql2sqlite_usleep(rand(0,1000));
		}
		if($timeout<0) {
			return false;
		}
		@chmod($file,0666);
		@touch($file);
		while($timeout>=0) {
			$result=@flock($stack[$hash],LOCK_EX|LOCK_NB);
			if(_mysql2sqlite_debug()) _mysql2sqlite_log(getmypid().": lock");
			if($result) break;
			$timeout-=_mysql2sqlite_usleep(rand(0,1000));
		}
		if($timeout<0) {
			if($stack[$hash]) {
				@fclose($stack[$hash]);
				$stack[$hash]=null;
			}
			return false;
		}
		ftruncate($stack[$hash],0);
		fwrite($stack[$hash],getmypid());
		return true;
	}
	if(stripos($fn,"release")!==false) {
		if(!$stack[$hash]) return false;
		@flock($stack[$hash],LOCK_UN);
		if(_mysql2sqlite_debug()) _mysql2sqlite_log(getmypid().": unlock");
		@fclose($stack[$hash]);
		if(_mysql2sqlite_debug()) _mysql2sqlite_log(getmypid().": close");
		$stack[$hash]=null;
		return true;
	}
	return false;
}

function _mysql2sqlite_group_concat_step($context,$rows,$string,$separator=",") {
	if($context!="") $context.=$separator;
	$context.=$string;
	return $context;
}

function _mysql2sqlite_group_concat_finalize($context,$rows) {
	return $context;
}

function _mysql2sqlite_lpad($input,$length,$char) {
	return str_pad($input,$length,$char,STR_PAD_LEFT);
}

function _mysql2sqlite_concat() {
	$array=func_get_args();
	return implode("",$array);
}

function _mysql2sqlite_unix_timestamp($date) {
	return strtotime($date);
}

function _mysql2sqlite_year($date) {
	return intval(date("Y",strtotime($date)));
}

function _mysql2sqlite_month($date) {
	return intval(date("m",strtotime($date)));
}

function _mysql2sqlite_week($date,$mode) {
	$mode=$mode*86400;
	return date("W",strtotime($date)+$mode);
}

function _mysql2sqlite_truncate($n,$d) {
	$d=pow(10,$d);
	return intval($n*$d)/$d;
}

function _mysql2sqlite_day($date) {
	return intval(date("d",strtotime($date)));
}

function _mysql2sqlite_dayofyear($date) {
	return date("z",strtotime($date))+1;
}

function _mysql2sqlite_dayofweek($date) {
	return date("w",strtotime($date))+1;
}

function _mysql2sqlite_hour($date) {
	return intval(date("H",strtotime($date)));
}

function _mysql2sqlite_minute($date) {
	return intval(date("i",strtotime($date)));
}

function _mysql2sqlite_second($date) {
	return intval(date("s",strtotime($date)));
}

function _mysql2sqlite_md5($temp) {
	return md5($temp);
}

function _mysql2sqlite_crc32($temp) {
	return sprintf("%u",crc32($temp));
}

function _mysql2sqlite_convert($sql,$bind) {
	if(_mysql2sqlite_debug()) _mysql2sqlite_log($sql);
	if(_mysql2sqlite_debug()) _mysql2sqlite_log($bind);
	if(!is_array($bind)) $bind=array($bind);
	if(stripos($sql,"show tables like")!==false) {
		$sql=str_ireplace("show tables like","select name from sqlite_master where type='table' and name like",$sql);
		$sql=str_replace("\\_","_",$sql);
	} elseif(stripos($sql,"show table status")!==false) {
		$sql=str_ireplace("show table status","select name from sqlite_master where type='table'",$sql);
		$sql=str_replace("\\_","_",$sql);
	} elseif(stripos($sql,"truncate")!==false) {
		$sql=str_ireplace("truncate","delete from",$sql);
	} elseif(stripos($sql,"on duplicate key")!==false) {
		$sql=str_ireplace("insert","insert or replace",$sql);
		$sql=substr($sql,0,stripos($sql,"on duplicate key"));
		$count=substr_count($sql,"?");
		$bind=array_slice($bind,0,$count);
	} elseif(stripos($sql,"insert ignore")!==false) {
		$sql=str_ireplace("insert ignore","insert or replace",$sql);
	} elseif(stripos($sql,"select")!==false && strpos($sql,":=")!==false && strpos($sql,"@")!==false) {
		$pos=strpos($sql,"@");
		while($pos!==false) {
			$pos2=$pos+1;
			while($sql[$pos2]!="=") $pos2++;
			$pos2++;
			while($sql[$pos2]==" ") $pos2++;
			while(!in_array($sql[$pos2],array(" ","\t","\n"))) $pos2++;
			$sql=substr($sql,0,$pos)."VARIABLE_EMULATION('".str_replace(array("@",":=","=","_"),array("$","_","__","="),substr($sql,$pos,$pos2-$pos))."')".substr($sql,$pos2);
			$pos=strpos($sql,"@");
		}
		// TODO: THIS LINES FIXES SOME BUGS THAT MUST BE FIXED IN THE CORE
		$sql=str_replace("log_action.name,","log_action.name as name,",$sql);
		$sql=str_replace("`type`","type",$sql);
		$sql=str_replace("`url_prefix`","url_prefix",$sql);
		// TODO: THIS LINES FIXES SOME BUGS THAT MUST BE FIXED IN THE CORE
	} elseif(stripos($sql,"create table")!==false && stripos($sql,"index ")!==false) {
		$pos=stripos($sql,"create table")+12;
		$pos2=strpos($sql,"(");
		$table=trim(substr($sql,$pos,$pos2-$pos));
		$indexes=array();
		$sql=explode("\n",$sql);
		foreach($sql as $key=>$val) {
			if(stripos($val,"index ")!==false) {
				$val=trim($val);
				if(substr($val,-1,1)==",") $val=substr($val,0,-1);
				$val=str_ireplace("index ","create index ${table}_",$val);
				$val=str_replace("("," ON ${table}(",$val);
				$indexes[]=$val;
				unset($sql[$key]);
			}
		}
		$sql=implode("\n",$sql);
		$pos=strrpos($sql,",");
		$sql=substr_replace($sql,"",$pos,1);
		$sql=str_ireplace("default charset=utf8","",$sql);
		$sql=array_merge(array("BEGIN"),array($sql),$indexes,array("COMMIT"));
	} elseif(stripos($sql,"select")!==false && stripos($sql,"union")!==false) {
		$unions=array();
		$orders=array();
		$limits=array();
		$pos=strpos($sql,"(");
		while($pos!==false) {
			$pos2=strpos($sql,")",$pos);
			$union=substr($sql,$pos+1,$pos2-$pos-1);
			$union=explode("\n",$union);
			foreach($union as $key=>$val) {
				if(stripos($val,"order by")!==false) {
					array_push($orders,trim(str_ireplace("order by","",$val)));
					unset($union[$key]);
				}
				if(stripos($val,"limit")!==false) {
					array_push($limits,trim(str_ireplace("limit","",$val)));
					unset($union[$key]);
				}
			}
			$union=implode("\n",$union);
			$unions[]=$union;
			$sql=substr_replace($sql,"",$pos,$pos2-$pos+1);
			$pos=strpos($sql,"(");
		}
		$sql=explode("\n",$sql);
		foreach($sql as $key=>$val) {
			if(stripos($val,"order by")!==false) {
				array_unshift($orders,trim(str_ireplace("order by","",$val)));
				unset($sql[$key]);
			}
			if(stripos($val,"limit")!==false) {
				array_unshift($limits,trim(str_ireplace("limit","",$val)));
				unset($sql[$key]);
			}
		}
		$orders=array_unique($orders);
		$limits=array_unique($limits);
		$sql="SELECT * FROM (".implode(" UNION ",$unions).") ORDER BY ".implode(",",$orders)." LIMIT ".implode(",",$limits);
	}
	if(_mysql2sqlite_debug()) _mysql2sqlite_log($sql);
	if(_mysql2sqlite_debug()) _mysql2sqlite_log($bind);
	return array($sql,$bind);
}

function _mysql2sqlite_usleep($usec) {
	$socket=socket_create(AF_UNIX,SOCK_STREAM,0);
	$read=null;
	$write=null;
	$except=array($socket);
	$time1=microtime(true);
	@socket_select($read,$write,$except,intval($usec/1000000),intval($usec%1000000));
	$time2=microtime(true);
	return ($time2-$time1)*1000000;
}

function _mysql2sqlite_log($data) {
	if(is_string($data)) {
		file_put_contents("log.txt",date("Y-m-d H:i:s").": ".$data."\n",FILE_APPEND);
	}
	if(is_array($data)) {
		foreach($data as $key=>$val) {
			$temp=count_chars($val,1);
			if(count($temp)) {
				if(min(array_keys($temp))<32) {
					$data[$key]="X'".bin2hex($val)."'";
				}
			}
		}
		file_put_contents("log.txt",date("Y-m-d H:i:s").": ".print_r($data,true)."\n",FILE_APPEND);
	}
}
?>