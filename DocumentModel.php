<?php
namespace YAVPL;

/**
 * @NAME: Class: Document Model
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2018 - Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
*/

/** CHANGELOG
 *
 * 1.07
 * DATE: 2023-12-31
 * удалены поля parent_id и document_typy_id - теперь это дело наследников.
 * начаты работы над 3-м куском кода использующим документы - систему CRM
 *
 *
 * 1.06
 * DATE: 2022-02-09
 * в словарь полей добавлен логический тип (0/1) 0 - false/нет, всё что не 0 - true/да
 *
 * 1.05
 * DATE: 2022-01-26
 * в словарь полей добавлены поля description(текст) и automated (0/1)
 * удалена модель для работы с файлами. файлы к документам теперь тлько через MPFL
 *
 * 1.04
 * DATE: 2021-06-3
 *

 * 1.03
 * DATE: 2021-04-14
 * в словаре полей (Document_fieldsModel) убрано поле field_group_id. за 3 года так и не пригодилось.

 * 1.02
 * DATE: 2018-10-15
 * В добавлены параметры:
 * add_default_value - к списку значений из словаря добавлять нулевое значение - типа НЕ ВЫБРАНО
 * default_value_title - заголовок для нулевого значения
	пример для controller->edit():
	$this->shipments->documents->fields_model->getList([
		'add_default_value'		=> 1,
		'default_value_title'	=> '-- Значение не выбрано --',
	]);
 *
 * 1.01
 * DATE: 2018-10-04
 * в словаре полей (Document_fieldsModel) убрано поле field_type, добавлено поле field_group_id integer
 *
 * 1.00
 * DATE: 2018-03-06
 * файл впервые опубликован
*/

/**
Модель Документ - вариант сложного словаря:
1. произвольное количество атрибутов (или их очень много, чтобы выдумывать им названия в базе)
2. прицепленные файлы
3. дерево документов
4. для каждого проекта таблица с документами и полями может быть своя (в своей схеме)

Модель толстая и, в отличии от предка, манипулирует сразу несколькими таблицами, потому в этом файле
сразу 3 класса и все наследники от DbTable.

Таблицы с документами, полями, значениями полей, прицепленными файлами, словарями полей и т.п.
имеют фиксированные названия в пределах схемы проекта:
documents - документы
documents_fields - список полей для каждого типа документа
documents_fields_values - значения полей для документа
documents_values_dicts - словарь для словарных полей



Документы наследуются от DBTable, но их поведение более сложное, поэтому parent::saveRow() запрещен.
Для создания нового документа надо применять метод creatRow().
Модификация документа допустима через изменение атрибутов и поштучный вызов updateField().

Для инициализации проекта имеет смысл использовать вывод функции
print $this->__getDataStructureScript();die('stopped');
см. её код.

Создание нового поля с внешним словарем.

0. через интерфейс его, как правило, создать нельзя, ибо там поля, которые прямо вставляются в запрос и это место для SQL-инъекций.
1. создаем новое поле целого типа, заполняем название, сортировку, единицы измерения - через интерфейс, если он есть.
2. идем в базу и там к этому полю делаем (пусть наше новое поле с ID=60):
shipments -- например
update shipments.documents_fields
set
	value_type = 'X',
	x_value_field_name = 'name',
	x_table_name	= 'shipments.container_types',
	x_table_order	= 'name',
	x_list_url	= '/shipments/containertypes'
where id = 60
*/

class DocumentModel extends DbTable
{
	public $list_actions = [
		'more'		=> '>',
		'less'		=> '<',
		'eq'		=> '=',
		'ne'		=> '!=',
		'is_null'	=> 'is_null',
		'substr'	=> 'substr',
	];

