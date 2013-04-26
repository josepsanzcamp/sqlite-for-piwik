<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 * @version $Id: Sqlite.php 7433 2012-11-11 10:52:45Z matt $
 *
 * @category Piwik
 * @package Piwik
 */

/**
 * @package Piwik
 * @subpackage Piwik_Db
 */
class Piwik_Db_Adapter_Pdo_Sqlite extends Zend_Db_Adapter_Pdo_Sqlite implements Piwik_Db_Adapter_Interface
{
	/**
	 * Constructor
	 *
	 * @param array|Zend_Config  $config  database configuration
	 */
	public function __construct($config)
	{
		parent::__construct($config);
	}

	/**
	 * Returns connection handle
	 *
	 * @return resource
	 */
	public function getConnection()
	{
		if($this->_connection)
		{
			return $this->_connection;
		}

		$this->_connect();

		// for compatibility with MySQL queries
		$this->_connection->sqliteCreateFunction("GET_LOCK",array($this, '_get_lock'));
		$this->_connection->sqliteCreateFunction("RELEASE_LOCK",array($this, '_release_lock'));

		/**
		 * for compatibility with MySQL queries
		 * copied from code/database/pdo_sqlite.php of the SaltOS project
		 */
		$this->_connection->query("PRAGMA cache_size=2000");
		$this->_connection->query("PRAGMA synchronous=OFF");
		$this->_connection->query("PRAGMA count_changes=OFF");
		$this->_connection->query("PRAGMA foreign_keys=OFF");
		$this->_connection->sqliteCreateAggregate("GROUP_CONCAT",array($this,"_group_concat_step"),array($this,"_group_concat_finalize"));
		$this->_connection->sqliteCreateFunction("LPAD",array($this,"_lpad"));
		$this->_connection->sqliteCreateFunction("CONCAT",array($this,"_concat"));
		$this->_connection->sqliteCreateFunction("UNIX_TIMESTAMP",array($this,"_unix_timestamp"));
		$this->_connection->sqliteCreateFunction("YEAR",array($this,"_year"));
		$this->_connection->sqliteCreateFunction("MONTH",array($this,"_month"));
		$this->_connection->sqliteCreateFunction("WEEK",array($this,"_week"));
		$this->_connection->sqliteCreateFunction("TRUNCATE",array($this,"_truncate"));
		$this->_connection->sqliteCreateFunction("DAY",array($this,"_day"));
		$this->_connection->sqliteCreateFunction("DAYOFYEAR",array($this,"_dayofyear"));
		$this->_connection->sqliteCreateFunction("DAYOFWEEK",array($this,"_dayofweek"));
		$this->_connection->sqliteCreateFunction("HOUR",array($this,"_hour"));
		$this->_connection->sqliteCreateFunction("MINUTE",array($this,"_minute"));
		$this->_connection->sqliteCreateFunction("SECOND",array($this,"_second"));
		$this->_connection->sqliteCreateFunction("MD5",array($this,"_md5"));

		return $this->_connection;
	}

	/**
	 * Reset the configuration variables in this adapter.
	 */
	public function resetConfig()
	{
		$this->_config = array();
	}

	/**
	 * Return default port.
	 *
	 * @return int
	 */
	public static function getDefaultPort()
	{
		return null;
	}

	/**
	 * Check SQLite version
	 *
	 * @throws Exception
	 */
	public function checkServerVersion()
	{
		// TODO BY SANZ
	}

	/**
	 * Check client version compatibility against database server
	 *
	 * @throws Exception
	 */
	public function checkClientVersion()
	{
		// TODO BY SANZ
	}

	/**
	 * Returns true if this adapter's required extensions are enabled
	 *
	 * @return bool
	 */
	public static function isEnabled()
	{
		$extensions = @get_loaded_extensions();
		return in_array('PDO', $extensions) && in_array('pdo_sqlite', $extensions) && in_array('sqlite', PDO::getAvailableDrivers());
	}

	/**
	 * Returns true if this adapter supports blobs as fields
	 *
	 * @return bool
	 */
	public function hasBlobDataType()
	{
		return true;
	}

	/**
	 * Returns true if this adapter supports bulk loading
	 *
	 * @return bool
	 */
	public function hasBulkLoader()
	{
		return true;
	}

	/**
	 * Test error number
	 *
	 * @param Exception  $e
	 * @param string     $errno
	 * @return bool
	 */
	public function isErrNo($e, $errno)
	{
		if(preg_match('/(?:\[|\s)([0-9]{4})(?:\]|\s)/', $e->getMessage(), $match))
		{
			return $match[1] == $errno;
		}
		return false;
	}

	/**
	 * Is the connection character set equal to utf8?
	 *
	 * @return bool
	 */
	public function isConnectionUTF8()
	{
		return true;
	}

	private $cachePreparedStatement = array();

