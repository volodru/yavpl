<?php
/**
 * @NAME: Db, iDb
 * @DESC: Database abstract layer
 * @VERSION: 1.06
 * @DATE: 2017-02-01
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2009- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** CHANGELOG
 *
 *
 * 1.08
 * DATE: 2017-09-30
 * Фатальные ошибки теперь генерят исключение. Перехват их дело добровольное.
 *
 * 1.07
 * В параметры хоста добавлен адрес админа СУБД - db_admin_email.
 * Именно на этот адрес будут идти фатальные ошибки БД.
 * Нужно, чтобы разделить для некототорых проектов db_admin_email и ADMIN_EMAIL.
 * По умолчанию как раньше используется глобальная константа ADMIN_EMAIL.
 * Соответственно поправлен конструктор и showErrorMessage.
 *
 * 1.06
 * ошибки по почте отправляются через класс Mail (mail.php), а не через функцию php - mail()
 *
 * 1.05
 * добавил метод типа "паблик Морозов" - public function getHostParams()
 * надо для копирование в новый коннектор, если в проекте используется синглтон, а надо сделать еще одно отдельное соединение с базой.
 * например при использовании команды COPY в цикле выборки из базы по другому коннектору
 *
 * 1.04
 * Класс стал базовым для подклассов для Постгреса и Мускуля. Здесь осталась только абстракция.
 *
 * 1.03
 * добавлен класс DBSingleton
 * Использование в конструкторе MainModel проекта:
  	$this->db = DBSingleton::getInstance([
			'host'		=> 'localhost',
			...................
			'dbname'	=> 'dbname',
	]);
	Кому это не надо, делает свой экземпляр класса Db и вешает его на переменную MainModel->db
	$this->db = new Db([
			'host'		=> 'localhost',
			...................
			'dbname'	=> 'dbname',
	]);
 * 1.02
 * добавлено поле public $min_cost_to_save_log
 *
 * 1.01
 * добавлен заголовок с версией, описанием и проч. к этому файлу
 * теперь в методах insert, update, delete, rowExists поля и ключ могут быть массивами или строками через запятую
 * добавлен метод getRow($table, $keys, $data)
 */

if (!defined('REMOTE_ADDR'))
{//используется в основном с отладочными целями
	define('REMOTE_ADDR', ( isset($_SERVER['HTTP_X_FOREIGN_IP']) ) ? $_SERVER['HTTP_X_FOREIGN_IP'] : $_SERVER['REMOTE_ADDR']);
}

DEFINE('MAX_REGISTER_EXECUTED_SQL_COUNT', 100);

$executed_sql = [];//hence many instance of DB are allowed - global scope is required :(

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
		$this->db_admin_email = isset($host_params['db_admin_email']) ? $host_params['db_admin_email'] : ADMIN_EMAIL;
		//!!!!!!!$this->connect() - используем lazy evaluation, коннектимся не в конструкторе, а по мере необходимости.
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
	public function disableReconnects()
	{
		$this->allow_reconnects = false;
	}

/** просто включатель поля
 */
	public function enableErorrMessages()
	{
		$this->show_error_messages = true;
	}

/** паблик Морозов
 */
	public function getHostParams()
	{
		return $this->host_params;
	}

/** разбираем параметры, делаем общую часть.
 *  после вызова этого метода наследник собственно обращается к базе и делает в нее запрос
 */
	public function exec()
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
			print "Query is undefined!";
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
	}

/**
 * Всегда делает die в конце. Ошибки базы всегда фатальны!
 */
	public function showErrorMessage($err_msg, $notice_msg)
	{
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
		{//@TODO: это мутный момент когда и как показывать ошибки БД для случаев,
			//когда они допустимы.
			//и решить что это за случаи.
			print "
<h1>Ошибка в запросе</h1>
<h2>Ваш запрос</h2>
<xmp>".htmlspecialchars($this->query)."</xmp>
		<h2>Ответ от базы данных</h2>
		<xmp style='color: #F00;'>$err_msg</xmp>
		<xmp style='color: #0FF;'>$notice_msg</xmp>";
		}
		throw new Exception($debug_info);
	}

/**
 * Вставка - делается универсально, т.к. это чистый SQL92
 * $fields, $keys - arrays or strings with comma separated fields
 * $data - hash
 */
    public function insert($table, $keys, $fields, array $data)
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
INSERT INTO $table (".join(', ', $ff).") VALUES (".join(', ', $p).")", $v);
    }

/**
 * Изменение
 * параметры - см. Вставку / Insert
 */
    public function update($table, $keys, $fields, array $data)
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
    public function delete($table, $keys, array $data)
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
	public function rowExists($table, $keys, array $data)
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
	public function getRow($table, $keys, array $data)
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

/** OBSOLETE, user fetchAll($field) instead
 */
	//abstract function fetchAllWithId($index);

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
