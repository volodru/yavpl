<?php
namespace YAVPL;
die('OBSOLETE');
/**
 * @NAME: DbMy
 * @DESC: MySQL wrapper
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2009- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** CHANGELOG


 * * 2023-04-24
 * расставлены type hints везде, куда можно
 * 1.00
 * выделен класс для работы с MySQL
 */

require_once('Db.php');

class DbMy extends Db implements iDb
{
	public $pg_dbh = 0;

	public function connect():void
	{
		$connect_string = "host={$this->host_params['host']} port={$this->host_params['port']} user={$this->host_params['user']} password={$this->host_params['passwd']} dbname={$this->host_params['dbname']} connect_timeout=5";

		$this->pg_dbh = @pg_connect($connect_string, PGSQL_CONNECT_FORCE_NEW)
		OR die("Cannot connect to PostgresQL base {$this->host_params['dbname']} (PGSQL_CONNECT_FORCE_NEW)");

		if (isset($this->host_params['exec_after_connect']))
		{
			@pg_query($this->pg_dbh, $this->host_params['exec_after_connect']);
		}
		//da(__getBackTrace());
		$this->is_connected = true;
	}

	public function disconnect():void
	{
		if ($this->is_connected)
		{
			@pg_close($this->pg_dbh);
			$this->pg_dbh = 0;
			$this->is_connected = false;
		}
	}

