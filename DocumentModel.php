<?php
/**
 * @NAME: Class: Document Model
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2018 - Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
*/

/** CHANGELOG
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
сразу 3 класса и все наследники от simpleDictionary.

Таблицы с документами, полями, значениями полей, прицепленными файлами, словарями полей и т.п.
имеют фиксированные названия в пределах схемы проекта:
documents - документы
documents_fields - список полей для каждого типа документа
documents_fields_values - значения полей для документа
documents_values_dicts - словарь для словарных полей

document_type_id - тип документа. по-умолчанию = 0, если надо в схеме реализовать несколько
разных документов и соединить их в иерархию через parent_id, то вот тут то и надо использовать
разные document_type_id для разных типов в рамках одной таблицы.

Типы документов и их проверки пока только хардкодить, т.к. по дефолту их может и не быть, т.е. будет всего один тип с id=0 и
ради него не стоит каждый раз городить таблицу вида document_types с одной строкой только для того,
чтобы сделать 2 констрэйнта с documents и documents_fields.
Целостность проверяем в коде модели, т.е. при модификации списка полей и модификации documents.document_type_id)
смотреть во внутреннюю таблицу типов документов на предмет соответствия.
Вариант отдать редактирование типов документов юзеру не рассматривается даже в перспективе.

Поля для документов сгруппированы по типу документа, т.е. в таблице documents_fields есть ссылка на тип документа.

Каждый документ имеет родителя по ссылке в parent_id в таблице documents.
Т.е. в рамках проекта можно констрэйнтами связать дерево документов в единое целое.
Для констрэйнта надо в пустой таблице с документами создать пустой документ с ID=0 (как бы root :) )

Документы наследуются от SimpleDictionary, но их поведение более сложное, поэтому parent::saveRow() запрещен.
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

class DocumentModel extends SimpleDictionaryModel
{
	protected $document_type_id;//а оно вообще надо тут? пока еще не было больше одного типа документов в одной схеме.

	public function __construct($scheme, $document_type_id = 0)
	{
		parent::__construct($scheme.'.documents', 'id', [
			'document_type_id',
			'parent_id',
		]);
		$this->scheme = $scheme;
		$this->document_type_id = $document_type_id;

		$this->initFieldsModel($scheme, $document_type_id);
		$this->initValuesDictsModel($scheme, $document_type_id);
	}

/** перекрыть, если используется своя модель для полей
 */
	public function initFieldsModel($scheme, $document_type_id):void
	{
		//в перекрытом методе вызываем свою модель
		$this->fields_model = new Document_fieldsModel($scheme, $document_type_id);
		//не забыть прицепить ее вот таким образом к документам!
		$this->fields_model->__parent = $this;
	}

/** перекрыть, если используется своя модель для значений полей
 */
	public function initValuesDictsModel($scheme, $document_type_id):void
	{
		//в перекрытом методе вызываем свою модель
		$this->values_dicts_model = new Document_values_dictsModel($scheme, $document_type_id);
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
	document_type_id integer NOT NULL DEFAULT 0,
	parent_id integer NOT NULL DEFAULT 0,
	CONSTRAINT documents_pkey PRIMARY KEY (id),
	CONSTRAINT documents_parent_id_fkey FOREIGN KEY (parent_id)
		REFERENCES {$this->scheme}.documents (id) MATCH SIMPLE
		ON UPDATE CASCADE ON DELETE CASCADE
)
WITH (
	OIDS=FALSE
);
ALTER TABLE {$this->scheme}.documents
	OWNER TO postgres;
INSERT INTO {$this->scheme}.documents (id, document_type_id, parent_id) VALUES (0, 0, 0);