	public function __construct(string $scheme)
	{
		parent::__construct($scheme.'.documents', 'id', []);
		$this->scheme = $scheme;

		$this->initFieldsModel($scheme);
		$this->initValuesDictsModel($scheme);
	}

/** перекрыть, если используется своя модель для полей
 */
	public function initFieldsModel(string $scheme):void
	{
		//в перекрытом методе вызываем свою модель
		$this->fields_model = new Document_fieldsModel($scheme);
		//не забыть прицепить ее вот таким образом к документам!
		$this->fields_model->__parent = $this;
	}

/** перекрыть, если используется своя модель для значений полей
 */
	public function initValuesDictsModel(string $scheme):void
	{
		//в перекрытом методе вызываем свою модель
		$this->values_dicts_model = new Document_values_dictsModel($scheme);
		//не забыть прицепить ее вот таким образом к документам!
		$this->values_dicts_model->__parent = $this;
	}

/** когда создаем новый тип документов, можно сразу получить SQL скрипт для создания всех таблиц схемы
 * USAGE:
print $this->__getDataStructureScript();die('stopped');
потом открыть исходник и скопировать отформатированный скрипт.
копия из браузера - идет без форматирования, а PgAdmin ругается на одну длинную строку.
не использовать da(); - там в коде есть ' который экранируется при выводе print_r() и который не нужен в SQL
 */
	public function __getDataStructureScript(): string
	{
		return "
DROP TABLE {$this->scheme}.documents_values_dicts;
DROP TABLE {$this->scheme}.documents_fields_values;
DROP TABLE {$this->scheme}.documents_fields;
DROP TABLE {$this->scheme}.documents;

CREATE TABLE {$this->scheme}.documents
(
	id serial NOT NULL,
	CONSTRAINT documents_pkey PRIMARY KEY (id)
)
WITH (
	OIDS=FALSE
);
ALTER TABLE {$this->scheme}.documents
	OWNER TO postgres;

CREATE TABLE {$this->scheme}.documents_fields
(
	id serial NOT NULL,
	title character varying, -- заголовок поля для форм и таблиц
	value_type character(1), -- тип значения
	description text, -- комментарий к полю
	measure character varying, -- ед.изм. значения. например длина в метрах, кредит-нота в долларах
	sort_order integer NOT NULL DEFAULT 0, -- для сортировки в списке полей
	x_value_field_name text DEFAULT 'name', -- для типа Внешний словарь (X): название для поля с значением (как правило - name)
	x_table_name text,-- для типа Внешний словарь (X): полное название таблицы - схема+таблица
	x_table_order text,-- для типа Внешний словарь (X): сортировака значений
	x_list_url text,-- для типа Внешний словарь (X): ссылка на список редактирования словаря
	CONSTRAINT documents_fields_pkey PRIMARY KEY (id)
)
WITH (
	OIDS=FALSE
);
ALTER TABLE {$this->scheme}.documents_fields
	OWNER TO postgres;

CREATE TABLE {$this->scheme}.documents_fields_values
(
	document_id integer NOT NULL,
	field_id integer NOT NULL,
	int_value integer,
	float_value double precision,
	date_value date,
	text_value text,
	value text,
	CONSTRAINT documents_fields_values_pkey PRIMARY KEY (document_id, field_id),
	CONSTRAINT documents_fields_values_field_id_fkey FOREIGN KEY (field_id)
		REFERENCES {$this->scheme}.documents_fields (id) MATCH SIMPLE
		ON UPDATE CASCADE ON DELETE RESTRICT,
	CONSTRAINT documents_fields_values_document_id_fkey FOREIGN KEY (document_id)
		REFERENCES {$this->scheme}.documents (id) MATCH SIMPLE
		ON UPDATE CASCADE ON DELETE CASCADE
)
WITH (
	OIDS=FALSE
);
ALTER TABLE {$this->scheme}.documents_fields_values
	OWNER TO postgres;

CREATE TABLE {$this->scheme}.documents_values_dicts
(
	id serial NOT NULL,
	field_id integer NOT NULL,
	value text,
	CONSTRAINT documents_values_dicts_pkey PRIMARY KEY (id),
	CONSTRAINT documents_values_dicts_field_id_fkey FOREIGN KEY (field_id)
		REFERENCES {$this->scheme}.documents_fields (id) MATCH SIMPLE
		ON UPDATE CASCADE ON DELETE CASCADE
)
WITH (
	OIDS=FALSE
);
ALTER TABLE {$this->scheme}.documents_values_dicts
	OWNER TO postgres;

CREATE INDEX documents_values_dicts_field_id_idx
	ON {$this->scheme}.documents_values_dicts
	USING btree
	(field_id);

-- warning about trigger, see below
CREATE OR REPLACE FUNCTION {$this->scheme}.documents_fields_values_ins_func()
	RETURNS trigger AS
\$BODY\$
DECLARE
	field_info {$this->scheme}.documents_fields%ROWTYPE;
BEGIN
	SELECT * INTO field_info FROM {$this->scheme}.documents_fields WHERE id = NEW.field_id;
	NEW.value = CASE WHEN field_info.value_type = 'K'
	THEN
		(SELECT value FROM {$this->scheme}.documents_values_dicts AS d WHERE d.field_id = NEW.field_id AND d.id = NEW.int_value)
	ELSE
		COALESCE(NEW.int_value::text, NEW.float_value::text, NEW.text_value, to_char(NEW.date_value, 'DD-MM-YYYY'))
	END AS value;
	RETURN NEW;
END;\$BODY\$
	LANGUAGE plpgsql VOLATILE
	COST 100;
ALTER FUNCTION {$this->scheme}.documents_fields_values_ins_func()
	OWNER TO postgres;

-- avoid trigger to work field with type X, all through PHP!
-- CREATE TRIGGER documents_fields_values_ins_trg
--	BEFORE INSERT
--	ON {$this->scheme}.documents_fields_values
--	FOR EACH ROW
--	EXECUTE PROCEDURE {$this->scheme}.documents_fields_values_ins_func();


CREATE TRIGGER log_history AFTER INSERT OR UPDATE OR DELETE ON {$this->scheme}.documents FOR EACH ROW EXECUTE PROCEDURE log_history();
CREATE TRIGGER log_history AFTER INSERT OR UPDATE OR DELETE ON {$this->scheme}.documents_fields FOR EACH ROW EXECUTE PROCEDURE log_history();
CREATE TRIGGER log_history AFTER INSERT OR UPDATE OR DELETE ON {$this->scheme}.documents_fields_values FOR EACH ROW EXECUTE PROCEDURE log_history();
CREATE TRIGGER log_history AFTER INSERT OR UPDATE OR DELETE ON {$this->scheme}.documents_values_dicts FOR EACH ROW EXECUTE PROCEDURE log_history();
";
	}

