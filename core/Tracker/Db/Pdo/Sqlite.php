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
			$sth = $this->query( $query, $parameters );
			if($sth === false)
			{
				return false;
			}
			return $sth->fetchAll(PDO::FETCH_ASSOC);
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
			$sth = $this->query( $query, $parameters );
			if($sth === false)
			{
				return false;
			}
			return $sth->fetch(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			throw new Piwik_Tracker_Db_Exception("Error query: ".$e->getMessage());
		}
	}

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

		try {
			if(self::$profiling)
			{
				$timer = $this->initProfiler();
			}

			if(!is_array($bind))
			{
				$bind = array( $bind );
			}

			if(_mysql2sqlite_semaphore_acquire(_mysql2sqlite_semaphore_file(),_mysql2sqlite_semaphore_timeout()))
			{
				if(is_array($sql))
				{
					$sth=$this->connection->prepare(array_shift($sql));
					$sth->execute($bind);
					foreach($sql as $query) $this->connection->query($query);
				}
				else
				{
					$sth=$this->connection->prepare($sql);
					$sth->execute($bind);
				}
				if(self::$profiling)
				{
					$this->recordQueryProfile($sql, $timer);
				}
				_mysql2sqlite_semaphore_release(_mysql2sqlite_semaphore_file());
				return $sth;
			}
			throw new Exception("Can not acquire semaphore "._mysql2sqlite_semaphore_file());
		} catch (PDOException $e) {
			throw new Piwik_Tracker_Db_Exception("Error query: ".$e->getMessage() . "
								In query: $sql
								Parameters: ".var_export($bind, true));
		}
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
		if(preg_match('/([0-9]{4})/', $e->getMessage(), $match))
		{
			return $match[1] == $errno;
		}
		return false;
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