<?php
/*
 * @NAME: Class: Document Model
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2018 - Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
*/

/** CHANGELOG
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

/*
Модель - вариант сложного словаря:
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
documents_files - прицепленные файлы

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

proposed for deletion 2021-04-14 Поля имеют атрибут field_group_id integer - тип поля с т.з. функционала, отображения и т.п. (hidden, например),
либо _группы_ полей с т.з. автоматизируемого процесса, если полей слишком много и их надо как-то раскидывать по экрану/формам и т.п.

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
0. через интерфейс его как правило создать нельзя, ибо там поля которые прямо вставляются в запрос и это место для SQL-инъекций
1. создаем новое поле целого типа, заполняем название, сортировку, единицы измерения - через интерфейс, если он есть.
2. идем в базу и там к этому полю делаем (пусть наше новое поле с ID=60)
shipments -- например
update shipments.documents_fields
set
  value_type = 'X',
  x_value_field_name = 'name',
  x_table_name  = 'shipments.container_types',
  x_table_order  = 'name',
  x_list_url  = '/shipments/containertypes'
where id = 60


*/

class DocumentModel extends SimpleDictionaryModel
{
	protected $document_type_id;//TODO: а оно вообще надо тут? пока еще не было больше одного типа документов в одной схеме.

	function __construct($scheme, $document_type_id = 0)
	{
		parent::__construct($scheme.'.documents', 'id', [
			'document_type_id',
			'parent_id',
		]);
		$this->scheme = $scheme;
		$this->document_type_id = $document_type_id;

		$this->initFieldsModel($scheme, $document_type_id);
		$this->initValuesDictsModel($scheme, $document_type_id);
		$this->initFilesModel($scheme, $document_type_id);
	}

/** перекрыть, если используется своя модель для полей
 */
	public function initFieldsModel($scheme, $document_type_id)
	{
		//в перекрытом методе вызываем свою модель
		$this->fields_model = new Document_fieldsModel($scheme, $document_type_id);
		//не забыть прицепить ее к документам!
		$this->fields_model->__parent = $this;
	}

/** перекрыть, если используется своя модель для значений полей
 */
	public function initValuesDictsModel($scheme, $document_type_id)
	{
		//в перекрытом методе вызываем свою модель
		$this->values_dicts_model = new Document_values_dictsModel($scheme, $document_type_id);
		//не забыть прицепить ее к документам!
		$this->values_dicts_model->__parent = $this;
	}

/** перекрыть, если используется своя модель для файлов
 */
	public function initFilesModel($scheme, $document_type_id)
	{
		//в перекрытом методе вызываем свою модель
		$this->files_model = new Document_filesModel($scheme, HOME_DIR.'/'.$scheme);
		//не забыть прицепить ее к документам!
		$this->files_model->__parent = $this;
	}

/** USAGE:
print $this->__getDataStructureScript();die('stopped');
потом открыть исходник и скопировать отформатированный скрипт.
копия из браузера - идет без форматирования, а PgAdmin ругается на одну длинную строку.
не использовать da(); - там в коде есть ' который экранируется при выводе print_r() и который не нужен в SQL
 */
	public function __getDataStructureScript()
	{
		return "
DROP TABLE {$this->scheme}.documents_files;
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
  --proposed for deletion 2021-04-14 field_group_id integer NOT NULL DEFAULT 0,-- тип поля с т.з. функционала, отображения и т.п. (hidden, например), либо группы полей с т.з. автоматизируемого процесса, если полей слишком много
  value_type character(1), -- тип значения
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
--  BEFORE INSERT
--  ON {$this->scheme}.documents_fields_values
--  FOR EACH ROW
--  EXECUTE PROCEDURE {$this->scheme}.documents_fields_values_ins_func();

CREATE TABLE {$this->scheme}.documents_files
(
  id serial NOT NULL,
  document_id integer,
  file_type_id integer, --тип файла - (например: отчет, скан запроса, скан кредитноты), ссылка на таблицу или массив в коде
  file_name text,
  file_ext character varying,
  file_size integer,
  CONSTRAINT documents_files_pkey PRIMARY KEY (id),
  CONSTRAINT documents_files_document_id_fkey FOREIGN KEY (document_id)
      REFERENCES {$this->scheme}.documents (id) MATCH SIMPLE
      ON UPDATE CASCADE ON DELETE CASCADE
)
WITH (
  OIDS=FALSE
);
ALTER TABLE {$this->scheme}.documents_files
  OWNER TO postgres;


CREATE TRIGGER log_history AFTER INSERT OR UPDATE OR DELETE ON {$this->scheme}.documents FOR EACH ROW EXECUTE PROCEDURE log_history();
CREATE TRIGGER log_history AFTER INSERT OR UPDATE OR DELETE ON {$this->scheme}.documents_fields FOR EACH ROW EXECUTE PROCEDURE log_history();
CREATE TRIGGER log_history AFTER INSERT OR UPDATE OR DELETE ON {$this->scheme}.documents_fields_values FOR EACH ROW EXECUTE PROCEDURE log_history();
CREATE TRIGGER log_history AFTER INSERT OR UPDATE OR DELETE ON {$this->scheme}.documents_values_dicts FOR EACH ROW EXECUTE PROCEDURE log_history();
CREATE TRIGGER log_history AFTER INSERT OR UPDATE OR DELETE ON {$this->scheme}.documents_files FOR EACH ROW EXECUTE PROCEDURE log_history();
";
	}

