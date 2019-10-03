<?php
/**
 * @NAME: Class: Simple Dictionary Model
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2016 - Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
*/

/** CHANGELOG
 *
 * * 1.01
 * DATE: 2019-07-31
 * beforeSaveRow - теперь пытается сделать проверки всех полей через метод checkFieldValue.
 * Если этого не надо - например, для ускорения работы, то не надо вызывать предка parent::beforeSaveRow.
 * Иначе, если перекрыт checkFieldValue для хоть каких-то полей, то предка вызывать как раз необходимо.
 *
 * * 1.00
 * DATE: 2016-11-01
 * Удалено логгирование ins-upd-del по-умолчанию.
 *
 * DATE: 2016-11-01
 * файл опубликован в библиотеке
*/

/* Модель встраивается в цепочку наследования
 * Model->MainModel->SimpleDictionaryModel->SomeProductionModel
 * MainModel должна быть в каждом проекте и иметь коннектор к СУБД $this->db (класс DB/DBPg)
 *
 *
 *
 *
 * Словарь - таблица, в которой есть ключевое поле и несколько полей атрибутов
 * этот класс позволяет
 * 1. получить строку по (одному!) (целочисленному!!!) ключу
 * 2. получить множество строк по фильтру
 * 3. создать запись (если ключ == 0) или обновить запись (ключ > 0)
 * 4. обновить поле запись по ключу и имени поля
 * 5. удалить строку по ключу
 *
 * от наследника требуется/желательно:
 * 1. перекрыть метод getEmptyRow() - он отдается при попытке получить строку по ключу == 0
 * это те значения, которые хочется увидеть в полях формы при создании строки
 *
 * 2. самостоятельно сделать все нужные проверки при вставке, коррекции и удалении строк.
 *
 * 3. если нужны сложные выборки, то лучше сразу перекрыть getList и вообще не вызывать parent::getList()
 *
 * Концепция обработки ошибок:
 * Методы с проверками возвращают строку.
 * Если строка не пустая - то это сообщение об ошибке.
 * Если строка пустая - значит всё хорошо пока.
 *
 */

class SimpleDictionaryModel extends MainModel
{
	public $table_name = '';
	public $key_field = '';
	public $fields = [];
	public $key_field_value;

