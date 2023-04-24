<?php
/**
 * @NAME: Db, iDb
 * @DESC: Database abstract layer
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2009- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** CHANGELOG
 *
 * 2023-04-24
 * расставлены type hints везде, куда можно

 * 1.09
 * DATE: 2020-05-06
 * $executed_sql - теперь в глобальной переменной $application
 *
 * 1.08
 * DATE: 2017-09-30
 * Фатальные ошибки теперь генерят исключение. Перехват их дело добровольное.
 *
 */

if (!defined('REMOTE_ADDR'))
{//используется в основном с отладочными целями
	define('REMOTE_ADDR', ( isset($_SERVER['HTTP_X_FOREIGN_IP']) ) ? $_SERVER['HTTP_X_FOREIGN_IP'] : $_SERVER['REMOTE_ADDR']);
}

DEFINE('MAX_REGISTER_EXECUTED_SQL_COUNT', 100);

class Db
{
	public $executed_sql_count = 0;
	public $save_executed_sql = false;
	public $log_path = '/tmp/';
	public $min_cost_to_save_log = 300;
	public $sth = false;
	public $rows;//number of rows after executing SELECT, for upd,del,ins results - see affectedRows()

	protected $host_params = [];//параметры подключения. устанавляваются в контрукторе, использу.тся в
	protected $executed_sql_queries_per_session = 0;//счетчик, сколько запросов делать до реконнекта
	protected $max_executed_sql_queries_per_session = 50000;//Pg memory leaks fighting :)
	protected $transaction_depth = 0; // begin++, commit--, reconnect when $transaction_depth==0
	protected $allow_reconnects = true;//disableReconnects() если оно не надо или глючит
	protected $is_connected = false;//lazy evaluation, коннектимся не в конструкторе, а по мере необходимости.
	//если Db будет синглтон, то все упоминания is_connected можно убрать, но Db не всегда имеет смысл делать синглтоном