	public function exec():Db
	{
//имитация чего-то вроде $query, $params = array()
//либо $query, $param1, $param2,.., $paramN
		$num_args = func_num_args();
		$args = func_get_args();
		if ($num_args < 1)//аргументы вообще где-то потеряли
		{
			print "Query is undefined!";
			return $this;
		}
		$this->query = $args[0];//первый аргумент точно есть

		if ($this->query == '')//ну а вдруг он пустой?
		{
			print "Query is defined but empty!";
			return $this;
		}

		$this->params = [];//если параметров не было вообще, то будет пустой массив
		if (isset($args[1]))
		{
			if (is_array($args[1]))
			{
				$this->params = $args[1];
			}
			else
			{
				for ($i = 1; $i < $num_args; $i++)
				{
					$this->params[] = $args[$i];
				}
			}
		}
		//закончили разбор параметров

		if (strtoupper(trim($this->query)) == 'BEGIN')
		{
			$this->transaction_depth++;
		}
		if (strtoupper(trim($this->query)) == 'COMMIT')
		{
			$this->transaction_depth--;
		}

		$this->executed_sql_count++;
		$this->executed_sql_queries_per_session++;

		if ($this->is_connected &&
			$this->allow_reconnects &&
			$this->transaction_depth == 0 &&
			$this->executed_sql_queries_per_session >= $this->max_executed_sql_queries_per_session)
		{
			//print "reconnection at: ".$this->executed_sql_queries_per_session.CRLF;
			$this->disconnect();
			$this->connect();
			$this->executed_sql_queries_per_session = 0;
		}
		if (!$this->is_connected)
		{
			$this->connect();
		}
//---- СОБСТВЕННО ОБРАЩЕНИЕ К СУБД --
		$this->sth = (count($this->params) > 0) ?
			@pg_query_params($this->pg_dbh, $this->query, $this->params) : @pg_query($this->pg_dbh, $this->query);
//-----------------------------------
		if ($this->sth)
		{
			$this->row = 0;
			$this->rows = pg_numrows($this->sth);
		}
		else
		{
			$err_msg = pg_last_error($this->pg_dbh);
			$notice_msg = pg_last_notice($this->pg_dbh);
			$current_time = date('Y/m/d H:i:s', time());
			$url = "http://".$_SERVER['SERVER_NAME'].$_SERVER['SCRIPT_NAME'];
			if ($_SERVER['QUERY_STRING']) $url .= '?'.$_SERVER['QUERY_STRING'];

			$ref = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER']:'';
			$ref = urldecode($ref);

			ob_start();
			debug_print_backtrace();
			$backtrace = ob_get_contents();
			ob_end_clean();

			$debug_info = "
<h3>Critical error</h3>{$current_time}
<br />URL {$url} (URI {$_SERVER['REQUEST_URI']})
<br />AGENT: {$_SERVER['HTTP_USER_AGENT']}
<br />REFERER: {$ref}
<br />query:
<br /><xmp>QUERY: ".htmlspecialchars($this->query)."</xmp>
<br /><xmp>PARAMS: ".print_r($this->params, true)."</xmp>
<br />PostgresQL's response:
<div style='color: #F00; padding: 5px;'>{$err_msg}</div>
<div style='color: #0FF; padding: 5px;'>{$notice_msg}</div>
<div style='color: #00F; padding: 5px;'><pre>{$backtrace}</pre></div>
<div><xmp>SESSION: ".print_r($_SESSION, true)."</xmp></div>
<div><xmp>SERVER: ".print_r($_SERVER, true)."</xmp></div>
";

			if (APPLICATION_ENV == 'production')
			{
				if ($this->show_error_messages)
				{
					$user_message = "
<h1>Ошибка в запросе</h1>
<h2>Ваш запрос</h2>
<xmp>".htmlspecialchars($this->query)."</xmp>
<h2>Ответ от базы данных</h2>
<xmp style='color: #F00;'>$err_msg</xmp>
<xmp style='color: #0FF;'>$notice_msg</xmp>";
				}
				else
				{
					mail(ADMIN_EMAIL, 'Critical error on '.$_SERVER['SERVER_NAME'], strip_tags($debug_info));
					$user_message = 'Fatal DB error occured. eMail to the system administrator already has been sent.';
				}
			}
			else
			{
				$user_message = $debug_info;
			}
			die($user_message);
		}

//чтобы память не переполнять, $save_executed_sql выключен. для дебагов - включить в прототипе модели проекта class MainModel{}
//$this->db->save_executed_sql = true;
		if ($this->save_executed_sql && $this->executed_sql_count < MAX_REGISTER_EXECUTED_SQL_COUNT)
		{
			$explain = '';
			$query = preg_replace("/^(\s+)/m",'', $this->query);
			if ((preg_match("/^\s*(SELECT)/sim", $query)) && (!preg_match("/^\s*BEGIN/sim", $query)))
			{//logging -------------------------------
				$sth = (count($this->params) > 0) ?
					pg_query_params($this->pg_dbh, "EXPLAIN $query ", $this->params) :
					pg_query($this->pg_dbh, "EXPLAIN $query ");

				$rows = pg_numrows($sth);

				for($r = 0; $r < $rows; $r++)
				{
					list($tmp) = pg_fetch_array($sth, $r);
					$explain .= "$tmp\n";
				}
				$this->explain = $explain;

				$res2 = $res1 = [];
				preg_match("/offset\s+(\d+)/sim", $query, $res1);
				//preg_match("/(sort)/sim", $explain, $res1);
				preg_match("/cost=(\d+)\.\d+\.\.\d+\.\d+/", $explain, $res2);
				//preg_match("/Total runtime: (\d+)\.(\d+) msec/", $explain, $res);
				if (isset($res2[1]) && ($res2[1] > $this->min_cost_to_save_log) &&(!(isset($res1[1])) ||((isset($res1[1])) && ($res1[1]>=0))))
				{
					$a = explode('.', $_SERVER['SERVER_NAME']);
					$fn = (isset($a[count($a)-2])) ? $a[count($a)-2] : $_SERVER['SERVER_NAME'];
					if ($f = fopen($this->log_path."long_sqls_$fn.log",'a'))
					{
						fwrite($f, "
#----------------------------------------------------
#	".date("Y/m/d  H:i:s")."
#	{$_SERVER['PHP_SELF']} {$_SERVER['REQUEST_URI']}
#	{$_SERVER['SERVER_NAME']}	{$_SERVER['REMOTE_ADDR']}
ref: ".urldecode(isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER']:'')."
agent: {$_SERVER['HTTP_USER_AGENT']}
$query
".((count($this->params) > 0) ? "
PARAMS: ".print_r($this->params, true) : '').$explain);
						fclose($f);
					}
				}
				//!!!!!!!!!!!!!
				//EXTREMELY DANGEROUS!!!!
				//$this->executed_sql[] = "$query\n$explain";
				//!!!!!!!!!!!!!
				// end logging -------------------------------
			}
			//global $executed_sql;
			global $application;
			//$executed_sql[] = $query.
			$application->executed_sql[] = $query.
				"\nQuery returs {$this->rows} row(s)".
				((count($this->params) > 0)?"\nPARAMS: ".print_r($this->params, true):'').
				(($explain != '') ? "\n-----------\n$explain-----------" : '');
		}
		return $this;
	}//exec

/**
 * Получить следующее значение из сиквенсы
 */
	public function nextVal($sequence_name): int
	{
		if (!$this->is_connected)
		{
			$this->connect();
		}
		list($i) = pg_fetch_array(pg_query($this->pg_dbh,
			"SELECT nextval('$sequence_name')"), 0, PGSQL_NUM);
		return $i;
	}

/** Все результаты в таблицу, как правило массив структур
 *
 */
	public function fetchAll($type = PGSQL_ASSOC): array
	{
	}

/**
 * Извлечение очередной строки результата, как правило в виде структуры
 *
 */
	public function fetchRow(): array/*typical usage: while ($r = $db->fetchRow()) {*/
	{
		return $this->row < $this->rows ? pg_fetch_array($this->sth, $this->row++, PGSQL_ASSOC) : false;
	}

/**
 * Извлечение строки в массив
 * typical usage: list($var1, $var2) = $db->fetchRowArray();
 */
	public function fetchRowArray()
	{
		return $this->row < $this->rows ? pg_fetch_array($this->sth, $this->row++, PGSQL_NUM) : false;
	}

/**
 * Количество строк подвергнутых коррекции по ins, upd, del
 */
	public function affectedRows(): int
	{
		return pg_affected_rows($this->sth);
	}

/**
 * FOR DEBUG: Вывести красиво сам запрос и его query-plan
 */
	public function print_r(): Db
	{
		if (!$this->is_connected)
		{
			$this->connect();
		}
		$explain = '';
		if (
				(preg_match("/^\s*(SELECT)/sim", $this->query))
				&& (! preg_match("/^\s*BEGIN/sim", $this->query))
			)
		{
			$sth = (count($this->params) > 0) ?
			pg_query_params($this->pg_dbh, "EXPLAIN {$this->query} ", $this->params) :
			pg_query($this->pg_dbh, "EXPLAIN {$this->query} ");
			$rows = pg_numrows($sth);
			$explain = '';
			for($r = 0; $r < $rows; $r++)
			{
				list($tmp) = pg_fetch_array($sth, $r);
				$explain .= "$tmp\n";
			}
		}
		print "<xmp>";
		print_r($this->query);
		print "\n-------------------------\n";
		print_r($explain);
		if (count($this->params) > 0)
		{
			print "\n-------------------------\nPARAMS: ".var_export($this->params, true);
		}
		print "</xmp>";
		return $this;
	}
}

class DbPgSingleton extends DbPg //singleton in the global scope
{
	protected static $instance;
	public static function getInstance($host_params) {// Возвращает единственный экземпляр класса
		if (is_null(self::$instance))
		{
			self::$instance = new DbPg($host_params);
		}
		return self::$instance;
	}
}