<?php
//declare(strict_types=1);

namespace YAVPL;

/**
 * @NAME: Class: Db Table
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2023 - Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
*/

/** CHANGELOG
 * DATE: 2023-05-27
 * изменения относительно SimpleDictionaryModel:
 * 1. добавлена типизация
 * 2. getList-
 * 	limit/offset = 0 значит без лимита и/или без офсета
 * 	params['select'] - должен начинаться со слова SELECT
 *	params['from'] - должен начинаться со слова FROM
 * 3. rowExist переименована в exists(key_value)
 * 4. при создании записи новый PK сохраняется в переменной $key_value (а не $key_field_value, как в SimpleDictionaryModel)
 * 5. getEmptyRow() по-умолчанию набивает массив пустыми строками.
 * 6. getRow() возвращает null, если нет записи по ключу
 *
 * DATE: 2023-04-28
 * Это новая инкарнация SimpleDictionaryModel, без проблем с наследованием.
*/

/**
 * *Класс таблица с целым ключом*
 *
 * Класс работает с данными как с данными/структурами(ассоциативными массивами).
 * Класс не работает с записями, как с классами.
 *
 * Этот класс позволяет:
 * 1. получить строку из базы по (одному!) (целочисленному!!!) ключу
 * 2. получить множество строк по фильтру
 * 3. создать запись (если ключ == null || 0) или обновить запись (ключ > 0)
 * 4. обновить поле или запись по ключу и имени поля
 * 5. удалить строку по ключу
 * 6. есть хуки на до/после изменения/удаления.
 *
 * от наследника требуется/желательно:
 * 1. перекрыть метод getEmptyRow() - он отдается при попытке получить строку по ключу == 0
 * это те значения, которые хочется увидеть в полях формы при создании записи в БД,
 * если конечно, не устраивают ВСЕ поля в виде пустой строки (по-умолчанию).
 *
 * 2. самостоятельно сделать все нужные проверки при вставке, коррекции и удалении строк.
 *
 * 3. если нужны сложные выборки, то лучше сразу перекрыть getList и вообще не вызывать parent::getList()
 *
 * Концепция обработки ошибок:
 *
 * Методы с проверками возвращают строку.
 * Если строка не пустая - то это сообщение об ошибке и/или причине проблемы.
 * Если строка пустая - значит всё пока хорошо.
 *
 * Перекрывать saveRow можно по большим праздникам, для проверок данных до/после использовать хуки beforeSaveRow/afterSaveRow
 * Тоже про удаление deleteRow и beforeDeleteRow/afterDeleteRow.
 * Проверка перед удалением должна выдавать осмысленный совет
 * вида "А теперь сделать следующее ...", раз удалить нельзя.
 * Например: "Бренд удалить нельзя, т.к. на него ссылается один и более артикулов. Удалите все артикулы бренда."
 */