	public function getEmptyRow()
	{
		return [
			'id'			=> 0,
			'document_type_id'	=> $this->document_type_id,
			'parent_id'		=> 0,
		];
	}

	public function getFieldsValues($document_id)
	{
		return $this->db->exec("
SELECT f.*, v.*, f.id AS field_id
FROM {$this->scheme}.documents_fields AS f
JOIN {$this->scheme}.documents_fields_values AS v ON (v.field_id = f.id)
WHERE document_id = $1
ORDER BY f.sort_order", $document_id)->fetchAll('field_id');
	}

	public function getFiles($document_id)
	{
		return $this->files_model->getList([
			'document_id'	=> $document_id,
		]);
	}

	private function getList_action($action)
	{
		if ($action == 'more') return '>';
		if ($action == 'less') return '<';
		if ($action == 'eq') return '=';
	}

/**
 * without_fields = true - не грузить атрибуты
 */
	public function getList($params = [])
	{
		$params['from'] = $params['from'] ?? "{$this->table_name} AS d";

		$params['where'] = $params['where'] ?? [];

		//эти поля будут прицеплены через
		//LEFT OUTER JOIN {$this->scheme}.documents_fields_values AS v ON (v.document_id = d.id AND v.field_id = FIELD_ID
		//ожидается просто массив номеров (ID) полей
		$params['fields_to_join'] = $params['fields_to_join'] ?? [];

		$params['index'] = $params['index'] ?? 'id';//не надо тут d.id!

		$params['order_direction'] = $params['order_direction'] ?? 'DESC';

		if (isset($params['raw_order']))
		{
			$params['order'] = $params['raw_order'];
		}
		else
		{
			if (!isset($params['order']))
			{
				$params['order'] = 'd.id '.$params['order_direction'];
			}
			else
			{// - $params['order'] ID поля для сортировки
				if ($params['order'] == 0)
				{
					$params['order'] = 'd.id '.$params['order_direction'];
				}
				else
				{
					$params['fields_to_join'][] = $params['order']; // ожидается в $params['order'] ID поля для сортировки
					$field_info = $this->fields_model->getRow($params['order']);
					$field_name = $this->fields_model->sort_field_names[$field_info['value_type']];
					//так будет работать всё, кроме словарных полей
					$params['order'] = "v{$params['order']}.{$field_name} {$params['order_direction']} NULLS LAST, d.id DESC";
				}
			}
		}

		//чтобы не было в выдаче рутового документа
		$params['where'][] = "d.id > 0";

		if (isset($params['parent_id']) && $params['parent_id'] > 0)
		{
			$params['where'][] = "parent_id = {$params['parent_id']}";
		}


		//da('FILTER');		da($params['filter']);
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

				if (($field_info['value_type'] == 'K')|| ($field_info['value_type'] == 'X'))
				{//TO_DO - сделать сравнение по значению для больше-меньше и по ключу - когда равно
					if ($action == '=')
					{
						$params['where'][] = "v{$field_id}.int_value = {$value}";
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
				{
					$value = preg_replace("/(\d{1,2})-(\d{1,2})-(\d{4})/", "$3-$2-$1", $value);
					$params['where'][] = "v{$field_id}.date_value {$action} '{$value}'";
				}
				else
				{
					$params['where'][] = "v{$field_id}.value {$action} '{$value}'";
				}
				$params['fields_to_join'][] = $field_id;
			}
		}
		//da($params['where']);		da($params); die();

		foreach (array_unique($params['fields_to_join']) as $field_id)
		{
			$params['from'] .= "\nLEFT OUTER JOIN {$this->scheme}.documents_fields_values AS v{$field_id} ON (v{$field_id}.document_id = d.id AND v{$field_id}.field_id = {$field_id})";
		}

		$params['select'] = isset($params['select']) ? $params['select'] : "d.*";

		$list = parent::getList($params);
		//$this->db->print_r();die();

		foreach ($list as $row)
		{
			if (!isset($params['without_fields']))
			{
				$list[$row['id']]['fields'] = $this->getFieldsValues($row['id']);
			}
			if (!isset($params['without_files']))
			{
				$list[$row['id']]['files'] = $this->getFiles($row['id']);
			}
		}
		return $list;
	}

	public function generateFieldValue($field_id, $value)
	{
		$result = ['id' => $field_id];
		$field_info = $this->fields_model->getRow($field_id);
		$field_name = $this->fields_model->value_field_names[$field_info['value_type']];
		$result['value'] = $result[$field_name] = $value;
		return $result;
	}

	public function saveFieldValue($document_id = 0, $field_id = 0, $value = null)
	{
//если value равно null - просто удаляем старое значение и выходим
//если поле неправильное, то выводим ошибку и выходим
//если все хорошо, то удаляем поле и вставляем его заново с новым значением
//триггер, обновляющий value в {DOCUMENTS}_fields_values работает только на вставку!
		if ($document_id == 0){die('DocumentModel.saveFieldsValue: $document_id == 0');}	// - absolutely
		if ($field_id == 0){die('DocumentModel.saveFieldsValue: $field_id == 0');}			// - barbaric!

		$field_info = $this->fields_model->getRow($field_id);
		//da($field_info);
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
		if ($field_info['value_type'] == 'A')
		{
			if ($value == '')
			{
				return "{$field_info['title']} - [{$value}]: Ожидается непустая строка";
			}
			$db_field = 'text_value';
			$result = '';
		}
		if ($field_info['value_type'] == 'I')
		{
			$value = preg_replace("/\D/", '', $value);
			if ($value == '')
			{
				return "{$field_info['title']} - [{$value}]: Ожидается целое число";
			}
			$db_field = 'int_value';
			$result = '';
		}
		if ($field_info['value_type'] == 'F')
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
		if (in_array($field_info['value_type'], ['K', 'X']))
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
		if ($field_info['value_type'] == 'D')
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
			$value = $matches[1].'.'.$matches[2].'.'.$matches[3];
			$db_field = 'date_value';
			$result = '';
		}

		if ($result == '')
		{
			if ($this->db->exec("
SELECT document_id
FROM {$this->scheme}.documents_fields_values
WHERE document_id = $1 AND field_id = $2 AND {$db_field} = $3", $document_id, $field_id, $value)->rows == 0)
			{
				$insert_clause = "INSERT INTO {$this->scheme}.documents_fields_values (document_id, field_id, {$db_field}, value) VALUES ($1,$2,$3,$4)";
				$this->db->exec("BEGIN");
				$this->db->exec($delete_clause, $document_id, $field_id);
				$this->db->exec($insert_clause, $document_id, $field_id, $value, $hr_value);
				$this->db->exec("COMMIT");
			}
		}
		return $result;
	}

	public function getRow($key_value)
	{
		$row = parent::getRow($key_value);
		if ($row !== false)
		{
			$row['fields'] = $this->getFieldsValues($row['id']);
			$row['files'] = $this->getFiles($row['id']);
		}
		return $row;
	}

	public function saveRow($data)
	{
		$msg = "DocumentModel::saveRow() is disabled, Use DocumentModel::createRow() and DocumentModel::updateField() instead!";
		sendBugReport($msg);
		die($msg);
	}

	public function createRow($data)
	{
		if (!isset($this->document_type_id))
		{
			return "Не задан тип документа";
		}
		$data['parent_id'] = $data['parent_id'] ?? 0;
		$data['document_type_id'] = $this->document_type_id;
		$this->document_id = $data['id'] = $this->db->nextVal($this->getSeqName());
		$ar = $this->db->insert($this->table_name, $this->key_field, $this->fields, $data)->affectedRows();
		return ($ar == 1) ? '' : "Произошла ошибка при сохранении документа [#{$this->document_id}]: количество измененных записей = {$ar}";
	}
}

class Document_fieldsModel extends SimpleDictionaryModel
{
	private $data_cash = [];

	public $value_types = [
		'A'	=> 'Строковый', // alphabet
		'I'	=> 'Целый', // integer
		'F'	=> 'Вещественный', // float
		'D'	=> 'Дата', // date
		'K'	=> 'Словарный', // key values
		'X'	=> 'Внешний словарь', // key values
	];

	public $value_field_names = [
		'A'	=> 'text_value', // alphabet
		'I'	=> 'int_value', // integer
		'F'	=> 'float_value', // float
		'D'	=> 'date_value', // date
		'K'	=> 'int_value', // key values
		'X'	=> 'int_value', // key values
	];

	public $sort_field_names = [
		'A'	=> 'text_value', // alphabet
		'I'	=> 'int_value', // integer
		'F'	=> 'float_value', // float
		'D'	=> 'date_value', // date
		'K'	=> 'value', // key values
		'X'	=> 'value', // key values
	];

	function __construct($scheme, $document_type_id = 0)
	{
		parent::__construct($scheme.'.documents_fields', 'id', [
			'title', 'value_type', 'measure', 'sort_order',
			'x_value_field_name', 'x_table_name', 'x_table_order', 'x_list_url',
		]);
		$this->scheme = $scheme;
		$this->document_type_id = $document_type_id;
	}

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
					die("Для поля #{$field_info['id']} {$field_info['title']} типа Внешний словарь не задано поле [$f]");
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

	public function getEmptyRow()
	{
		return [
			'id'				=> 0,
			'title'				=> '',
			'measure'			=> '',
			'value_type'		=> 'I',
			'sort_order'		=> 0,
		];
	}

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
		$params['where'] = $params['where'] ?? [];

		$params['where'][] = "document_type_id = {$this->document_type_id}";

		$list = parent::getList($params);
		if (isset($params['add_default_value']))
		{
			$default_value_title = $params['default_value_title'] ?? '-- Выберите --';
		}

		foreach ($list as $id => $field_info)
		{
			if (in_array($field_info['value_type'], ['K', 'X']))
			{
				$list[$id]['values'] = $this->getValues($field_info);
				if (isset($params['add_default_value']))
				{
					$list[$id]['values'] = [0 => $default_value_title] + $list[$id]['values'];
				}
			}
		}
		return $list;
	}

	public function getDistinctValues($key_value)
	{
		return array_keys($this->db->exec("SELECT DISTINCT(value) AS value  FROM {$this->scheme}.documents_fields_values WHERE field_id = $1",
			$key_value)->fetchAll('value'));
	}

	public function getMetaData()
	{
		return [
			'pk'	=> $this->key_field,
			'fields'=> [
				'title'	=> [
					'title'	=> 'Название',
					'width' => 200,
					'type'	=> 'string',
				],
				'value_type'	=> [
					'title'	=> 'Тип значения',
					'width' => 50,
					'type'	=> 'string',
				],
				'measure'	=> [
					'title'	=> 'Ед.изм.',
					'width' => 20,
					'type'	=> 'string',
				],
				'sort_order'	=> [
					'title'	=> 'Сортировка',
					'width' => 10,
					'type'	=> 'integer',
				],
			]//fields
		];
	}

	public function saveRow($data)
	{
		if (!isset($data['id']) || intval($data['id']) == 0)
		{//запрет создания полей
			return "Потерян ID поля.";
		}
		if (!isset($data['sort_order']))
		{
			$data['sort_order'] = 0;
		}
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
			return "Описание поля не может быть пустым";
		}
		//da($this->fields);		da($data);		da($this->table_name);		die();
		$ar = $this->db->update($this->table_name, $this->key_field, $this->fields, $data)->affectedRows();
		/*
		$ar = $this->db->exec("UPDATE {$this->scheme}.documents_fields SET title = $2, measure = $3, sort_order = $4 WHERE id = $1",
			$data['id'], trim($data['title']), trim($data['measure']), $data['sort_order'])->affectedRows();
			*/
		if ($ar != 1)
		{
			return "Ошибка при сохранении атрибутов поля. Возможно, поле ID={$data['id']} уже не существует";
		}
		return '';
	}
}

