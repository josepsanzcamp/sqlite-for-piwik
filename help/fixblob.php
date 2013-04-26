<?php
ini_set("session.bug_compat_42","On");
ini_set("register_globals","Off");
ini_set("memory_limit","1024M");
ini_set("max_execution_time","600");
ini_set("date.timezone","Europe/Madrid");
ini_set("default_charset","UTF-8");

if(!isset($argv[1])) die();
if(!file_exists($argv[1])) die();
if(!isset($argv[2])) die();

$file=file_get_contents($argv[1]);
$file=explode("\n",$file);

foreach($file as $key=>$val) {
	$pos=strpos($val,",0x");
	if($pos===false) $pos=strpos($val,"(0x");
	while($pos!==false) {
		$val=substr_replace($val,"X'",$pos+1,2);
		$pos2=strpos($val,",",$pos+1);
		if($pos2===false) $pos2=strpos($val,")",$pos);
		$val=substr_replace($val,"'",$pos2,0);
		$pos=strpos($val,",0x",$pos2);
		if($pos===false) $pos=strpos($val,"(0x",$pos2);
	}
	$file[$key]=$val;
}

$file=implode("\n",$file);
file_put_contents($argv[2],$file);

?>