	public function getEmptyRow(): array
	{
		return [
			'id'				=> 0,
		];
	}

/** получить значения полей документа
 * для списков полей и getRow оно вызывается автоматом.
 * если перекрываем метод getRow и не вызываем предка, то значения заполнять именно этой функцией.
 * @param $document_id int ID документа
 */
	public function getFieldsValues(int $document_id): array
	{
		return $this->db->exec("
SELECT f.*, v.*, f.id AS field_id
FROM {$this->scheme}.documents_fields AS f
JOIN {$this->scheme}.documents_fields_values AS v ON (v.field_id = f.id)
WHERE document_id = $1
ORDER BY f.sort_order", $document_id)->fetchAll('field_id');
	}

/** получить значение одного поля
 * например в calcAutomatedFields()
 * @param $document_id int ID документа
 */
	public function getFieldValue(int $document_id, int $field_id)
	{
		return $this->db->exec("
SELECT f.*, v.*, f.id AS field_id
FROM {$this->scheme}.documents_fields AS f
JOIN {$this->scheme}.documents_fields_values AS v ON (v.field_id = f.id)
WHERE document_id = $1 AND f.id = $2
", $document_id, $field_id)->fetchRow();
	}

/** фильтруемый список документов
 * 'without_fields' => true, - не грузить атрибуты. по умолчанию они грузятся. если надо быстро получить ID документов, то поля грузить не надо.
 */
	public function getList(array $params = []): array
	{
		$params['from'] ??= "FROM {$this->table_name} AS d";
		$params['where'] ??= [];

//эти поля будут прицеплены через
//LEFT OUTER JOIN {$this->scheme}.documents_fields_values AS v ON (v.document_id = d.id AND v.field_id = FIELD_ID
//ожидается просто массив номеров (ID) полей
		$params['fields_to_join'] ??= [];
		$params['index'] ??= 'id';//не надо тут d.id! это индекс для хеша в PHP
		$params['order_direction'] ??= 'DESC';//как сортировать записи

		//если значения одинаковые, то как сортировать их (например, ORDER BY v16.date_value ASC, id DESC)
		$params['order_default_field'] ??= 'd.id';
		$params['order_default_direction'] ??= 'DESC';

//если не получается сортировать по полю, а надо сложно - приоритет на этом параметре.
//значение просто вставляется в SQL запрос
		if (isset($params['raw_order']))
		{
			$params['order'] = $params['raw_order'];
		}
		else
		{//иначе считаем, что в $params['order'] ID поля для сортировки - в отличии от предка этого класса!
			if (!isset($params['order']))
			{
				$params['order'] = $params['order_default_field'].' '.$params['order_direction'];
			}
			else
			{// - $params['order'] ID поля для сортировки
				if (is_numeric($params['order']))
				{
					if ($params['order'] == 0)
					{
						$params['order'] = $params['order_default_field'].' '.$params['order_direction'];
					}
					else
					{// ожидается в $params['order'] ID поля для сортировки
						$params['fields_to_join'][] = $params['order']; //добавляем его к прицепляемым (left outer join ) полям
						$field_info = $this->fields_model->getRow($params['order']);
						$field_name = $this->fields_model->sort_field_names[$field_info['value_type']];
						//так будет работать всё, кроме словарных полей
						$params['order'] = "v{$params['order']}.{$field_name} {$params['order_direction']} NULLS LAST, ".
							$params['order_default_field'].' '.$params['order_default_direction'];
					}
				}
				else
				{
					$params['order'] = $params['order_default_field'].' '.$params['order_direction'];
				}
			}
		}

//?? чтобы не было в выдаче рутового документа
		$params['where'][] = "d.id > 0";

		/*
		$f = 'parent_id';
		if (isset($params[$f]) && $params[$f] > 0)
		{
			$params['where'][] = "{$f} = {$params[$f]}";
		}*/

		//da('FILTER');		da($params['filter']);

//универсальный фильтр по значениям полей
//передаем сюда поле, действие и значение.
//всё вместе идет как AND по всем полям.
//кому надо OR делает несколько запросов :)
		if (isset($params['filter_values']) && (count($params['filter_values']) > 0))
		{
			//структура: $params['filter']
			// ID-поля => значение для поиска
			foreach ($params['filter_values'] as $field_id => $value)
			{
				$value = trim($value);
				if (is_string($value) && $value == ''){continue;}
				if (is_numeric($value) && $value == 0) {continue;}

				$action = $this->list_actions[$params['filter_actions'][$field_id] ?? 'eq'];
				//da("$field_id $action");

				$field_info = $this->fields_model->getRow($field_id);
				if ($action == 'is_null')
				{
					$params['where'][] = "v{$field_id}.int_value IS NULL";
				}
				else
				{
					if (($field_info['value_type'] == 'K')|| ($field_info['value_type'] == 'X'))
					{//сравнение по значению для больше-меньше и по ключу - когда равно / не равно
						if (($action == '=') || ($action == '!='))
						{
							$params['where'][] = "v{$field_id}.int_value {$action} {$value}";
						}
						else
						{
							$params['where'][] = "v{$field_id}.value {$action} '".$field_info['values'][$value]['value']."'";
						}
					}
					elseif ($field_info['value_type'] == 'I')
					{
						$params['where'][] = "v{$field_id}.int_value {$action} {$value}";
					}
					elseif ($field_info['value_type'] == 'B')
					{
						if ($value != '')
						{
							$params['where'][] = "v{$field_id}.int_value = ".(($value == 'yes') ? 1 : 0);
						}
					}
					elseif ($field_info['value_type'] == 'F')
					{
						$params['where'][] = "v{$field_id}.float_value {$action} {$value}";
					}
					elseif ($field_info['value_type'] == 'D')
					{//дата приходит в русском формате (DD-MM-YYYY), переводим его в естественный YYYY-MM-DD
						$value = preg_replace("/(\d{1,2})-(\d{1,2})-(\d{4})/", "$3-$2-$1", $value);
						$params['where'][] = "v{$field_id}.date_value {$action} '{$value}'";
					}
					else
					{
						if ($action == 'substr')
						{
							$params['where'][] = "v{$field_id}.value ILIKE '%{$value}%'";
						}
						else
						{
							$params['where'][] = "v{$field_id}.value {$action} '{$value}'";
						}
					}
				}
				$params['fields_to_join'][] = $field_id;
			}
		}
		//da($params['where']);		da($params); die();

//прицепляем к запросу все используемые поля
//в селектах/сортировке/фильтрах появляются таблицы v{$field_id}
		foreach (array_unique($params['fields_to_join']) as $field_id)
		{
			$params['from'] .= "
LEFT OUTER JOIN {$this->scheme}.documents_fields_values AS v{$field_id}
	ON (v{$field_id}.document_id = d.id AND v{$field_id}.field_id = {$field_id})";
		}
//по-умолчанию отдаем только атрибуты документа
		$params['select'] ??= "SELECT d.*";

		$list = parent::getList($params);
		//$this->db->print_r();die();

		foreach ($list as $row)
		{
			if (!isset($params['without_fields']))
			{
				$list[$row['id']]['fields'] = $this->getFieldsValues($row['id']);
			}
		}
		return $list;
	}

/** если надо сделать поля по умолчанию для методов типа ->edit() для новой записи/документа
 * то можно сгенерировать правильную структуру поля этим методом
 * $this->document_info['fields'] = [
		13	=> $this->claims->documents->generateFieldValue(13, $this->user->id),//заявитель
		3	=> $this->claims->documents->generateFieldValue(3, date('d-m-Y')),
	];
 */
	public function generateFieldValue(int $field_id, $value)
	{
		$result = ['id' => $field_id];
		$field_info = $this->fields_model->getRow($field_id);
		$field_name = $this->fields_model->value_field_names[$field_info['value_type']];
		$result['value'] = $result[$field_name] = $value;
		return $result;
	}

/** Сохранение значения поля.
 * Собственно, это самый ценный метод этого класса.
 * Всё остальное нужно, чтобы работал этот метод :)
 *
 * тут есть все мыслимые проверки валидности.
 * если на выходе пустая строка - все хорошо, иначе там описание ошибки.
 *
 * логика:
- если value равно (null||''||0 для словарей) - просто удаляем старое значение и выходим
- если поле неправильное, то выводим ошибку и выходим
- если все хорошо, то удаляем поле и вставляем его заново с новым значением
- триггер, обновляющий value в {DOCUMENTS}_fields_values работает только на вставку!
*/
	public function saveFieldValue(int $document_id = 0, int $field_id = 0, $value = null): string
	{
		if ($document_id == 0){die('DocumentModel.saveFieldsValue: $document_id == 0');}	// - absolutely
		if ($field_id == 0){die('DocumentModel.saveFieldsValue: $field_id == 0');}			// - barbaric!

		$field_info = $this->fields_model->getRow($field_id);
		//da($value);da($field_info);die;
		if ($field_info == false)
		{
			die("DocumentModel.saveFieldsValue: UNKNOWN field_id = {$field_id}");
		}

		$delete_clause = "DELETE FROM {$this->scheme}.documents_fields_values WHERE document_id = $1 AND field_id = $2";

		//если $value равно NULL или пустой строке, то оно удаляется.
		//если оно равно 0, то это вполне себе целое, вещественное или булево значение, которое хранится.
		//но если оно равно 0 для словаря - то удаляем.
		//далее value уже точно не null
		$value = $hr_value = trim($value ?? '');

		//установка value -> '' - удаляет значение поля
		if (($value == '') ||
			(in_array($field_info['value_type'], ['K', 'X']) && ($value == 0))
			)
		{
			$this->db->exec($delete_clause, $document_id, $field_id);
			return '';
		}


		$insert_clause = '';
		if (in_array($field_info['value_type'], ['A', 'M', 'H']))//string
		{
			//теоретически тут хранятся совсем произвольные данные, хотя мы уже раньше сделалии trim
			/* мы ее уже удалили и сделали return выше
			if ($value == '')
			{
				return "{$field_info['title']} - [{$value}]: Ожидается непустая строка";
			}*/
		}
		elseif ($field_info['value_type'] == 'I')//integer
		{
			$value = preg_replace("/\D/", '', $value);
			if ($value == '')
			{
				return "{$field_info['title']} - [{$value}]: Ожидается целое число";
			}
		}
		elseif ($field_info['value_type'] == 'B')// boolean
		{
			//da($value);
			$value = intval(preg_replace("/\D/", '', $value));
			//da($value);
			$value = ($value != 0) ? 1 : 0;//всё, кроме нуля - ДА
			//da($value);
			if ($value == '')
			{
				return "{$field_info['title']} - [{$value}]: Ожидается число 0 или 1";
			}
			$hr_value = ($value != 0) ? 'да' : 'нет';
			//da($value);da($hr_value);die;
		}
		elseif ($field_info['value_type'] == 'F')//float
		{
			$value = preg_replace("/\,/", '.', $value);
			$value = preg_replace("/[^\-\d\.]/", '', $value);
			//$value = preg_replace("/[\s\xA0]+/", '', $value);
			//$value = filter_var($value, FILTER_VALIDATE_FLOAT);
			if (!is_numeric($value + 0))
			{
				return "{$field_info['title']} - [{$value}]: Ожидается вещественное число";
			}
			$hr_value = $value;
		}
		elseif (in_array($field_info['value_type'], ['K']))//dictionary
		{
			$value = preg_replace("/\D/", '', $value);
			if ($value == '')
			{
				return "{$field_info['title']} - [{$value}]: Ожидается индекс в словаре";
			}
			if (!isset($field_info['values'][$value]))
			{
				return "Значение {$value} не найдено в словаре для поля {$field_info['title']}";
			}
			$hr_value = $field_info['values'][$value]['value'];
		}
		elseif (in_array($field_info['value_type'], ['X']))//external dictionary or any table
		{
			$value = preg_replace("/\D/", '', $value);
			if ($value == '')
			{
				return "{$field_info['title']} - [{$value}]: Ожидается ID записи в таблице {$field_info['x_table_name']}";
			}
			if ($this->db->exec("SELECT {$field_info['x_value_field_name']} FROM {$field_info['x_table_name']} WHERE id = $1", $value)->rows == 0)
			{
				return "Значение {$value} не найдено в таблице {$field_info['x_table_name']} для поля {$field_info['title']}";
			}
			$hr_value = $this->db->fetchRow()[$field_info['x_value_field_name']];
		}

		elseif ($field_info['value_type'] == 'D')//date
		{
			$value = trim($value);
			$matches = [];
			if (!preg_match("/(\d{1,2})[\.\-](\d{1,2})[\.\-](\d{4})/", $value, $matches))
			{
				return "Поле {$field_info['title']} имеет значение [{$value}]: Ожидается формат даты ДД-ММ-ГГГГ";
			}
			if (!checkdate($matches[2], $matches[1], $matches[3]))
			{
				return "Поле {$field_info['title']} имеет значение [{$value}]: Неверная дата. Формат даты ДД-ММ-ГГГГ";
			}
			$value = $matches[1].'-'.$matches[2].'-'.$matches[3];
			$hr_value = $matches[1].'-'.$matches[2].'-'.$matches[3];
		}
		elseif ($field_info['value_type'] == 'Z')//files
		{
			$hr_value = $value;//количество файлов
		}
		elseif ($field_info['value_type'] == 'T')//table in JSON
		{
			/* не может быть тут пустой строки.
			if ($value == '')
			{
				return "{$field_info['title']} - [{$value}]: Ожидается непустая строка";
			}*/
			//$db_field = 'text_value';
			//$result = '';
		}
		else
		{
			return "Field [{$field_info['title']}] with value [{$value}]: Unknown value_type [{$field_info['value_type']}]";//ошибка по-умолчанию
		}

		//тут все хорошо, все проверки сделали early return до этого места
		$db_field = $this->fields_model->value_field_names[$field_info['value_type']];

		if ($this->db->exec("
SELECT document_id
FROM {$this->scheme}.documents_fields_values
WHERE document_id = $1 AND field_id = $2 AND {$db_field} = $3", $document_id, $field_id, $value)->rows == 0)
		{//нет такого значения - удаляем старое и добавляем новое
			$insert_clause = "
INSERT INTO {$this->scheme}.documents_fields_values (document_id, field_id, {$db_field}, value) VALUES ($1,$2,$3,$4)
ON CONFLICT ON CONSTRAINT documents_fields_values_pkey DO NOTHING";
			//собственно изменение в базе
			$this->db->exec("BEGIN");
			$this->db->exec($delete_clause, $document_id, $field_id);
			$this->db->exec($insert_clause, $document_id, $field_id, $value, $hr_value);
			$this->db->exec("COMMIT");
		}
		//наша задача, чтобы поле БЫЛО и было с определенным значением
		//и если оно там уже есть, то ничего не делаем
		return '';
	}

	public function getRow(int $key_value): ?array
	{
		$row = parent::getRow($key_value);
		if (!empty($row))
		{
			$row['fields'] = $this->getFieldsValues($row['id']);
		}
		return $row;
	}

	public function saveRow(array $data): string
	{
		$msg = "DocumentModel::saveRow() is disabled, Use DocumentModel::createRow() and DocumentModel::updateField() instead!";
		sendBugReport($msg);
		die($msg);
	}
/**
 * Создаем запись.
 * Выводим пустую строку, если все хорошо, либо сообщение об ошибке.
 */
	public function createRow(array $data):string
	{
		$this->document_id = $data['id'] = $this->db->nextVal($this->getSeqName());
		$ar = $this->db->insert($this->table_name, $this->key_field, $this->fields, $data)->affectedRows();
		return ($ar == 1) ? '' : "Произошла ошибка при сохранении документа [#{$this->document_id}]: количество измененных записей = {$ar}";
	}

/** В наследниках проходим по всем автоматизированным полям и вычисляем/сохраняем.
 * Ошибку в виде строки выводим при любом сохранении любого поля (первого попавшегося) - все должно работать без ошибок!
 * @param $document_id int ID документа
 * @param $fields_ids - если задан, о проходим только по указанным автоматизированным полям. иначе по всем автоматизированным
 * @return string сообщение об ошибке или пустая строка если все хорошо
 */
	public function calcAutomatedFields(int $document_id = 0, array $fields_ids = []): string
	{
		if ($document_id == 0){die('Lost $document_id in calcAutomatedFields($document_id = 0)');}

		/* ШАБЛОН для наследников
		foreach (array_keys($this->fields_model->getList(['automated' => 1])) as $field_id)
		{
			//если передан список полей для автообновления - используем строго его. иначе - по всем полям.
			if (count($fields_ids) > 0 && !in_array($field_id, $fields_ids)){continue;}


			if (in_array($field_id, [71]))
			{//
				$fv1 = $this->getFieldValue($document_id, 16);
				$fv2 = $this->getFieldValue($document_id, 4);
				if (($fv1 !== false) && ($fv2 !== false))
				{
					$field_value1 = $fv1['date_value'];
					$field_value2 = $fv2['date_value'];
					//da($value);			da($new_field_value);
					if (($field_value1 != '') && ($field_value2 != ''))
					{//
						//da("$field_value16	$field_value4");
						$new_value = $this->db->exec("SELECT ($1::date - $2::date) AS v", $field_value1, $field_value2)->fetchRow()['v'];
						$msg = $this->saveFieldValue($document_id, $field_id, $new_value);
						if ($msg != ''){ return $msg; }
					}
				}
			}
		}
		// END ШАБЛОН
		*/
	}
}

/**
 * Поля документа.
 */
class Document_fieldsModel extends DbTable
{
	/** Приватный локальный кеш данных
	 */
	private array $data_cash = [];

	/** Типы данных полей - названия для юзеров
	 */
	public array $value_types = [
		'A'	=> 'Строковый', // alphabet (input type=text)
		'M'	=> 'Многострочный текст', // alphabet / MEMO field (textarea)
		'H'	=> 'Текст с разметкой', // alphabet / textarea + WYSIWYG editor
		'I'	=> 'Целый', // integer
		'F'	=> 'Вещественный', // float
		'D'	=> 'Дата', // date
		'K'	=> 'Словарный', // key values
		'X'	=> 'Внешний словарь', // key values
		'B'	=> 'Логический', // integer (0|1), 0 - false, not 0 - true
		'T'	=> 'Таблица',//описание структуры в x_description, данные в отдельной таблице
		'Z'	=> 'Файлы',
		'S'	=> 'Специальное поле',//Special Field
	];

	/** Типы данных полей - названия поля в базе
	 */
	public array $value_field_names = [
		'A'	=> 'text_value', // alphabet
		'M'	=> 'text_value', // alphabet
		'H'	=> 'text_value', // alphabet
		'I'	=> 'int_value', // integer
		'F'	=> 'float_value', // float
		'D'	=> 'date_value', // date
		'K'	=> 'int_value', // key values
		'X'	=> 'int_value', // key values
		'B'	=> 'int_value', // integer
		'T'	=> 'int_value', // заглушка
		'Z'	=> 'int_value', // files has no values here
		'S'	=> 'text_value', // кастомные поля это спец структуры и спец код на кадый экземпляр поля. допустимо хранение данных в формате JSON
	];

	/** Типы данных полей - названия полей, по которым надо их сортировать	 */
	public array $sort_field_names = [
		'A'	=> 'text_value', // alphabet
		'M'	=> 'text_value', // alphabet
		'H'	=> 'text_value', // alphabet
		'I'	=> 'int_value', // integer
		'F'	=> 'float_value', // float
		'D'	=> 'date_value', // date
		'K'	=> 'value', // key values
		'X'	=> 'value', // key values
		'B'	=> 'int_value', // integer
		'T'	=> 'int_value',	// заглушка, по таблицам сортировать нельзя
		'Z'	=> 'int_value',	// files has no values here
		'S'	=> 'text_value', // кастомные поля - не участвуют в списках
	];

	public array $value_type_cgi_types = [
		'A'	=> 'string', // alphabet
		'M'	=> 'string', // alphabet
		'H'	=> 'string', // alphabet
		'I'	=> 'integer', // integer
		'F'	=> 'float', // float
		'D'	=> 'string', // date
		'K'	=> 'integer', // key values
		'X'	=> 'integer', // key values
		'B'	=> 'integer', // integer (0|1), 0 - false, not 0 - true
		'T'	=> 'string', // заглушка, таблицы сохраняются отдельно
		'Z'	=> 'integer',// количество загруженных файлов
		'S'	=> 'string',// информация для списков, специфичная для каждого поля
	];

/** Типы данных полей - ширина и высота по умолчанию, в "em"
 * [width, height]*/
	public array $value_type_sizes = [
		'A'	=> [25, 1],
		'M'	=> [25, 5],
		'H'	=> [25, 5],
		'I'	=> [25, 1],
		'F'	=> [25, 1],
		'D'	=> [ 6, 1],
		'K'	=> [25, 1],
		'X'	=> [25, 1],
		'B'	=> [ 1, 1],//не имеет смысла, т.к. это радио кнопки
		'T'	=> [25, 5],	//рисуется в блоке с полями, редактируется отдельно
		'Z'	=> [ 1, 1],	//рисуется в отдельной форме
		'S'	=> [ 1, 1],	//рисуется в отдельной форме
	];

	public function __construct(string $scheme)
	{
		parent::__construct($scheme.'.documents_fields', 'id', [
			'title', 'value_type', 'measure', 'sort_order', 'description', 'automated',
			'x_value_field_name', 'x_table_name', 'x_table_order', 'x_list_url', 'x_description', 'x_fill_values',
			'width', 'height',
			'use_in_index_sort',
		]);
		$this->scheme = $scheme;
	}

/** Отдает массив значений для словарных полей в виде хеша
 * id =>
 * 	'id'	=> ID_значения
 * 	'value'	=> значение в виде текста
 */
	public function getValues(array $field_info): array
	{
		if ($field_info['value_type'] == 'K')
		{
			return $this->__parent->values_dicts_model->getList([
				'field_id'	=> $field_info['id'],
			]);
		}
		elseif ($field_info['value_type'] == 'X')
		{
			foreach (['x_value_field_name', 'x_table_name', 'x_table_order'] as $f)
			{
				if (empty($field_info[$f]))
				{
					//die("Для поля #{$field_info['id']} {$field_info['title']} типа Внешний словарь не задано поле [{$f}]");
					return [];
				}
			}
			if ($field_info['x_fill_values'] == 1)
			{
				$query = "
SELECT id, {$field_info['x_value_field_name']} AS value
FROM {$field_info['x_table_name']}
ORDER BY {$field_info['x_table_order']}
LIMIT 1000";
				return $this->db->exec($query)->fetchAll('id');
			}
			else
			{
				return [];
			}
		}
		else
		{
			return [];
		}
	}

/** Инициализирует пустую запись.
 * Для форм редактирования полей документов.
 */
	public function getEmptyRow(): array
	{
		$row = parent::getEmptyRow();
		$row['value_type'] = 'I';
		$row['sort_order'] = 0;
		$row['automated'] = 0;
		$row['width'] = 0;
		$row['height'] = 0;
		$row['use_in_index_sort'] = 1;
		$row['x_fill_values'] = 1;
		return $row;
	}

/** Надо для интерфейсов редактирования полей документов.
 * Релизовано кеширование с хранение в массиве этого же класса ($data_cash)
 */
	public function getRow(int $key_value): ?array
	{
		if ($key_value == 0)
		{
			return $this->getEmptyRow();
		}
		else
		{
			if (!isset($this->data_cash[$key_value]))
			{
				$this->data_cash[$key_value] = $this->db->exec("
SELECT * FROM {$this->table_name} WHERE {$this->key_field} = $1", $key_value)->fetchRow();
				if (!empty($this->data_cash[$key_value]))
				{
					$this->data_cash[$key_value]['values'] = $this->getValues($this->data_cash[$key_value]);
					$this->data_cash[$key_value]['cgi_type'] = $this->value_type_cgi_types[$this->data_cash[$key_value]['value_type']] ??
						die("Cannot find field type {$this->data_cash[$key_value]['value_type']} in Fields->value_type_cgi_types");
				}
			}
			return $this->data_cash[$key_value];
		}
	}

/** Возвращает список полей. Для интерйесов редактирования полей.
 */
	public function getList(array $params = []): array
	{
		if (!isset($params['order']) || $params['order'] == '')
		{
			$params['order'] = 'sort_order';
		}
		$params['pkey'] = 'id';
		$params['where'] ??= [];

		foreach (['automated'] as $f)
		{
			if (isset($params[$f]))
			{
				$params['where'][] = "{$f} = {$params[$f]}";
			}
		}
		foreach (['value_type'] as $f)
		{
			if (isset($params[$f]))
			{
				$params['where'][] = "{$f} = '{$params[$f]}'";
			}
		}

		$list = parent::getList($params);

//если надо для списков значений словарей делать нулевое/невыбранное значение - текст делаем тут
		if (isset($params['add_default_value']))
		{
			$default_value_title = $params['default_value_title'] ?? '-- Выберите --';
		}

		foreach ($list as $id => $field_info)
		{
			$list[$id]['cgi_type'] = $this->value_type_cgi_types[$field_info['value_type']] ??
				die("Cannot find field type {$field_info['value_type']} in Fields->value_type_cgi_types");

			//заполняем значения словарных полей
			if (in_array($field_info['value_type'], ['K', 'X']))
			{
				$list[$id]['values'] = $this->getValues($field_info);
				//если надо для списков значений словарей делать нулевое/невыбранное значение
				if (isset($params['add_default_value']))
				{
					$list[$id]['values'] = [0 => $default_value_title] + $list[$id]['values'];
				}
			}
		}
		return $list;
	}

/** Отдает все значения для поля.
 * Надо, например, если в поле ID юзера и надо взять всех юзеров, которые упоминались во всех документах.
 * Для фильтров списков документом по юзеру, например. Чтобы не все юзеры, а только те, кто упоминался в авторах документов.
 * Работает, очевидно, долго и когда-нибудь работать перестанет :)
 */
	public function getDistinctValues(int $key_value): array
	{
		return array_keys($this->db->exec("SELECT DISTINCT(value) AS value FROM {$this->scheme}.documents_fields_values WHERE field_id = $1",
			$key_value)->fetchAll('value'));
	}

/** Для интерфейсов редактирования полей документов

ALTER TABLE IF EXISTS shipments.documents_fields ADD COLUMN width integer DEFAULT 0;
ALTER TABLE IF EXISTS shipments.documents_fields ADD COLUMN height integer DEFAULT 0;

update shipments.documents_fields set width = 0, height = 0;

update shipments.documents_fields set width=20 where id in (41,42,43,44,45,46);

ALTER TABLE IF EXISTS shipments.documents_fields ALTER COLUMN width SET NOT NULL;
ALTER TABLE IF EXISTS shipments.documents_fields ALTER COLUMN height SET NOT NULL;
 */
	public function beforeSaveRow(string $action, array &$data, array $old_data): string
	{
		$data['sort_order'] ??= 0;
		$data['automated'] ??= 0;
		$data['use_in_index_sort'] ??= 1;

		if ($action == 'update')
		{
			$data['value_type'] = $old_data['value_type'];//тип явно не меняем из интерфейса.
		}

		$f = 'width';
		if (($data[$f] ?? 0) <= 0)
		{
			$data[$f] = $this->value_type_sizes[$data['value_type']][0];
		}

		$f = 'height';
		if (($data[$f] ?? 0) <= 0)
		{
			$data[$f] = $this->value_type_sizes[$data['value_type']][1];
		}

		//для внешнего словаря проверяем существование таблицы и наличие указанных полей в таблице
		///da($data);
		if ($data['value_type'] == 'X')
		{
			//заполнять значения - либо 1 либо 0
			$data['x_fill_values'] = (($data['x_fill_values'] ?? 0) == 1) ? 1 : 0;

			if ($data['x_table_name'] != '')
			{
				$table_info = (new \YAVPL\PostgresQL())->getFieldsList($data['x_table_name']);
				//da($table_info);return 'sss';
				if (empty($table_info))
				{
					return "Не найдена таблица [{$data['x_table_name']}]";
				}
				foreach (['x_value_field_name', 'x_table_order', ] as $f)
				{
					if ($data[$f]!= '' && !isset($table_info[$data[$f]]))
					{
						return "Не найдено поле [{$data[$f]}] в таблице {$data['x_table_name']}";
					}
				}
				if ($data['x_list_url'] == '')
				{
					$data['x_list_url'] = '/'.preg_replace("/\./", '/', $data['x_table_name']);
				}
			}

			//return 'Только разработчик может создавать поля типа Внешний словарь, т.к. для этого требуется модификация исходного кода.';
		}

		if ($action == 'insert')
		{
			if ($data['value_type'] == 'X')
			{
				//return 'Только разработчик может создавать поля типа Внешний словарь, т.к. для этого требуется модификация исходного кода.';
			}

			if ($data['value_type'] == 'T')
			{
				return 'Только разработчик может создавать поля этого типа.';
			}
		}

		//переключение между типами строковых полей автоматическое
		if ($data['height'] > 1 && $data['value_type'] == 'A')
		{
			$data['value_type'] = 'M';
		}
		if ($data['height'] == 1 && $data['value_type'] == 'M')
		{
			$data['value_type'] = 'A';
		}

		$f = 'x_description';
		$data[$f] = trim($data[$f] ?? '', " \r\n\t");
		if ($data[$f] == '')
		{
			$data[$f] = json_encode([]);
		}
		json_decode($data[$f]);
		if (json_last_error() !== JSON_ERROR_NONE)
		{
			return "Ошибка формата JSON в поле {$f}.";
		}

		if (trim($data['title'] ?? '') == '')
		{
			$data['title'] = 'Новое поле '.time();
		}

		return '';
	}

/** иногда триггеры работают неправильно или мы меняем тип поля на словарный.
 * тогда надо вызвать этот метод для глобального апдейта
 */
	public function updateDictValues(): void
	{
		$this->db->exec("
UPDATE {$this->scheme}.documents_fields_values
SET value = to_char(date_value, 'DD-MM-YYYY')
WHERE field_id IN (SELECT id FROM {$this->scheme}.documents_fields WHERE value_type = 'D')");

		$this->db->exec("
UPDATE {$this->scheme}.documents_fields_values
SET value = (SELECT value FROM {$this->scheme}.documents_values_dicts AS d WHERE d.field_id = field_id AND d.id = int_value)
WHERE field_id IN (SELECT id FROM {$this->scheme}.documents_fields WHERE value_type = 'K')");

		foreach ($this->db->exec("SELECT * FROM {$this->scheme}.documents_fields WHERE value_type = 'X'")->fetchAll() as $field_info)
		{
			$this->db->exec("
UPDATE {$this->scheme}.documents_fields_values
SET value = (SELECT {$field_info['x_value_field_name']} AS value FROM {$field_info['x_table_name']} AS d WHERE d.id = int_value)
WHERE field_id = $1", $field_info['id']);
		}
	}

/** Нельзя удалять поля с имеющимися значениями
 */
	public function canDeleteRow(int $key_value): string
	{
		if ($this->db->exec("
SELECT f.id
FROM {$this->scheme}.documents_fields_values AS v
JOIN {$this->scheme}.documents_fields AS f ON (f.id = v.field_id)
WHERE v.field_id = {$key_value} LIMIT 1")->rows > 0)
		{
			return "На удаляемое поле [{$key_value}] ссылаются какие-то документы. Нужно их ВСЕ отредактировать перед удалением значения.";
		}
		return '';
	}
}

/** Словарики для простых словарных полей
 * ID значений уникальны в рамках схемы
 */
class Document_values_dictsModel extends DbTable
{
	public function __construct(string $scheme)
	{
		parent::__construct($scheme.'.documents_values_dicts', 'id', [
			'field_id', 'value',
		]);
		$this->scheme = $scheme;
	}

	public function getList(array $params = []): array
	{
		if (!isset($params['order']) || $params['order'] == '')
		{
			$params['order'] = 'value';
		}
		$params['where'] ??= [];

		$f = 'field_id';
		if (isset($params[$f]))
		{
			$params['where'][] = "{$f} = {$params[$f]}";
		}

		return parent::getList($params);
	}

	public function canDeleteRow(int $key_value): string
	{
		if ($this->db->exec("
SELECT f.id
FROM {$this->scheme}.documents_fields_values AS v
JOIN {$this->scheme}.documents_fields AS f ON (f.id = v.field_id)
WHERE f.value_type='K' AND v.int_value = {$key_value} LIMIT 1")->rows > 0)
		{
			return "На удаляемое значение [{$key_value}] ссылаются какие-то документы. Нужно их ВСЕ отредактировать перед удалением значения.";
		}
		return '';
	}

	public function getMetaData(): array
	{
		return [
			'pk'	=> $this->key_field,
			'fields'=> [
				'field_id'	=> [
					'title'	=> 'Поле',
					'hidden' => true,
					'type'	=> 'string',
				],
				'value'	=> [
					'title'	=> 'Значение',
					'width' => 400,
					'type'	=> 'string',
				],
			]//fields
		];
	}
}