<?php
declare(strict_types=1);
namespace YAVPL;

/**
 * @NAME: DbPg
 * @DESC: PostgresQL wrapper
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2009- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** CHANGELOG
 *
 * 2023-04-24
 * расставлены type hints везде, куда можно
 *
 * 1.01
 * добавлен метод для работы через команду COPY - bulkLoad($table_name, $fields_list, $data)
 *
 * 1.00
 * выделен класс для работы с Постгресом
 */

class DbPg extends Db implements iDb
{
	public $pg_dbh;
	private $row = 0;

/** Подключаемся к базе через установленные в конструкторе параметры соединения
 * @return void
 */
	public function connect():void
	{
		$connect_string = "host={$this->host_params['host']} port={$this->host_params['port']} user={$this->host_params['user']} password={$this->host_params['passwd']} dbname={$this->host_params['dbname']} connect_timeout=5";

		$this->pg_dbh = @pg_connect($connect_string, PGSQL_CONNECT_FORCE_NEW)
			OR die("Cannot connect to PostgresQL host={$this->host_params['host']} base=[{$this->host_params['dbname']}] port=[{$this->host_params['port']}] user=[{$this->host_params['user']}] (PGSQL_CONNECT_FORCE_NEW)");
		if (isset($this->host_params['exec_after_connect']))
		{
			@pg_query($this->pg_dbh, $this->host_params['exec_after_connect']);
		}
		//da(pg_get_pid ($this->pg_dbh));da(__getBackTrace());
		$this->is_connected = true;
	}

/** Отключаемся от базы
 * @return void
 */
	public function disconnect():void
	{
		if ($this->is_connected)
		{
			@pg_close($this->pg_dbh);
			$this->pg_dbh = 0;
			$this->is_connected = false;
		}
	}

/** возвращает self! чтобы делать $db->exec()->fetchAll();
 * первый параметр - текст SQL запроса
 * второй и далее - параметры для подстановки в запрос по числу плейсхолдеров
 *
 * первый параметр - обязателен
 * @return Db
 */
	public function exec():Db
	{
		parent::exec(func_get_args());//там всякие подготовки безотносительно к Постгресу

//---- СОБСТВЕННО ОБРАЩЕНИЕ К СУБД --
		$this->sth = (count($this->query_params) > 0) ?
			@pg_query_params($this->pg_dbh, $this->query, $this->query_params) : @pg_query($this->pg_dbh, $this->query);
//-----------------------------------
		if ($this->sth)
		{
			$this->row = 0;
			$this->rows = pg_num_rows($this->sth);
		}
		else
		{
			$err_msg = pg_last_error($this->pg_dbh);
			$notice_msg = pg_last_notice($this->pg_dbh);
			$this->showErrorMessage($err_msg, $notice_msg);//it dies in the end. always.
		}

//--------------------------------------------------------------------------
// чтобы память не переполнять, $save_executed_sql выключен. для дебагов - включить в коннекторе к БД - как правило, в Application проекта
//$this->db->save_executed_sql = true;
//--------------------------------------------------------------------------

		//da($this->save_executed_sql);da($this->executed_sql_count);da(MAX_REGISTER_EXECUTED_SQL_COUNT);
		if ($this->save_executed_sql && $this->executed_sql_count < MAX_REGISTER_EXECUTED_SQL_COUNT)
		{
			$explain = '';
			$query = preg_replace("/^(\s+)/m",'', $this->query);
			if ((preg_match("/^\s*(SELECT)/sim", $query)) && (!preg_match("/^\s*BEGIN/sim", $query)))
			{//logging -------------------------------
				$sth = (count($this->query_params) > 0) ?
					pg_query_params($this->pg_dbh, "EXPLAIN {$query} ", $this->query_params) :
					pg_query($this->pg_dbh, "EXPLAIN {$query} ");

				$rows = pg_numrows($sth);

				for($r = 0; $r < $rows; $r++)
				{
					list($tmp) = pg_fetch_array($sth, $r);
					$explain .= $tmp."\n";
				}
				$this->explain = $explain;

				$res1 = $res2 = [];
				preg_match("/offset\s+(\d+)/sim", $query, $res1);
				//preg_match("/(sort)/sim", $explain, $res1);
				preg_match("/cost=(\d+)\.\d+\.\.\d+\.\d+/", $explain, $res2);
				//preg_match("/Total runtime: (\d+)\.(\d+) msec/", $explain, $res);
				if (
					$this->log_path != '' &&
					isset($_SERVER['SERVER_NAME']) && //not for CLI
					isset($res2[1]) && ($res2[1] > $this->min_cost_to_save_log) &&(!(isset($res1[1])) ||((isset($res1[1])) && ($res1[1]>=0))))
				{
					$a = explode('.', $_SERVER['SERVER_NAME']);
					$fn = $this->log_path."long_sqls_".((isset($a[count($a)-2])) ? $a[count($a)-2] : $_SERVER['SERVER_NAME']).".log";
					$f = false;
					if (!file_exists($fn))
					{
						$f = fopen($fn,'w');
					}
					else
					{
						if (is_writable($fn))
						{
							$f = fopen($fn,'a');
						}
						else
						{
							//cannot create or add to
						}
					}
					if ($f)
					{
						fwrite($f, "
#----------------------------------------------------
#	".date("Y/m/d  H:i:s")."
#	{$_SERVER['PHP_SELF']} {$_SERVER['REQUEST_URI']}
#	{$_SERVER['SERVER_NAME']}	{$_SERVER['REMOTE_ADDR']}
ref: ".urldecode(isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER']:'')."
agent: {$_SERVER['HTTP_USER_AGENT']}
{$query}
".((count($this->query_params) > 0) ? "
PARAMS: ".print_r($this->query_params, true) : '').$explain);
						fclose($f);
					}
				}
				//!!!!!!!!!!!!!
				//EXTREMELY DANGEROUS!!!!
				//$this->executed_sql[] = "$query\n$explain";
				//!!!!!!!!!!!!!
				// end logging -------------------------------
			}
			global $application;
			$application->executed_sql[] = $query.
				"\nQuery returs {$this->rows} row(s)".
				((count($this->query_params) > 0)?"\nPARAMS: ".print_r($this->query_params, true):'').
				(($explain != '') ? "\n-----------\n{$explain}-----------" : '');
		}
		return $this;
	}//exec

/**
 * Получить следующее значение из сиквенсы
 */
	public function nextVal(string $sequence_name): int
	{
		if (!$this->is_connected)
		{
			$this->connect();
		}
		list($i) = pg_fetch_array(pg_query($this->pg_dbh, "SELECT nextval('{$sequence_name}')"), 0, PGSQL_NUM);
		return intval($i);
	}

/** Все результаты в таблицу, массив структур.
 * Если передать индексы, то будет сделан ассоциативный массив
 * Индексы передавать через запятую, например fetchAll('brand_id,article')
 *
 * @var string $hash_index - индекс для ассоциативного массива (поля через запятую), либо '' для линейного массива
 * @return array результирующий массив структур - линейный или ассоциативный.
 *
 */
	public function fetchAll(string $hash_index = ''): array
	{
		$t = [];
		for ($row_num = 0; $row_num < $this->rows; $row_num++)
		{
			$row = pg_fetch_array($this->sth, $row_num, PGSQL_ASSOC);
			if ($hash_index == '')
			{
				$t[] = $row;
			}
			else
			{
				$code = '';
				foreach (preg_split("/,\s*/", $hash_index) as $index)
				{
					//проверку делать именно на string!, а не на целое и т.п.
					//OLD CODE was unsafe : $code .= (is_string($row[$index])) ? "['{$row[$index]}']" : "[{$row[$index]}]";
					if (!isset($row[$index]))
					{
						sendBugReport('Wrong index in fetchAll()', "index: {$hash_index}", true);
					}
					if (is_string($row[$index]))//но строковых значений в индексе массива лучше избегать,
					{
						$row[$index] = str_replace("'", '', $row[$index]);//we have to do it
						/** @TODO - вопрос спорный что делать с обратными слэшами. если оставить
						//$row[$index] = preg_replace("/^(.+)\\$/", '$1', $row[$index]);//в конце строки, чтобы не ломался eval со скобкой ]
						*/
						$row[$index] = trim($row[$index], '\\');
						//вот такой попался артикул: 96308617\ и его нельзя было смешивать с 96308617
						$code .= "['".$row[$index]."']" ;
					}
					else
					{
						$code .= "[{$row[$index]}]";
					}
				}
				eval("\$t{$code} = \$row;");
			}
		}
		return $t;
	}

/**
 * Извлечение очередной строки результата в виде структуры
 * использование:
 * while (!empty($row = $db->fetchRow())){...}
 */
	public function fetchRow(): ?array
	{
		return ($this->row < $this->rows) ? pg_fetch_array($this->sth, $this->row++, PGSQL_ASSOC) : null;
	}

/**
 * Извлечение строки в массив
 * использование:
 * list($var1, $var2) = $db->fetchRowArray();
 */
	public function fetchRowArray(): ?array
	{
		return ($this->row < $this->rows) ? pg_fetch_array($this->sth, $this->row++, PGSQL_NUM) : null;
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
		if ((preg_match("/^\s*(SELECT)/sim", $this->query))
			&& (! preg_match("/^\s*BEGIN/sim", $this->query)))
		{
			$sth = (count($this->query_params) > 0) ?
			pg_query_params($this->pg_dbh, "EXPLAIN {$this->query} ", $this->query_params) :
			pg_query($this->pg_dbh, "EXPLAIN {$this->query} ");
			$rows = pg_numrows($sth);
			$explain = '';
			for ($r = 0; $r < $rows; $r++)
			{
				list($tmp) = pg_fetch_array($sth, $r);
				$explain .= "$tmp\n";
			}
		}
		print "<xmp>";
		print_r($this->query);
		print "\n-------------------------\n";
		print_r($explain);
		if (count($this->query_params) > 0)
		{
			print "\n-------------------------\nPARAMS: ".var_export($this->query_params, true);
		}
		print "</xmp>";
		return $this;
	}

/**
 * Массовая загружалка через команду COPY.
 * Можно либо собрать всё в память и выгрузить одной командой COPY
 * + работает если сервера разные или даже с доступом по интернету
 * - двукратный расход памяти под буфер
 * Либо сразу писать COPY на каждую строку входных данных
 * - на удаленном сервере будет тормозить
 * - нет перерасхода памяти.
 *
 * @param $table_name string таблица со схемой,
 * @param $fields_list array массив полей,
 * @param $data array массив массивов.
 *
 * осмысленные проверки надо делать на стороне вызывающей стороны!
 * тут заменяются \t на \T в строках и null элементы на \N
 */
	public function bulkLoad(string $table_name, array $fields_list, array $data): void
	{
		if ((count($fields_list) == 0) ||
			(count($data) == 0) ||
			(trim($table_name) == ''))
		{
			return;
		}
		$buf = [];
		foreach ($data as $line)
		{
			foreach($line as $k => $v)
			{
				if (isset($v))
				{
					$v = preg_replace("/([\t])/", '\T', $v);
					//$v = preg_replace("/([\\\\])/", '\\', $v);
					$v = str_replace("\\", '\\\\', $v);
					$line[$k] = $v;
				}
				else
				{
					$line[$k] = '\N';
				}
			}
			$buf[] = join("\t", $line);
		}
		//da($fields_list);da($buf);return;
		$this->exec("COPY {$table_name} (".join(',',$fields_list).") FROM stdin;");
		//тут делаем строго один вызов - надо при удалении сервера СУБД от апача, иначе можно было бы просто сделать count($data) вызовов pg_put_line
		pg_put_line($this->pg_dbh, join("\n", $buf)."\\.\n");
		pg_end_copy($this->pg_dbh);
	}
}