	/**
	 * Prepares and executes an SQL statement with bound data.
	 * Caches prepared statements to avoid preparing the same query more than once
	 *
	 * @param string|Zend_Db_Select  $sql   The SQL statement with placeholders.
	 * @param array                  $bind  An array of data to bind to the placeholders.
	 * @return Zend_Db_Statement_Interface
	 */
	public function query($sql, $bind = array())
	{
		//~ file_put_contents("log.txt",$sql."\n",FILE_APPEND);
		//~ file_put_contents("log.txt",print_r($bind,true)."\n",FILE_APPEND);

		// for compatibility with MySQL queries
		if(is_string($sql)) {
			if(stripos($sql,"on duplicate key")!==false) {
				list($sql, $bind) = $this->_onDuplicateKey($sql, $bind);
			}
			if(stripos($sql,"insert ignore")!==false) {
				$sql=str_ireplace("insert ignore","insert or replace",$sql);
			}
			if(strpos($sql,":=")!==false && strpos($sql,"@")!==false) {
				$sql=""; // TODO BY SANZ
			}
		}

		//~ file_put_contents("log.txt",$sql."\n",FILE_APPEND);
		//~ file_put_contents("log.txt",print_r($bind,true)."\n",FILE_APPEND);

		if(!is_string($sql))
		{
			if($this->_semaphore_acquire("tmp/sqlite.sem")) {
				$result=parent::query($sql, $bind);
				$this->_semaphore_release("tmp/sqlite.sem");
				return $result;
			}
			return null;
		}

		if(isset($this->cachePreparedStatement[$sql]))
		{
			if (!is_array($bind)) {
				$bind = array($bind);
			}

			if($this->_semaphore_acquire("tmp/sqlite.sem")) {
				$stmt = $this->cachePreparedStatement[$sql];
				$stmt->execute($bind);
				$this->_semaphore_release("tmp/sqlite.sem");
				return $stmt;
			}
			return null;
		}

		if($this->_semaphore_acquire("tmp/sqlite.sem")) {
			$stmt = parent::query($sql, $bind);
			$this->cachePreparedStatement[$sql] = $stmt;
			$this->_semaphore_release("tmp/sqlite.sem");
			return $stmt;
		}
		return null;
	}

	/**
	 * translate the 'ON DUPLICATE KEY UPDATE' MySQL feature to SQLite
	 */

	private function _onDuplicateKey($sql, $bind)
	{
		preg_match('/ on duplicate key update.*$/si', $sql, $matches);
		$clause = $matches[0];
		$numParams = substr_count($clause, '?');
		$bind = array_slice($bind, 0, -$numParams);

		$sql = str_replace($clause, '', $sql);
		$sql = preg_replace('/^insert /i', 'insert or replace ', $sql);

		return array($sql, $bind);
	}

	/**
	 * for compatibility with MySQL queries
	 * copied from code/php/functions.php of the SaltOS project
	 */
	public function _get_lock() {
		// NOTHING TO DO
	}

	public function _release_lock() {
		// NOTHING TO DO
	}

	private function _semaphore_acquire($file,$timeout=100000) {
		global $_SEMAPHORE;
		if(!isset($_SEMAPHORE)) $_SEMAPHORE=array();
		$hash=md5($file);
		if(!isset($_SEMAPHORE[$hash])) $_SEMAPHORE[$hash]=null;
		srand((float)microtime(true)*1000000);
		while($timeout>=0) {
			if(!$_SEMAPHORE[$hash]) break;
			$usleep=rand(0,1000);
			usleep($usleep);
			$timeout-=$usleep;
		}
		if($timeout<0) {
			return 0;
		}
		while($timeout>=0) {
			$_SEMAPHORE[$hash]=@fopen($file,"a");
			if($_SEMAPHORE[$hash]) break;
			$usleep=rand(0,1000);
			usleep($usleep);
			$timeout-=$usleep;
		}
		if($timeout<0) {
			return 0;
		}
		@chmod($file,0666);
		@touch($file);
		while($timeout>=0) {
			$result=@flock($_SEMAPHORE[$hash],LOCK_EX|LOCK_NB);
			if($result) break;
			$usleep=rand(0,1000);
			usleep($usleep);
			$timeout-=$usleep;
		}
		if($timeout<0) {
			if($_SEMAPHORE[$hash]) {
				@fclose($_SEMAPHORE[$hash]);
				$_SEMAPHORE[$hash]=null;
			}
			return 0;
		}
		return 1;
	}

	private function _semaphore_release($file) {
		global $_SEMAPHORE;
		$hash=md5($file);
		if($_SEMAPHORE[$hash]) {
			@flock($_SEMAPHORE[$hash],LOCK_UN);
			@fclose($_SEMAPHORE[$hash]);
			$_SEMAPHORE[$hash]=null;
		} else {
			return 0;
		}
		return 1;
	}

	/**
	 * for compatibility with MySQL queries
	 * copied from code/database/pdo_sqlite.php of the SaltOS project
	 */
	public function _group_concat_check() {
		$query="SELECT GROUP_CONCAT(1)";
		capture_next_error();
		db_query_pdo_sqlite($query);
		$error=get_clear_error();
		return !$error?true:false;
	}

	public function _group_concat_step($context,$rows,$string,$separator=",") {
		if($context!="") $context.=$separator;
		$context.=$string;
		return $context;
	}

	public function _group_concat_finalize($context,$rows) {
		return $context;
	}

	public function _lpad($input,$length,$char) {
		return str_pad($input,$length,$char,STR_PAD_LEFT);
	}

	public function _concat() {
		$array=func_get_args();
		return implode("",$array);
	}

	public function _unix_timestamp($date) {
		return strtotime($date);
	}

	public function _year($date) {
		return intval(date("Y",strtotime($date)));
	}

	public function _month($date) {
		return intval(date("m",strtotime($date)));
	}

	public function _week($date,$mode) {
		$mode=$mode*86400;
		return date("W",strtotime($date)+$mode);
	}

	public function _truncate($n,$d) {
		$d=pow(10,$d);
		return intval($n*$d)/$d;
	}

	public function _day($date) {
		return intval(date("d",strtotime($date)));
	}

	public function _dayofyear($date) {
		return date("z",strtotime($date))+1;
	}

	public function _dayofweek($date) {
		return date("w",strtotime($date))+1;
	}

	public function _hour($date) {
		return intval(date("H",strtotime($date)));
	}

	public function _minute($date) {
		return intval(date("i",strtotime($date)));
	}

	public function _second($date) {
		return intval(date("s",strtotime($date)));
	}

	public function _md5($temp) {
		return md5($temp);
	}

}