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

		require_once("libs/mysql2sqlite.php");
		_mysql2sqlite_connect($this->_connection);

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
		// NOTHING TO DO
	}

	/**
	 * Check client version compatibility against database server
	 *
	 * @throws Exception
	 */
	public function checkClientVersion()
	{
		// NOTHING TO DO
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
		if(!is_array($errno)) $errno=array($errno);
		$error=$e->getMessage();
		$error=explode(":",$error);
		$error=array_pop($error);
		$error=trim($error);
		$error=explode(" ",$error);
		$error=array_shift($error);
		return in_array($error,$errno);
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
		if(is_string($sql))
		{
			list($sql,$bind)=_mysql2sqlite_convert($sql,$bind);
		}

		if(_mysql2sqlite_semaphore_acquire())
		{
			$timeout=10000000;
			while(1) {
				try {
					if(is_array($sql)) {
						foreach($sql as $query) $stmt=$this->_connection->query($query);
					} elseif(isset($this->cachePreparedStatement[$sql])) {
						$stmt = $this->cachePreparedStatement[$sql];
						$stmt->execute($bind);
					} else {
						$stmt=$this->_connection->prepare($sql);
						$this->cachePreparedStatement[$sql] = $stmt;
						$stmt->execute($bind);
					}
					break;
				} catch (PDOException $e) {
					if($timeout<=0) {
						_mysql2sqlite_log("throw: ".$e->getMessage());
						throw new Exception($e->getMessage());
					} elseif($this->isErrNo($e,5)) {
						$timeout-=_mysql2sqlite_usleep(rand(0,1000));
					} elseif($this->isErrNo($e,17)) {
						unset($this->cachePreparedStatement[$sql]);
						$timeout-=_mysql2sqlite_usleep(rand(0,1000));
					} else {
						_mysql2sqlite_log("throw: ".$e->getMessage());
						throw new Exception($e->getMessage());
					}
				}
			}
			_mysql2sqlite_semaphore_release();
			return $stmt;
		}
		throw new Exception("Can not acquire semaphore");
	}
}
?>