CREATE TABLE {$this->scheme}.documents_fields
(
	id serial NOT NULL,
	document_type_id integer NOT NULL DEFAULT 0,
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
	document_type_id integer NOT NULL DEFAULT 0,
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

	public function getEmptyRow()
	{
		return [
			'id'				=> 0,
			'document_type_id'	=> $this->document_type_id,
			'parent_id'			=> 0,
		];
	}

/** получить значения полей документа
 * для списков полей и getRow оно вызывается автоматом.
 * если перекрываем метод getRow и не вызываем предка, то значения заполнять именно этой функцией.
 * @param $document_id int ID документа
 */
	public function getFieldsValues($document_id): array
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
	public function getFieldValue($document_id, $field_id): array
	{
		return $this->db->exec("
SELECT f.*, v.*, f.id AS field_id
FROM {$this->scheme}.documents_fields AS f
JOIN {$this->scheme}.documents_fields_values AS v ON (v.field_id = f.id)
WHERE document_id = $1 AND f.id = $2
", $document_id, $field_id)->fetchRow();
	}

	private function getList_action($action): string
	{//ну не люблю я конструкцию CASE во всех языках :)
		if ($action == 'more') {return '>';}
		if ($action == 'less') {return '<';}
		if ($action == 'eq') {return '=';}
		if ($action == 'ne') {return '!=';}
		if ($action == 'is_null') {return 'is_null';}
	}

/** фильтруемый список документов
 * 'without_fields' => true, - не грузить атрибуты. по умолчанию они грузятся. если надо быстро получить ID документов, то поля грузить не надо.
 */
	public function getList($params = [])
	{
		$params['from'] = $params['from'] ?? "{$this->table_name} AS d";
		$params['where'] = $params['where'] ?? [];

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
		}

//чтобы не было в выдаче рутового документа
		$params['where'][] = "d.id > 0";

		$f = 'parent_id';
		if (isset($params[$f]) && $params[$f] > 0)
		{
			$params['where'][] = "{$f} = {$params[$f]}";
		}

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

				$action = $this->getList_action($params['filter_actions'][$field_id] ?? 'eq');
				//da("$field_id $action");

				$field_info = $this->fields_model->getRow($field_id);
				if ($action == 'is_null')
				{
					$params['where'][] = "v{$field_id}.int_value IS NULL";
				}
				else
				{
					if (($field_info['value_type'] == 'K')|| ($field_info['value_type'] == 'X'))
					{//		TO_DO - сделать сравнение по значению для больше-меньше и по ключу - когда равно
						if (($action == '=')||($action == '!='))
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
						$params['where'][] = "v{$field_id}.value {$action} '{$value}'";
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
			$params['from'] .= "\nLEFT OUTER JOIN {$this->scheme}.documents_fields_values AS v{$field_id} ON (v{$field_id}.document_id = d.id AND v{$field_id}.field_id = {$field_id})";
		}
//по-умолчанию отдаем только атрибуты документа
		$params['select'] = isset($params['select']) ? $params['select'] : "d.*";

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
	public function generateFieldValue($field_id, $value)
	{
		$result = ['id' => $field_id];
		$field_info = $this->fields_model->getRow($field_id);
		$field_name = $this->fields_model->value_field_names[$field_info['value_type']];
		$result['value'] = $result[$field_name] = $value;
		return $result;
	}

/** сохранение значения поля.
 * собственно, это самый ценный метод этого класса.
 * всё остальное нужно, чтобы работал этот метод :)
 *
 * тут есть все мыслимые проверки валидности.
 * если на выходе пустая строка - все хорошо, иначе там описание ошибки.
 *
 * логика:
- если value равно null - просто удаляем старое значение и выходим
- если поле неправильное, то выводим ошибку и выходим
- если все хорошо, то удаляем поле и вставляем его заново с новым значением
- триггер, обновляющий value в {DOCUMENTS}_fields_values работает только на вставку!
*/
	public function saveFieldValue($document_id = 0, $field_id = 0, $value = null)
	{
		if ($document_id == 0){die('DocumentModel.saveFieldsValue: $document_id == 0');}	// - absolutely
		if ($field_id == 0){die('DocumentModel.saveFieldsValue: $field_id == 0');}			// - barbaric!

		$field_info = $this->fields_model->getRow($field_id);
		//da($value);da($field_info);die;
		if ($field_info == false){die("DocumentModel.saveFieldsValue: UNKNOWN field_id = {$field_id}");}
		$delete_clause = "DELETE FROM {$this->scheme}.documents_fields_values WHERE document_id = $1 AND field_id = $2";
		//установка value -> null - удаляет значение поля
		if (!isset($value) || trim($value) == '' ||
			((in_array($field_info['value_type'], ['K', 'X'])) && ($value == 0)))
		{
			$this->db->exec($delete_clause, $document_id, $field_id);
			return '';
		}
		//далее value уже точно не null
		$value = $hr_value = trim($value);
		$result = "Field [{$field_info['title']}] with value [{$value}]: Unknown value_type [{$field_info['value_type']}]";//ошибка по-умолчанию
		$insert_clause = '';
		if ($field_info['value_type'] == 'A')//string
		{
			if ($value == '')
			{
				return "{$field_info['title']} - [{$value}]: Ожидается непустая строка";
			}
			$db_field = 'text_value';
			$result = '';
		}
		if ($field_info['value_type'] == 'I')//integer
		{
			$value = preg_replace("/\D/", '', $value);
			if ($value == '')
			{
				return "{$field_info['title']} - [{$value}]: Ожидается целое число";
			}
			$db_field = 'int_value';
			$result = '';
		}
		if ($field_info['value_type'] == 'B')// boolean
		{
			$value = preg_replace("/\D/", '', $value);
			if ($value == '')
			{
				return "{$field_info['title']} - [{$value}]: Ожидается число 0 или 1";
			}
			$value = ($value != 0) ? 1 : 0;//всё, кроме нуля - ДА
			$hr_value = ($value != 0) ? 'да' : 'нет';
			$db_field = 'int_value';
			$result = '';
		}
		if ($field_info['value_type'] == 'F')//float
		{
			$value = preg_replace("/\,/", '.', $value);
			$value = preg_replace("/\s+/", '', $value);
			if (!is_numeric($value + 0))
			{
				return "{$field_info['title']} - [{$value}]: Ожидается вещественное число";
			}
			$hr_value = $value;
			$db_field = 'float_value';
			$result = '';
		}
		if (in_array($field_info['value_type'], ['K', 'X']))//dictionary
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
			$db_field = 'int_value';
			$result = '';
		}
		if ($field_info['value_type'] == 'D')//date
		{
			$value = trim($value);
			$matches = [];
			if (!preg_match("/(\d{1,2})[\.\-](\d{1,2})[\.\-](\d{4})/", $value, $matches))
			{
				return "{$field_info['title']} - [{$value}]: Ожидается формат даты ДД-ММ-ГГГГ";
			}
			if (!checkdate($matches[2],$matches[1],$matches[3]))
			{
				return "{$field_info['title']} - [{$value}]: Неверная дата. Формат даты ДД-ММ-ГГГГ";
			}
			$value = $matches[1].'-'.$matches[2].'-'.$matches[3];
			$hr_value = $matches[1].'-'.$matches[2].'-'.$matches[3];
			$db_field = 'date_value';
			$result = '';
		}

		if ($result == '')
		{
			//da("$document_id, $field_id, $value, $hr_value");die;
			//проверяем - есть ли такой значение
			if ($this->db->exec("
SELECT document_id
FROM {$this->scheme}.documents_fields_values
WHERE document_id = $1 AND field_id = $2 AND {$db_field} = $3", $document_id, $field_id, $value)->rows == 0)
			{//нет значения - удаляем старое и добавляем новое
				$insert_clause = "INSERT INTO {$this->scheme}.documents_fields_values (document_id, field_id, {$db_field}, value) VALUES ($1,$2,$3,$4)";
				$this->db->exec("BEGIN");
				$this->db->exec($delete_clause, $document_id, $field_id);
				$this->db->exec($insert_clause, $document_id, $field_id, $value, $hr_value);
				$this->db->exec("COMMIT");
			}
			//наша задача, чтобы поле БЫЛО
			//и если оно там уже есть, то ничего не делаем
		}
		return $result;
	}

	public function getRow($key_value)
	{
		$row = parent::getRow($key_value);
		if ($row !== false)
		{
			$row['fields'] = $this->getFieldsValues($row['id']);
		}
		return $row;
	}

	public function saveRow($data)
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
		if (!isset($this->document_type_id))
		{
			return "Не задан тип документа";
		}
		$data['parent_id'] ??= 0;
		$data['document_type_id'] = $this->document_type_id;
		$this->document_id = $data['id'] = $this->db->nextVal($this->getSeqName());
		$ar = $this->db->insert($this->table_name, $this->key_field, $this->fields, $data)->affectedRows();
		return ($ar == 1) ? '' : "Произошла ошибка при сохранении документа [#{$this->document_id}]: количество измененных записей = {$ar}";
	}

	/** В наследниках проходим по всем автоматизированным полям и вычисляем/сохраняем.
	 * Ошибку в виде строки выводим при любом сохранении любого поля (первого попавшегося) - все должно работать без ошибок!
	 * @param $document_id int ID документа
	 */
	public function calcAutomatedFields($document_id = 0): string
	{
		if ($document_id == 0){die('Lost $document_id in calcAutomatedFields($document_id = 0)');}

		/* ШАБЛОН для наследников
		foreach (array_keys($this->fields_model->getList(['automated' => 1])) as $field_id)
		{

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
class Document_fieldsModel extends SimpleDictionaryModel
{
	/** Приватный локальный кеш данных
	 */
	private $data_cash = [];

	/** Типы данных полей - названия для юзеров
	 */
	public $value_types = [
		'A'	=> 'Строковый', // alphabet
		'I'	=> 'Целый', // integer
		'F'	=> 'Вещественный', // float
		'D'	=> 'Дата', // date
		'K'	=> 'Словарный', // key values
		'X'	=> 'Внешний словарь', // key values
		'B'	=> 'Логический', // integer (0|1), 0 - false, not 0 - true
	];

	/** Типы данных полей - названия поля в базе
	 */
	public $value_field_names = [
		'A'	=> 'text_value', // alphabet
		'I'	=> 'int_value', // integer
		'F'	=> 'float_value', // float
		'D'	=> 'date_value', // date
		'K'	=> 'int_value', // key values
		'X'	=> 'int_value', // key values
		'B'	=> 'int_value', // integer
	];

	/** Типы данных полей - названия полей, по которым надо их сортировать
	 */

	public $sort_field_names = [
		'A'	=> 'text_value', // alphabet
		'I'	=> 'int_value', // integer
		'F'	=> 'float_value', // float
		'D'	=> 'date_value', // date
		'K'	=> 'value', // key values
		'X'	=> 'value', // key values
		'B'	=> 'int_value', // integer
	];

	public function __construct($scheme, $document_type_id = 0)
	{
		parent::__construct($scheme.'.documents_fields', 'id', [
			'title', 'value_type', 'measure', 'sort_order', 'description', 'automated',
			'x_value_field_name', 'x_table_name', 'x_table_order', 'x_list_url',
		]);
		$this->scheme = $scheme;
		$this->document_type_id = $document_type_id;
	}

/** Отдает массив значений для словарных полей в виде хеша
 * id =>
 * 	'id'	=> ID_значения
 * 	'value'	=> значение в виде текста
 */
	public function getValues($field_info)
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
				if (!isset($field_info[$f]))
				{
					die("Для поля #{$field_info['id']} {$field_info['title']} типа Внешний словарь не задано поле [{$f}]");
				}
			}

			return $this->db->exec("SELECT id, {$field_info['x_value_field_name']} AS value
FROM {$field_info['x_table_name']}
ORDER BY {$field_info['x_table_order']}")->fetchAll('id');
		}
		else
		{
			return [];
		}
	}

/** Надо инициализировать запись для интерфейсов редактирования полей документов
 */
	public function getEmptyRow()
	{
		return [
			'id'				=> 0,
			'title'				=> '',
			'measure'			=> '',
			'description'		=> '',
			'value_type'		=> 'I',
			'sort_order'		=> 0,
			'automated'			=> 0,
		];
	}

/** Надо для интерфейсов редактирования полей документов.
 * Релизовано кеширование с хранение в массиве этого же класса ($data_cash)
 */
	public function getRow($key_value)
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
				if ($this->data_cash[$key_value] !== false)
				{
					$this->data_cash[$key_value]['values'] = $this->getValues($this->data_cash[$key_value]);
				}
			}
			return $this->data_cash[$key_value];
		}
	}

	public function getList($params = [])
	{
		if (!isset($params['order']) || $params['order'] == '')
		{
			$params['order'] = 'sort_order';
		}
		$params['pkey'] = 'id';
		$params['where'] ??= [];

		$params['where'][] = "document_type_id = {$this->document_type_id}";

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

//заполняем значения словарных полей
		foreach ($list as $id => $field_info)
		{
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
	public function getDistinctValues($key_value)
	{
		return array_keys($this->db->exec("SELECT DISTINCT(value) AS value FROM {$this->scheme}.documents_fields_values WHERE field_id = $1",
			$key_value)->fetchAll('value'));
	}

/** Для интерфейсов редактирования полей документов
 */
	public function saveRow($data)
	{
		if (!isset($data['id']) || intval($data['id']) == 0)
		{//запрет создания полей
			return "Потерян ID поля.";
		}
		$data['sort_order'] = $data['sort_order'] ?? 0;
		$data['automated'] = $data['automated'] ?? 0;

		$old_data = $this->getRow($data['id']);

		$data['value_type'] = $old_data['value_type'];//тип не меняем из интерфейса.

		if ($data['value_type'] == 'X')
		{//эти атрибуты не меняем из интерфейса.
			foreach (['x_value_field_name', 'x_table_name', 'x_table_order', 'x_list_url',] as $f)
			{
				$data[$f] = $old_data[$f];
			}
		}

		if (trim($data['title']) == '')
		{
			return "Название поля не может быть пустым";
		}
		$ar = $this->db->update($this->table_name, $this->key_field, $this->fields, $data)->affectedRows();
		if ($ar != 1)
		{
			return "Ошибка при сохранении атрибутов поля. Возможно, поле с ID={$data['id']} не существует";
		}
		return '';
	}

/** иногда триггеры работают неправильно или мы меняем тип поля на словарный.
 * тогда надо вызвать этот метод для глобального апдейта
 */
	public function updateDictValues()
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
	public function canDeleteRow($key_value)
	{
		if ($this->db->exec("
SELECT f.id
FROM {$this->scheme}.documents_fields_values AS v
JOIN {$this->scheme}.documents_fields AS f ON (f.id = v.field_id)
WHERE v.field_id = {$key_value} LIMIT 1")->rows > 0)
		{
			return "На удаляемое поле [{$key_value}] ссылаются какие-то документы. Нужно их ВСЕХ отредактировать перед удалением значения.";
		}
		return '';
	}
}

/** Словарики для простых словарных полей
 * ID значений уникальны в рамках схемы
 */
class Document_values_dictsModel extends SimpleDictionaryModel
{
	public function __construct($scheme)
	{
		parent::__construct($scheme.'.documents_values_dicts', 'id', [
			'field_id', 'value',
		]);
		$this->scheme = $scheme;
	}

	public function getList($params = [])
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

	public function canDeleteRow($key_value)
	{
		if ($this->db->exec("
SELECT f.id
FROM {$this->scheme}.documents_fields_values AS v
JOIN {$this->scheme}.documents_fields AS f ON (f.id = v.field_id)
WHERE f.value_type='K' AND v.int_value = {$key_value} LIMIT 1")->rows > 0)
		{
			return "На удаляемое значение [{$key_value}] ссылаются какие-то документы. Нужно их ВСЕХ отредактировать перед удалением значения.";
		}
		return '';
	}

	public function getMetaData()
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