<?php
namespace YAVPL;
/**
 * @NAME: Class: Simple Dictionary Model
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2016 - Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
*/

/* CHANGELOG
 *
 * DATE: 2023-05-27
 * getEmptyRow() теперь отдает массив пустых строк по всем полям
 *
 * DATE: 2023-04-28
 * Теперь наследуется от здешней модели и пока временно использует глобальную переменную $application
 * чтобы получить коннектор к главной базе данных
 *
 * DATE: 2019-07-31
 * beforeSaveRow - теперь пытается сделать проверки всех полей через метод checkFieldValue.
 * Если этого не надо - например, для ускорения работы, то не надо вызывать предка parent::beforeSaveRow.
 * Иначе, если перекрыт checkFieldValue для хоть каких-то полей, то предка вызывать как раз необходимо.
 *
 * DATE: 2016-11-01
 * Удалено логгирование ins-upd-del по-умолчанию.
 *
 * DATE: 2016-11-01
 * файл опубликован в библиотеке
*/

/**
 * *Класс простой словарь - таблица с целым ключом*
 *
 * Название дурацкое, но так исторически сложилось. Но начиналось всё именно со словаря :)
 *
 * Модель встраивается в цепочку наследования
 * ```
 * Model->MainModel->SimpleDictionaryModel->SomeProductionModel
 * ```
 * MainModel должна быть в каждом проекте и иметь коннектор к СУБД $this->db (класс DB/DBPg)
 *
 * Наследники SimpleDictionaryModel проекта вовсю используют методы из MainModel проекта, поэтому получается дичь, когда
 * 4 уровня наследования чередуются то в библиотеке то проекте.
 *
 * Словарь - таблица, в которой есть ключевое поле и несколько полей атрибутов.
 *
 * Этот класс позволяет:
 * 1. получить строку из базы по (одному!) (целочисленному!!!) ключу
 * 2. получить множество строк по фильтру
 * 3. создать запись (если ключ == 0) или обновить запись (ключ > 0)
 * 4. обновить поле или запись по ключу и имени поля
 * 5. удалить строку по ключу
 * 6. есть хуки на до/после изменения/удаления.
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
 *
 * Методы с проверками возвращают строку.
 * Если строка не пустая - то это сообщение об ошибке.
 * Если строка пустая - значит всё хорошо пока.
 *
 * Перекрывать saveRow можно по большим праздникам, для проверок данных до/после использовать хуки beforeSaveRow/afterSaveRow
 * Тоже про удаление deleteRow и beforeDeleteRow/afterDeleteRow.
 * Проверка перед удалением должна выдавать осмысленный совет
 * вида "А теперь сделать следующее ...", раз удалить нельзя.
 * Например: "Бренд удалить нельзя, т.к. на него ссылается 1 и более артикулов. Удалите все артикулы бренда."
 */