class Document_values_dictsModel extends SimpleDictionaryModel
{
	function __construct($scheme)
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
		$params['where'] = $params['where'] ?? [];

		if (isset($params['field_id']))
		{
			$params['where'][] = "field_id = {$params['field_id']}";
		}

		return parent::getList($params);
	}

	public function canDeleteRow($key_value)
	{
		if ($this->db->exec("
SELECT f.id
FROM {$this->scheme}.documents_fields_values AS v
JOIN {$this->scheme}.documents_fields AS f ON (f.id = v.field_id)
WHERE f.value_type='K' AND v.int_value = $key_value LIMIT 1")->rows > 0)
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

class Document_filesModel extends SimpleFilesModel
{
	function __construct($scheme, $storage_path, $allowed_extensions = [], $max_file_size = 0)
	{
		parent::__construct($scheme.'.documents_files', 'id', [
				'document_id', 'file_type_id', 'file_name', 'file_ext', 'file_size',
			],
				$storage_path, $allowed_extensions, $max_file_size
		);
		$this->scheme = $scheme;
	}

	public function getList($params = [])
	{
		if (!isset($params['order']) || $params['order'] == '')
		{
			$params['order'] = 'file_type_id, file_name';
		}
		$params['where'] = $params['where'] ?? [];

		if (isset($params['document_id']))
		{
			$params['where'][] = "document_id = {$params['document_id']}";
		}
		return parent::getList($params);
	}
}