	function __construct($table_name, $key_field, $fields)
	{
		parent::__construct();
		$this->table_name = $table_name;
		$this->key_field = $key_field;
		$this->fields = $fields;
	}

/** Запись отдаваемая по ключу == 0
 */
	public function getEmptyRow()
	{
		//override!
	}

/** максимально быстрый и корректный способ проверить наличие строки в базе.
 * в теории - оно работает только по индексу, т.е. не трогает файлы с данными.
 */
	public function rowExists($key_value)
	{
		return ($this->db->exec("SELECT {$this->key_field} FROM {$this->table_name} WHERE {$this->key_field} = $1", $key_value)->fetchRow() !== false);
	}

/** Возвращает сырую запись из таблицы по ключу.
 * Перекрывать этот метод нежелательно, все join и экзотику в селектах надо писать в перекрываемом getRow($key_value)
 */
	public function getRawRow($key_value)
	{
		return $this->db->exec("-- ".get_class($this).", method: ".__METHOD__."
SELECT * FROM {$this->table_name} WHERE {$this->key_field} = $1", $key_value)->fetchRow();
	}

/** Отдает запись по ключу, если ключ == 0, отдает дефолтную запись из getEmptyRow
 * Наследники могут добавлять в селект всё, что угодно и join-нить как угодно с чем угодно.
 * Для оригинальной записи всегда останется getRawRow($key_value)
 */
	public function getRow($key_value)
	{
		if ($key_value == 0)
		{
			return $this->getEmptyRow();
		}
		else
		{
			return $this->getRawRow($key_value);
		}
	}

	public function getCount($params = [])
	{
		$params['select'] = "count(*) AS cnt";
		$params['index'] = '';
		$params['limit'] = 0;
		$params['offset'] = 0;
		list($r) = $this->getList($params);
		return $r['cnt'];
	}

	public function getList($params = [])
	{
		$where = [];
		$limit = $offset = -1;
		$select = '*';
		$from = $this->table_name;
		$index = $order = $this->key_field;
		$group = $having = '';
		$this->__last_list_params = $params;//на случай если контроллер захочет сохранить себе сформированные параметры вызова getList() для следующего раза.
		if (isset($params['order']) && $params['order'] != '')
		{
			$order = $params['order'];
		}

		if (isset($params['index']))
		{
			$index = $params['index'];
		}

		if (isset($params['limit']) && $params['limit'] > 0)
		{
			$limit = $params['limit'];
		}

		if (isset($params['offset']) && $params['offset'] > 0)
		{
			$offset = $params['offset'];
		}

		if (isset($params['select']) && $params['select'] != '')
		{
			$select = $params['select'];
		}

		if (isset($params['from']) && $params['from'] != '')
		{
			$from = $params['from'];
		}

		if (isset($params['group']) && $params['group'] != '')
		{
			$group = $params['group'];
		}

		if (isset($params['having']) && $params['having'] != '')
		{
			$having = $params['having'];
		}

		if (isset($params['where']) && is_array($params['where']))
		{
			$where = $params['where'];
		}

		if (isset($params['ids']))//либо массив, либо сразу список через запятую
		{
			if (!isset($params['pkey']))//$params['pkey'] надо устанавливать в перекрытых методах getList, т.к. только там понятно какой алиас у PK
			{// в секцию WHERE надо указывать алиас и поле, например FROM genarts AS g ..... WHERE g.id IN (...)
			// а оно у всех разное.
				$params['pkey'] = $this->key_field;//но если просто выборка по одной таблице без JOIN то и так сработает.
				$msg = 'When passing ids array - the $params[pkey] value should be set.';
				sendBugReport($msg);
				//die($msg);
			}
			$ids = is_array($params['ids']) ? $params['ids'] : explode(',',$params['ids']);
			$ids = array_filter($ids, function($v){return ($v > 0);});
			if (count($ids) > 0)
			{
				$where[] = "{$params['pkey']} IN (".join(',',$ids).")";
			}
			else
			{//если массив передали, но пустой - значит ожидаем явно не весь каталог в случае пустого массива!
				return [];
			}
		}

		$this->db->exec("-- ".get_class($this).", method: ".__METHOD__."
SELECT
	{$select}
FROM
	{$from}
".((count($where) > 0) ? "WHERE ".join(" AND ", $where):'')
.(($group != '') ? "\n".$group : '')
.(($having != '') ? "\n".$having : '')."
ORDER BY
	{$order}
".(($limit >= 0) ? "\nLIMIT ".$limit : '')."
".(($offset >= 0) ? "\nOFFSET ".$offset : ''));//->print_r();

		$this->last_query = [
			'query'		=> $this->db->query,
			'params'	=> $this->db->params,
		];
		return $this->db->fetchAll($index);
	}

	public function getSeqName()
	{
		return $this->table_name.'_'.$this->key_field.'_seq';
	}

/** при перекрытии не забываем вызывать предка, чтобы запустить проверку по отдельным полям.
 */
	public function beforeSaveRow($action, &$data, $old_data)
	{
		foreach ($this->fields as $field_name)
		{//данные ($data) передаются по ссылке, так что overhead от пустых выхзовов должен быть минимальным.
			$message = $this->checkFieldValue($action, $field_name, $data);
			if (isset($message) && ($message != ''))
			{
				return $message;
			}
		}
		return '';
	}

	public function afterSaveRow($action, &$data, $old_data)
	{
		return '';//override
	}

	public function saveRow($data)
	{
		//  ?? а надо ли? $data[$this->key_field] = $data[$this->key_field] ?? 0;
		$action = ($data[$this->key_field] == 0) ? 'insert' : 'update';

		$old_data = $this->getRow($data[$this->key_field]);

		$message = $this->beforeSaveRow($action, $data, $old_data);
		if (isset($message) && ($message != ''))
		{
			return $message;
		}

		if ($action == 'insert')
		{
			$data[$this->key_field] = $this->db->nextVal($this->getSeqName());
			$ar = $this->db->insert($this->table_name, $this->key_field, $this->fields, $data)->affectedRows();
		}
		else
		{
			$ar = $this->db->update($this->table_name, $this->key_field, $this->fields, $data)->affectedRows();
		}
		$this->affected_rows = $ar;
		$this->key_field_value = $data[$this->key_field];//new value will be here, in case of "insert"
		//проверки вставилось/обновилось делаем по $this->affected_rows
		if ($this->affected_rows == 1)
		{
			$message = $this->afterSaveRow($action, $data, $old_data);
			// наследники могут вернуть NULL, а мы, как старшие, не можем.
			// у нас ответственность перед контроллером, который проверяет только на пустую строку.
			if (isset($message) && ($message != ''))
			{
				return $message;
			}
			return '';//все хорошо
		}
		else
		{
			return "Вероятно произошла ошибка при сохранении: количество измененных записей = {$this->affected_rows}";
		}
	}

/** метод может корректировать данные.
 * для сравнения с предыдущими данными наследник может по $data[$this->key_field] получить старое значение.
 * $old_data = $this->getRawRow($data[$this->key_field]);
 * штука редкая - пусть наследники этим занимаются.
 */
	public function checkFieldValue($action, $field_name, &$data)
	{
		return '';//override
	}

	public function updateField($key_value, $field_name, $value)
	{
		$data = [
			$this->key_field	=> $key_value,
			$field_name			=> $value,
		];
		if (!in_array($field_name, $this->fields))
		{//ошибка на этапе разработки. в продакшене это недопустимо.
			die("Wrong field name [{$field_name}] passed to updateField()");
		}
		elseif ($key_value == 0)
		{
			return "Переданный первичный ключ равен нулю.";
		}
		elseif (!$this->rowExists($key_value))
		{
			return "По переданному первичному ключу ({$key_value}) не найдена запись в базе.";
		}
		else
		{
			$message = $this->checkFieldValue('update', $field_name, $data);
			if (isset($message) && ($message != ''))
			{
				return $message;
			}
			$this->affected_rows = $this->db->update($this->table_name, $this->key_field, $field_name, $data)->affectedRows();
			return ($this->affected_rows == 1) ?
				'' ://все хорошо
				"Вероятно произошла ошибка при сохранении: количество измененных записей = {$this->affected_rows}";
		}
	}

/**
 * возвращает "" или NULL если можно удалить запись или сообщение о том, почему нельзя ее удалять.
 * deleteRow() проверяет наличие пустой строки в качестве сообщения
 */
	public function canDeleteRow($key_value)
	{
		return '';
	}

/**
 * перекрываем метод, чтобы сделать что-то непоследственно перед удалением записи.
 * бывает, что что-то надо удалить в обход констрэйнтов
 * запись удалится наверняка, т.к. метод canDeleteRow уже дал добро на удаление.
 *
 * возвращает пустую строку, если все хорошо, иначе возвращает сообщение об ошибке.
 * чтобы не заморачиваться с возвратом - стоит всегда возвращать parent::beforeDeleteRow($key_value)
 */
	public function beforeDeleteRow($key_value)
	{
		return '';
	}

/**
 * перекрываем метод, чтобы сделать что-то после удаления записи.
 * сюда пихать зачистку файловой системы от файлов, т.к. надо сначала точно удалить
 * запись в СУБД, прежде, чем удалять файл.
 * файл удалится наверняка, а в СУБД бывают дедлоки и прочее.
 *
 * но все это работает только в том случае, если нужен только ID записи.
 * саму-то запись мы уже удалили :(
 *
 * возвращает пустую строку, если все хорошо, иначе возвращает сообщение об ошибке.
 * чтобы не заморачиваться с возвратом - стоит всегда возвращать parent::afterDeleteRow($key_value)
 */
	public function afterDeleteRow($key_value)
	{
		return '';
	}

/**
 * все проверки в наследниках пихать в canDeleteRow
 * перекрывать deleteRow только по большим праздникам
 * все действия по зачистке мусора делать в beforeDeleteRow() и в afterDeleteRow()
 *
 * возвращаем всегда не NULL, а либо пустую строку, либо объяснительную.
 */
	public function deleteRow($key_value)
	{
//можем ли мы удалить запись
		$message = $this->canDeleteRow($key_value);
		if (isset($message) && $message != '')
		{
			return $message;
		}
//делаем что надо ДО удаления
		$message = $this->beforeDeleteRow($key_value);
		if (isset($message) && $message != '')
		{
			return $message;
		}
//удаляем запись
		$data = [$this->key_field => $key_value];
		$this->affected_rows = $this->db->delete($this->table_name, $this->key_field, $data)->affectedRows();

// т.к. удаление делается по ключу, то если $ar > 1 - это ошибка, если == 0 тоже фигня,
// только это быть может и не ошибка,
// например, на двух вкладках браузера шмакнули на ссылку Удалить.
// а перед удалением проверять наличие записи обычно в лом, да и без транзакционной обертки
// это все равно профанация.
		if ($this->affected_rows == 1)
		{//делаем все, что надо после удаления и заканчиваем работу
			$message = $this->afterDeleteRow($key_value);
// наследники могут вернуть NULL, а мы, как старшие, не можем.
// у нас ответственность перед контроллером, который проверяет только на пустую строку.
			if (isset($message) && $message != '')
			{
				return $message;
			}
		}
		else
		{
			return "Что-то не так: affected rows = {$this->affected_rows}";
		}
		return '';//все хорошо, а ошибки уходят по early return
	}
}