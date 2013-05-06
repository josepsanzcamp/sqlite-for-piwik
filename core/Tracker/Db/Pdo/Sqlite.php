<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik
 * @package Piwik
 */

/**
 * PDO SQLite wrapper
 *
 * @package Piwik
 * @subpackage Piwik_Tracker
 */
class Piwik_Tracker_Db_Pdo_Sqlite extends Piwik_Tracker_Db
{
	protected $connection = null;
	protected $dsn;

	/**
	 * Builds the DB object
	 *
	 * @param array   $dbInfo
	 * @param string  $driverName
	 */
	public function __construct( $dbInfo, $driverName = 'sqlite')
	{
		$this->dsn = $driverName . ':' . $dbInfo['dbname'];
	}

	public function __destruct()
	{
		$this->connection=null;
	}

	/**
	 * Connects to the DB
	 *
	 * @throws Exception if there was an error connecting the DB
	 */
	public function connect()
	{
		if($this->connection)
		{
			return;
		}

		if(self::$profiling)
		{
			$timer = $this->initProfiler();
		}

		$this->connection = @new PDO($this->dsn);

		if(self::$profiling)
		{
			$this->recordQueryProfile('connect', $timer);
		}

		require_once("libs/mysql2sqlite.php");
		_mysql2sqlite_connect($this->connection);
	}

	/**
	 * Disconnects from the server
	 */
	public function disconnect()
	{
		$this->connection = null;
	}

	/**
	 * Returns an array containing all the rows of a query result, using optional bound parameters.
	 *
	 * @param string  $query       Query
	 * @param array   $parameters  Parameters to bind
	 * @return array|bool
	 * @see query()
	 * @throws Exception|Piwik_Tracker_Db_Exception if an exception occurred
	 */
	public function fetchAll( $query, $parameters = array() )
	{
		try {
			$stmt = $this->query( $query, $parameters );
			if($stmt === false)
			{
				return false;
			}
			return $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			throw new Piwik_Tracker_Db_Exception("Error query: ".$e->getMessage());
		}
	}

	/**
	 * Returns the first row of a query result, using optional bound parameters.
	 *
	 * @param string  $query Query
	 * @param array   $parameters Parameters to bind
	 * @return bool|mixed
	 * @see query()
	 * @throws Exception|Piwik_Tracker_Db_Exception if an exception occurred
	 */
	public function fetch( $query, $parameters = array() )
	{
		try {
			$stmt = $this->query( $query, $parameters );
			if($stmt === false)
			{
				return false;
			}
			return $stmt->fetch(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			throw new Piwik_Tracker_Db_Exception("Error query: ".$e->getMessage());
		}
	}

	private $cachePreparedStatement = array();

	/**
	 * Executes a query, using optional bound parameters.
	 *
	 * @param string        $sql       Query
	 * @param array|string  $bind  Parameters to bind array('idsite'=> 1)
	 * @return PDOStatement|bool  PDOStatement or false if failed
	 * @throws Piwik_Tracker_Db_Exception if an exception occured
	 */
	public function query($sql, $bind = array())
	{
		if(is_null($this->connection))
		{
			return false;
		}

		if(is_string($sql))
		{
			list($sql,$bind)=_mysql2sqlite_convert($sql,$bind);
		}

		if(_mysql2sqlite_semaphore_acquire())
		{
			$timeout=10000000;
			while(1) {
				try {
					if(self::$profiling) $timer = $this->initProfiler();
					if(is_array($sql)) {
						foreach($sql as $query) $stmt=$this->connection->query($query);
					} elseif(isset($this->cachePreparedStatement[$sql])) {
						$stmt = $this->cachePreparedStatement[$sql];
						$stmt->execute($bind);
					} else {
						$stmt=$this->connection->prepare($sql);
						$this->cachePreparedStatement[$sql] = $stmt;
						$stmt->execute($bind);
					}
					if(self::$profiling) $this->recordQueryProfile($sql, $timer);
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

	/**
	 * Returns the last inserted ID in the DB
	 * Wrapper of PDO::lastInsertId()
	 *
	 * @return int
	 */
	public function lastInsertId()
	{
		return $this->connection->lastInsertId();
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
	 * Return number of affected rows in last query
	 *
	 * @param mixed  $queryResult  Result from query()
	 * @return int
	 */
	public function rowCount($queryResult)
	{
		return $queryResult->rowCount();
	}
}
?>