	protected $show_error_messages = false; //mean on production. on develop - always show errors.

/** передаются параметры подключения к серверу в виде красивого хеша.
 *  чего с ним потом делать решает ->connect() метод для конкретной базы
 */
	public function __construct(array $host_params)
	{
		$this->host_params = $host_params;
		//!!!!!!!$this->connect() - используем lazy evaluation, коннектимся не в конструкторе, а только по мере необходимости.
	}

/** если надо преждевременно оторваться от БД, можно просто сделать unset($this->db) в модели
 */
	public function __destruct()
	{
		$this->disconnect();
	}

//	public function disconnect()	{	}

/** просто выключатель поля
 */
	public function disableReconnects():void
	{
		$this->allow_reconnects = false;
	}

/** просто включатель поля
 */
	public function enableErorrMessages():void
	{
		$this->show_error_messages = true;
	}

/** паблик Морозов
 */
	public function getHostParams():array
	{
		return $this->host_params;
	}

/**
 * Выполнение запроса к базе.
 * Здесь разбираем параметры, делаем всякую общую работу безотносительно к какой базе обращаемся.
 * После вызова этого метода наследник собственно обращается к базе и делает в нее запрос.
 */
	public function exec(): Db
	{
		//имитация чего-то вроде $query, $params = array()
		//либо $query, $param1, $param2,.., $paramN
		//$num_args = func_num_args();
		$args = func_get_args()[0];
		//da($args);
		//da($num_args);

		if (count($args) < 1)//аргументы вообще где-то потеряли
		{
			print "Query is undefined!";
			return $this;
		}

		$this->query = array_shift($args);//уж тут первый аргумент теперь точно есть
		//da($this->query);

		if ($this->query == '')//ну а вдруг он пустой?
		{
			print "Query is defined but empty!";
			return $this;
		}

		$this->params = [];//если параметров не было вообще, то будет пустой массив
		if (isset($args[0]))
		{
			if (is_array($args[0]))
			{
				$this->params = $args[0];//передали массив - хорошо
			}
			else
			{//передали много аргументов - собираем из них тот же массив
				//for ($i = 1; $i < count($args); $i++)
				foreach ($args as $arg)
				{
					$this->params[] = $arg;
				}
			}
		}
		//закончили разбор параметров

		//пытаемся не разрывать транзакции реконнектами, тупо смотрим на ключевое слово в запросе
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
		return $this;
	}

/**
 * Ошибки базы всегда фатальны!
 * Выводим сообщение от СУБД и трассировку вызова.
 * Либо выводим сразу на экран, либо отдаем оформленное сообщение в кидаемый Exception
 * @param $err_msg string сообщение об ошибке
 * @param $notice_msg string замечание от базы (для PG - pg_last_notice)
 */
	public function showErrorMessage($err_msg, $notice_msg):void
	{
		$current_time = date('Y/m/d H:i:s');
		$url = "http://".$_SERVER['SERVER_NAME'].$_SERVER['SCRIPT_NAME'];

		if ($_SERVER['QUERY_STRING']) {$url .= '?'.$_SERVER['QUERY_STRING'];}

		ob_start();
		debug_print_backtrace();
		$backtrace = ob_get_contents();
		ob_end_clean();

		$debug_info = "
		<h3>Critical error</h3>{$current_time}
		<br />URL {$url} (URI {$_SERVER['REQUEST_URI']})
		<br />AGENT: {$_SERVER['HTTP_USER_AGENT']}
		<br />REFERER: ".urldecode($_SERVER['HTTP_REFERER'] ?? '')."
		<br />QUERY ON HOST {$this->host_params['host']}:{$this->host_params['port']} TO DB [{$this->host_params['dbname']}] AS USER [{$this->host_params['user']}]:
		<br /><xmp>QUERY: ".htmlspecialchars($this->query)."</xmp>
		<br /><xmp>PARAMS: ".print_r($this->params, true)."</xmp>
		<br />PostgresQL's response:
		<div style='color: #F00; padding: 5px;'>{$err_msg}</div>
		<div style='color: #0FF; padding: 5px;'>{$notice_msg}</div>
		<div style='color: #00F; padding: 5px;'><pre>{$backtrace}</pre></div>
";//<div><xmp>SERVER: ".print_r($_SERVER, true)."</xmp></div>
		if (isset($_SESSION))
		{
			$debug_info .= "<div><xmp>SESSION: ".print_r($_SESSION, true)."</xmp></div>";
		}

		if ($this->show_error_messages)
		{
//@TODO: это мутный момент когда и как показывать ошибки БД для случаев,
//когда они допустимы.
//и решить что это за случаи.
			print "
<h1>Ошибка в запросе</h1>
<h2>Ваш запрос</h2>
<xmp>".htmlspecialchars($this->query)."</xmp>
<h2>Ответ от базы данных</h2>
<xmp style='color: #F00;'>{$err_msg}</xmp>
<xmp style='color: #0FF;'>{$notice_msg}</xmp>";
		}
		throw new Exception($debug_info);
	}

/**
 * Вставка записи в базу.
 * Делается универсально через SQL запрос, т.к. это чистый SQL92, то подходит для всех рапперов к базам
 * @param $keys - ключевые поля - массив или строка через запятую
 * @param $fields - остальные поля  - массив или строка через запятую
 * @param $data - hash с данными вида ключ (поле) -- значение
 */
	public function insert($table, $keys, $fields, array $data):Db
	{
		if (!is_array($keys))
		{
			$keys = preg_split("/\s*,\s*/", $keys, -1, PREG_SPLIT_NO_EMPTY);
		}
		if (!is_array($fields))
		{
			$fields = preg_split("/\s*,\s*/", $fields, -1, PREG_SPLIT_NO_EMPTY);
		}
//$fields - array with names of data fields
//$data - hash
		$p = [];//place holders
		$v = [];//linear array of data
		$ff = [];//fields
		$i = 1;
   		foreach (array_merge($keys, $fields) as $f)
		{
			$p[] = '$'.$i;
			$ff[] = $f;
			$v[] = (isset($data[$f])) ? $data[$f] : null;
			$i++;
		}
		return $this->exec("-- ".get_class($this).", method: ".__METHOD__."
INSERT INTO $table (".join(', ', $ff).") VALUES (".join(', ', $p).")", $v);//->print_r();
	}

/**
 * Изменение
 * параметры - см. Вставку / Insert
 */
	public function update($table, $keys, $fields, array $data):Db
	{
		if (!is_array($keys))
		{
			$keys = preg_split("/\s*,\s*/", $keys, -1, PREG_SPLIT_NO_EMPTY);
		}
		if (!is_array($fields))
		{
			$fields = preg_split("/\s*,\s*/", $fields, -1, PREG_SPLIT_NO_EMPTY);
		}

		$v = [];//linear array of data
		$s = [];// for SET with place holders
		$i = 1;//for both "foreach" cycles!
		foreach ($fields as $f)
		{
			$s[] = "$f = \$$i";
			$v[] = (isset($data[$f])) ? $data[$f] : null;
			$i++;
		}
		$w = [];// for where
		foreach ($keys as $f)
		{
			$w[] = "$f = \$$i";
			$v[] = (isset($data[$f])) ? $data[$f] : null;
			$i++;
		}
		return $this->exec("-- ".get_class($this).", method: ".__METHOD__."
UPDATE $table SET ".join(', ', $s)." WHERE (".join(') AND (', $w).")", $v);
	}

/**
 * Удаление
 * параметры - см. Вставку / Insert
 */
	public function delete($table, $keys, array $data):Db
	{
		if (!is_array($keys))
		{
			$keys = preg_split("/\s*,\s*/", $keys, -1, PREG_SPLIT_NO_EMPTY);
		}
		$i = 1;
		$w = [];// for where
		$v = [];// linear array of data
   		foreach ($keys as $f)
		{
			$w[] = "$f = \$$i";
			$v[] = (isset($data[$f])) ? $data[$f] : null;
			$i++;
		}
		return $this->exec("-- ".get_class($this).", method: ".__METHOD__."
DELETE FROM $table WHERE (".join(') AND (', $w).")", $v);
	}

/**
 * Наличие строки  по ключу
 * параметры - см. Вставку / Insert
 */
	public function rowExists($table, $keys, array $data): bool
	{
		if (!is_array($keys))
		{
			$keys = preg_split("/\s*,\s*/", $keys, -1, PREG_SPLIT_NO_EMPTY);
		}
		$i = 1;
		$w = [];// for where
		$v = [];// linear array of data
		foreach ($keys as $f)
		{
			$w[] = "$f = \$$i";
			$v[] = (isset($data[$f])) ? $data[$f] : null;
			$i++;
		}
		return $this->exec("-- ".get_class($this).", method: ".__METHOD__."
SELECT ".join(',',$keys)." FROM $table WHERE (".join(') AND (', $w).")", $v)->rows > 0;
	}

/**
 * Выдача строки по ключу
 * параметры - см. Вставку / Insert
 */
	public function getRow($table, $keys, array $data): array
	{
		if (!is_array($keys))
		{
			$keys = preg_split("/\s*,\s*/", $keys, -1, PREG_SPLIT_NO_EMPTY);
		}
		$i = 1;
		$w = [];// for where
		$v = [];// linear array of data
		foreach ($keys as $f)
		{
			$w[] = "$f = \$$i";
			$v[] = (isset($data[$f])) ? $data[$f] : null;
			$i++;
		}
		return $this->exec("-- ".get_class($this).", method: ".__METHOD__."
SELECT * FROM $table WHERE (".join(') AND (', $w).")", $v)->fetchRow();
	}

/**
 * Получить следующее значение для первичного ключа
 */
	//abstract public function nextVal($sequence_name);

/** Все результаты в таблицу, как правило массив структур
 */
	//abstract public function fetchAll($hash_index = '');

/**
 * Извлечение очередной строки результата, как правило в виде структуры
 */
	//abstract public function fetchRow();

/**
 * Извлечение строки в массив
 * typical usage: list($var1, $var2) = $db->fetchRowArray();
 */
	//abstract public function fetchRowArray();

/**
 * Количество строк подвергнутых коррекции по ins, upd, del
 */
	//abstract public function affectedRows();

/**
 * FOR DEBUG: Вывести красиво сам запрос и его query-plan
 */
	//abstract public function print_r();
}


interface iDb
{
	public function connect();

	public function disconnect();

	public function exec();

	public function nextVal($sequence_name);

	public function fetchAll($hash__index);

	public function fetchRow();

	public function fetchRowArray();

	public function affectedRows();

	public function print_r();
}