class DbTable extends \YAVPL\Model
{
/** Имя таблица в базе (со схемой, например catalog.articles) */
	public string $table_name = '';

/** Ключевое поле, как правило - id*/
	public string $key_field = 'id';

/** Список полей - массив */
	public array $fields = [];

/** Последний добавленный ключ / ID сущности.
 * После сохранения строки (saveRow) с ключом 0 в этой переменной будет новый id записи.
 * Если было обновление, то в этой переменной всё равно будет ID записи. */
	public int $key_value;

/** Кеш описания сущности. Для проектов с таблицей сущностей. */
	private $__entityTypeInfo;

/** Берем таблицу, ключ и поля
 * @param $table_name string таблица
 * @param $key_field string ключевое поле (как правило "id")
 * @param $fields array массив полей
 */
	public function __construct(string $table_name, string $key_field, array $fields)
	{
		parent::__construct();
		$this->table_name = $table_name;
		$this->key_field = $key_field;
		$this->fields = $fields;
	}

//-------------------------------------------------------------------------------------------------------------------
//READ
//-------------------------------------------------------------------------------------------------------------------

/** Запись отдаваемая по ключу == 0
 * @return array Дефолтные значения для новой записи.
 * TODO придумать как быстро заполнять числовые поля нулями. тупо лезть в описание таблиц - долго.
 */
	public function getEmptyRow(): array
	{
		return [$this->key_field => 0] + array_fill_keys($this->fields, '');
	}

/** Максимально быстрый и корректный способ проверить наличие строки в базе.
 * в теории - оно работает только по индексу (PK), т.е. не трогает на диске файлы с данными.
 * @param $key_value int - значение ключевого поля
 * @return bool есть строка или нет
 */
	public function exists(int $key_value): bool
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
	public function getRawRow(int $key_value): ?array
	{
		$row = $this->db->exec("-- ".get_class($this).", method: ".__METHOD__."
SELECT * FROM {$this->table_name} WHERE {$this->key_field} = $1", $key_value)->fetchRow();
		return (empty($row)) ? null : $row;//костыль на переходный период. когда db->fetchRow будет сама давать null, можно его убрать.
	}

/**
 * Отдает запись по ненулевому ключу, а если ключ == 0, отдает дефолтную запись из getEmptyRow.
 *
 * Наследники могут добавлять в селект всё, что угодно и join-нить как угодно с чем угодно.
 * Для оригинальной записи всегда останется getRawRow($key_value)
 *
 * Для наследников, добавляющих функционал стоит использовать шаблон:
 *
 	$row = parent::getRow($key_value);
	if (!empty($row))
	{
		$row['какое-то новое поле'] = какие-то манипуляции дял его получения;
	}//else так отдаем пустой массив/null и т.д., чтобы пользователь мог понять, что по ID ничего не нашлось
	return $row;
 *
 * @param $key_value int - значение ключевого поля
 * @return array всю строку по ключу, но наследники могут делать join и отдавать еще что-нибудь из прицепленных таблиц
 */
	public function getRow(int $key_value): ?array
	{
		return ($key_value == 0) ? $this->getEmptyRow() : $this->getRawRow($key_value);
	}

/** Возвращает список записей по параметрам
 *
 * @return array Возвращает массив записей по переданным параметрам.
 * */
	public function getList(array $params = []): array
	{
		$params['select'] ??= "SELECT *";
		$params['from'] ??= "FROM {$this->table_name}";
		$params['where'] ??= [];//массив для склеивания по AND
		$params['order'] ??= $this->key_field;
		$params['limit'] ??= 0;//no limits
		$params['offset'] ??= 0;//from start
		$params['index'] ??= $this->key_field;
		$params['pkey'] ??= $this->key_field;//но если просто выборка по одной таблице без JOIN то и так сработает.

		$this->__last_list_params = $params;//на случай если контроллер захочет сохранить себе сформированные параметры вызова getList() для следующего раза.

		$f = 'ids';
		if (isset($params[$f]))//либо массив, либо сразу список через запятую
		{
			$ids = is_array($params[$f]) ? $params[$f] : explode(',', $params[$f]);
			$ids = array_filter($ids, function($v){return ($v > 0);});
			if (count($ids) > 0)
			{
				$params['where'][] = "{$params['pkey']} IN (".join(',', $ids).")";
			}
			else
			{//если массив передали, но пустой - значит ожидаем явно не весь каталог в случае пустого массива!
				return [];
			}
		}

		$this->db->exec("-- ".get_class($this).", method: ".__METHOD__."
{$params['select']}
{$params['from']}
".((count($params['where']) > 0) ? "WHERE ".join(" AND ", $params['where']):'')."
ORDER BY {$params['order']}
".(($params['limit'] > 0) ? "\nLIMIT ".$params['limit'] : '')."
".(($params['offset'] > 0) ? "\nOFFSET ".$params['offset'] : ''));//->print_r();

		$this->last_query = [
			'query'		=> $this->db->query,
			'params'	=> $this->db->query_params,
		];
		return $this->db->fetchAll($params['index']);
	}

/**
 * Имя сиквенсы для таблицы. Если отличается от умолчательной для Постгреса - перекрыть.
 *
 * @return array имя сиквенсы для первичного ключа таблицы */
	public function getSeqName(): string
	{
		return $this->table_name.'_'.$this->key_field.'_seq';
	}


//-------------------------------------------------------------------------------------------------------------------
//CREATE & UPDATE
//-------------------------------------------------------------------------------------------------------------------

/**
 * Проверка отдельного поля перед сохранением.
 * Требуется если проверки и модификации нужна как saveRow() так и в updateFieldValue() -
 * т.е. сохраняется, например, и вся форма сразу и отдельные поля - разными контроллерами.
 *
 * Метод может корректировать данные.
 * Для сравнения с предыдущими данными наследник может по $data[$this->key_field] получить старое значение.
 * $old_data = $this->getRawRow($data[$this->key_field]);
 *
 * Штука редкая - пусть наследники этим занимаются.
 * @return string Сообщение об ошибке или пустую строку, если все хорошо
 */
	public function checkFieldValue(string $action, string $field_name, array &$data): string
	{
		return '';//override - не забыть вызвать родительский beforeSaveRow в beforeSaveRow!
	}

/**
 * Проверка ДО сохранения записи.
 *
 * При перекрытии не забываем вызывать предка, чтобы запустить проверку по отдельным полям.
 * @return string Сообщение об ошибке или пустую строку, если все хорошо
 */
	public function beforeSaveRow(string $action, array &$data, array $old_data): string
	{
		foreach ($this->fields as $field_name)
		{//данные ($data) передаются по ссылке, так что overhead от пустых вызовов должен быть минимальным.
			if (($message = $this->checkFieldValue($action, $field_name, $data)) != '')
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
	public function afterSaveRow(string $action, array &$data, array $old_data): string
	{
		return '';//override
	}

/**
 * Сохранения сразу всей строки.
 *
 * Если ключевое поле == 0, то создает новую запись, иначе - обновляет поля.
 * @return string Сообщение об ошибке или пустую строку, если все хорошо
 * */
	public function saveRow(array $data): string
	{
		$this->key_value = 0;

		$data[$this->key_field] ??= 0;

		$action = ($data[$this->key_field] == 0) ? 'insert' : 'update';

		$old_data = $this->getRow($data[$this->key_field]);

		$message = $this->beforeSaveRow($action, $data, $old_data);
		if (!empty($message))
		{
			return $message;
		}

		if ($action == 'insert')
		{
//на случай если наследники при инсерте сами используют свои сиквенсы или другие источники первичного ключа
//и этот ключ уже был получен в $this->beforeSaveRow(...)
			if ($data[$this->key_field] == 0)//если наследники так ничего и ничего не выдали
			{
				$data[$this->key_field] = $this->db->nextVal($this->getSeqName());
			}
//костыль!
//если наследники сами нашли ID, например по UNIQ индексу и выдали сюда существущий ID, то
//делаем апдейт.
//некостыльный вариант - наследники меняют $action в beforeSaveRow, но надо всем наследникам во всех проектах проставить &$action
//таки пока отложим (2023-09-30).
			if (empty($this->getRawRow($data[$this->key_field])))
			{
				$ar = $this->db->insert($this->table_name, $this->key_field, $this->fields, $data)->affectedRows();
			}
			else
			{
				$ar = $this->db->update($this->table_name, $this->key_field, $this->fields, $data)->affectedRows();
			}
			//end костыль
		}
		else
		{
			$ar = $this->db->update($this->table_name, $this->key_field, $this->fields, $data)->affectedRows();
		}
		$this->affected_rows = $ar;
		$this->key_value = $data[$this->key_field];//new value will be here, in case of "insert"

		//проверки вставилось/обновилось делаем по $this->affected_rows
		if ($this->affected_rows == 1)
		{
			$message = $this->afterSaveRow($action, $data, $old_data);
			if (!empty($message))
			{
				return $message;
			}
			return '';//все хорошо
		}
		else
		{
			return "Вероятно, произошла ошибка при сохранении, т.к. количество измененных записей = {$this->affected_rows}";
		}
	}

/** Обновляет одно поле в строке.
 * @param $key_value int ключ
 * @param $field_name string имя поля
 * @param $value mixed новое значение поля
 * @return string Сообщение об ошибке или пустую строку, если все хорошо
 * */
	public function updateField(int $key_value, string $field_name, $value): string
	{
		$data = [
			$this->key_field	=> $key_value,
			$field_name			=> $value,
		];
		if (!in_array($field_name, $this->fields))
		{//ошибка на этапе разработки. в продакшене это недопустимо.
			sendBugReport("Wrong field name [{$field_name}] passed to updateField()", "FATALITY", true);
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
			if (!empty($message))
			{
				return $message;
			}
			$this->affected_rows = $this->db->update($this->table_name, $this->key_field, $field_name, $data)->affectedRows();
			return ($this->affected_rows == 1) ?
				'' ://все хорошо
				"Вероятно произошла ошибка при сохранении: количество измененных записей = {$this->affected_rows}";
		}
	}


//-------------------------------------------------------------------------------------------------------------------
//DELETE
//-------------------------------------------------------------------------------------------------------------------

/**
 * Проверка на возможность удаления строки.
 * Возвращает пустую строку, если можно удалить запись или сообщение о том, почему нельзя её удалять.
 * deleteRow() проверяет наличие пустой строки в качестве сообщения.
 * @return string Сообщение о причине невозможности удаления или пустую строку, если все можно удалять
 *
 * В наследниках можно проверять строкой ПОСЛЕ своих проверок с более осмысленными сообщениями:
if (($msg = parent::canDeleteRow($key_value)) != ''){return $msg;}

@TODO - проверить, как оно работает с констрейнтами CASCADE
 */
	public function canDeleteRow(int $key_value): string
	{
		list($schema, $table) = explode('.',$this->table_name);
/* "лишние" поля тут не трогаем, пусть будут для отладки.
 *
 * список других таблиц ссылающихся на нашу как на foreign_table
 * */
		$f_keys = $this->db->exec("
SELECT
	tc.*,
	tc.table_schema,
	tc.constraint_name, tc.table_name, kcu.column_name,
	ccu.table_schema AS foreign_table_schema,
	ccu.table_name AS foreign_table_name,
	ccu.column_name AS foreign_column_name
FROM information_schema.table_constraints AS tc
JOIN information_schema.key_column_usage AS kcu USING (constraint_schema, constraint_name)
JOIN information_schema.constraint_column_usage AS ccu USING (constraint_schema, constraint_name)
WHERE
	constraint_type = 'FOREIGN KEY' AND
	ccu.table_schema = '{$schema}' AND
	ccu.table_name = '{$table}'
")->fetchAll();

		//da($f_keys);die;
		foreach ($f_keys as $f_key_info)
		{
			//da($f_key_info);
			if ($this->db->exec("
SELECT {$f_key_info['column_name']}
FROM {$f_key_info['table_schema']}.{$f_key_info['table_name']}
WHERE {$f_key_info['column_name']} = $1", $key_value)->rows > 0)
			{
				return "Невозможно удалить запись ID={$key_value} в таблице {$this->table_name}.
У таблицы {$f_key_info['table_schema']}.{$f_key_info['table_name']} есть записи ссылающиеся на запись ID={$key_value} в таблице {$this->table_name}.";
			}
		}
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
	public function beforeDeleteRow(int $key_value): string
	{
		return '';
	}

/**
 * Действие ПОСЛЕ удаления строки.
 * Перекрываем метод, чтобы сделать что-то после удаления записи.
 * Сюда пихать зачистку файловой системы от файлов, т.к. надо сначала точно удалить запись в СУБД прежде, чем удалять файл.
 * Файл удалится наверняка, а в СУБД бывают дедлоки и прочее.
 *
 * Но все это работает только в том случае, если нужен только ID записи.
 * Саму-то запись мы уже удалили :(
 *
 * Возвращает пустую строку, если все хорошо, иначе возвращает сообщение об ошибке.
 * Чтобы не заморачиваться с возвратом - стоит всегда возвращать parent::afterDeleteRow($key_value)
 * @return string Сообщение об ошибке или пустую строку, если все хорошо
 */
	public function afterDeleteRow(int $key_value): string
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
	public function deleteRow(int $key_value): string
	{
//можем ли мы удалить запись
		$message = $this->canDeleteRow($key_value);
		if (!empty($message))
		{
			return $message;
		}
//делаем что надо ДО удаления
		$message = $this->beforeDeleteRow($key_value);
		if (!empty($message))
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
			if (!empty($message))
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


//-------------------------------------------------------------------------------------------------------------------
//Поддержка хранилища сущностей в проекте
//-------------------------------------------------------------------------------------------------------------------

/** Отдает описание сущности экземпляра модели по названию таблицы (т.е. по $this->table_name).
 */
	public function getEntityTypeInfo(bool $can_return_cache = true): array
	{
		if (isset($this->__entityTypeInfo) && $can_return_cache)
		{
			return $this->__entityTypeInfo;
		}
		else
		{
			global $application;
			$et_id = $application->getEntityTypesInstance()->byTable($this->table_name);
			return ($et_id > 0) ?
				$application->getEntityTypesInstance()->getRow($et_id)
				:
				sendBugReport('getEntityTypeInfo', "Не найден тип сущности по таблице {$this->table_name}", true);
		}
	}

/** Отдает ID  сущности.
 */
	public function getEntityTypeId(): int
	{
		return $this->getEntityTypeInfo()['id'];
	}
}