class SimpleDictionaryModel extends \YAVPL\Model
{
/** Имя таблица в базе (со схемой) */
	public string $table_name = '';
/** Ключевое поле - как правило - id*/
	public string $key_field = '';
/** Список полей - массив */
	public array $fields = [];
/** Последний добавленный ключ
 * - после сохранения строки с ключом 0 в этой переменной будет новый id записи*/
	public int $key_field_value;//OBSOLETE
	//ВРЕМЕННО!!
	public int $key_value;//для совместимости контроллеров с DbTable

/** Берем таблицу, ключ и поля
 * @param $table_name string таблица
 * @param $key_field string ключевое поле (как правило "id")
 * @param $fields array массив полей
 */
	public function __construct($table_name, $key_field, $fields)
	{
		parent::__construct();
		$this->table_name = $table_name;
		$this->key_field = $key_field;
		$this->fields = $fields;

		global $application;
		$this->db = $application->getMainDBConnector();
	}

/** Запись отдаваемая по ключу == 0
 * По-умолчанию отдает пустые строки. Для получения осмысленных значений метод стоит перекрыть.
 * @return array Дефолтные значения для новой записи.
 */
	public function getEmptyRow()
	{//should override!
		return [$this->key_field => 0] + array_fill_keys($this->fields, '');
	}

/** Максимально быстрый и корректный способ проверить наличие строки в базе.
 * в теории - оно работает только по индексу, т.е. не трогает файлы с данными.
 * @param $key_value int - значение ключевого поля
 * @return bool есть строка или нет
 */
	public function exists($key_value): bool
	{
		return (!empty($this->db->exec("SELECT {$this->key_field} FROM {$this->table_name} WHERE {$this->key_field} = $1", $key_value)->fetchRow()));
	}

/**
 * Возвращает сырую запись из таблицы по ключу.
 *
 * Перекрывать этот метод нежелательно, все join и экзотику в селектах надо писать в перекрываемом getRow($key_value)
 * @param $key_value int - значение ключевого поля
 * @return array всю строку по ключу
 */
	public function getRawRow($key_value)
	{
		return $this->db->exec("-- ".get_class($this).", method: ".__METHOD__."
SELECT * FROM {$this->table_name} WHERE {$this->key_field} = $1", $key_value)->fetchRow();
	}

/**
 * Отдает запись по ненулевому ключу, а если ключ == 0, отдает дефолтную запись из getEmptyRow.
 *
 * Наследники могут добавлять в селект всё, что угодно и join-нить как угодно с чем угодно.
 * Для оригинальной записи всегда останется getRawRow($key_value)
 * @param $key_value int - значение ключевого поля
 * @return array всю строку по ключу, но наследники могут делать join и отдавать еще что-нибудь из прицепленных таблиц
 */
	public function getRow($key_value)
	{
		return ($key_value == 0) ? $this->getEmptyRow() : $this->getRawRow($key_value);
	}

/** DEPRECATED
 * Вместо списка с данными выдает количество записей по переданным параметрам, вызывает свой getList($params) */
/* Proposed for deletion, volod, 27-05-2023
	public function getCount($params = [])
	{
		$params['select'] = "count(*) AS cnt";
		$params['index'] = '';
		$params['limit'] = 0;
		$params['offset'] = 0;
		list($r) = $this->getList($params);
		return $r['cnt'];
	}*/

/** Возвращает список записей по параметрам
 *
 * @return array Возвращает массив записей по переданным параметрам.
 * */
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
			$ids = is_array($params['ids']) ? $params['ids'] : explode(',', $params['ids']);
			$ids = array_filter($ids, function($v){return ($v > 0);});
			if (count($ids) > 0)
			{
				$where[] = "{$params['pkey']} IN (".join(',', $ids).")";
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

/**
 * Имя сиквенсы для таблицы. Если отличается от умолчательной для Постгреса - перекрыть.
 *
 * @return array имя сиквенсы для первичного ключа таблицы */
	public function getSeqName()
	{
		return $this->table_name.'_'.$this->key_field.'_seq';
	}

/**
 * Проверка ДО сохранения записи.
 *
 * При перекрытии не забываем вызывать предка, чтобы запустить проверку по отдельным полям.
 * @return string Сообщение об ошибке или пустую строку, если все хорошо
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

/**
 * Действие ПОСЛЕ сохранения записи
 * @return string Сообщение об ошибке или пустую строку, если все хорошо
 */
	public function afterSaveRow($action, &$data, $old_data)
	{
		return '';//override
	}

/**
 * Сохранения сразу всей строки.
 *
 * Если ключевое поле == 0, то создает новую запись, иначе - обновляет поля.
 * @return string Сообщение об ошибке или пустую строку, если все хорошо
 * */
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
		$this->key_value = $this->key_field_value;//для совместимости с DbTable
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

/**
 * Проверка поля перед сохранением.
 *
 * Метод может корректировать данные.
 * Для сравнения с предыдущими данными наследник может по $data[$this->key_field] получить старое значение.
 * $old_data = $this->getRawRow($data[$this->key_field]);
 *
 * Штука редкая - пусть наследники этим занимаются.
 * @return string Сообщение об ошибке или пустую строку, если все хорошо
 */
	public function checkFieldValue($action, $field_name, &$data)
	{
		return '';//override - не забыть вызвать родительский beforeSaveRow в beforeSaveRow!
	}

/** Обновляет одно поле в строке.
 * @param $key_value int ключ
 * @param $field_name string имя поля
 * @param $value mixed новое значение поля
 * @return string Сообщение об ошибке или пустую строку, если все хорошо
 * */
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
		elseif (!$this->exists($key_value))
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
 * Проверка на возможность удаления строки.
 * возвращает "" или NULL если можно удалить запись или сообщение о том, почему нельзя ее удалять.
 * deleteRow() проверяет наличие пустой строки в качестве сообщения.
 * @return string Сообщение о причине невозможности удаления или пустую строку, если все можно удалять
 */
	public function canDeleteRow($key_value)
	{
		return '';
	}

/**
 * Действие ДО удаления строки.
 * Перекрываем метод, чтобы сделать что-то непоследственно перед удалением записи.
 * Бывает, что что-то надо удалить в обход констрэйнтов
 * Запись удалится наверняка, т.к. метод canDeleteRow уже дал добро на удаление.
 *
 * Возвращает пустую строку, если все хорошо, иначе возвращает сообщение об ошибке.
 * Чтобы не заморачиваться с возвратом - стоит всегда возвращать parent::beforeDeleteRow($key_value).
 * @return string Сообщение об ошибке или пустую строку, если все хорошо
 */
	public function beforeDeleteRow($key_value)
	{
		return '';
	}

/**
 * Действие ПОСЛЕ удаления строки.
 * Перекрываем метод, чтобы сделать что-то после удаления записи.
 * Сюда пихать зачистку файловой системы от файлов, т.к. надо сначала точно удалить запись в СУБД, прежде, чем удалять файл.
 * Файл удалится наверняка, а в СУБД бывают дедлоки и прочее.
 *
 * Но все это работает только в том случае, если нужен только ID записи.
 * Саму-то запись мы уже удалили :(
 *
 * Возвращает пустую строку, если все хорошо, иначе возвращает сообщение об ошибке.
 * Чтобы не заморачиваться с возвратом - стоит всегда возвращать parent::afterDeleteRow($key_value)
 * @return string Сообщение об ошибке или пустую строку, если все хорошо
 */
	public function afterDeleteRow($key_value)
	{
		return '';
	}

/**
 * Удаляет строку из базы.
 * Все проверки в наследниках пихать в canDeleteRow.
 * Перекрывать deleteRow только по большим праздникам.
 * Все действия по зачистке мусора делать в beforeDeleteRow() и в afterDeleteRow().
 *
 * Возвращаем всегда не NULL, а либо пустую строку, либо объяснительную.
 * @param int $key_value id записи
 * @return string Строка с ошибкой или пустая строка если все хорошо.
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

/** Поддержка хранилища сущностей в проекте.
 */
	public function getEntityTypeInfo()
	{
		global $application;
		return $application->getEntityTypesInstance()->byTable($this->table_name) ??
			sendBugReport('getEntityTypeInfo', "Не найден тип сущности по таблице {$this->table_name}", true);
	}

	public function getEntityTypeId()
	{
		return $this->getEntityTypeInfo()['id'];
	}

}