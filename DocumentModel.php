<?php
/*
 * @NAME: Class: Document Model
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2018 - Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
*/

/** CHANGELOG
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

Поля имеют атрибут field_group_id integer - тип поля с т.з. функционала, отображения и т.п. (hidden, например),
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
*/

class DocumentModel extends SimpleDictionaryModel
{
	protected $document_type_id;//TODO: а оно вообще надо тут?

	function __construct($scheme, $document_type_id = 0, $storage_path = '', $allowed_extensions = [], $max_file_size = 0)
	{
		parent::__construct($scheme.'.documents', 'id', [
			'document_type_id',
			'parent_id',
		]);
		$this->scheme = $scheme;
		$this->document_type_id = $document_type_id;

		$this->fields_model = new Document_fieldsModel($scheme, $document_type_id);
		$this->fields_model->__parent = $this;

		$this->values_dicts_model = new Document_values_dictsModel($scheme, $document_type_id);
		$this->values_dicts_model->__parent = $this;

		if ($storage_path == '')
		{
			$storage_path = HOME_DIR.'/'.$scheme;
		}
		$this->files_model = new Document_filesModel($scheme, $storage_path, $allowed_extensions, $max_file_size);
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
  field_group_id integer NOT NULL DEFAULT 0,-- тип поля с т.з. функционала, отображения и т.п. (hidden, например), либо группы полей с т.з. автоматизируемого процесса, если полей слишком много
  value_type character(1), -- тип значения
  measure character varying, -- ед.изм. значения. например длина в метрах, кредит-нота в долларах
  sort_order integer NOT NULL DEFAULT 0, -- для сортировки в списке полей
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

CREATE TRIGGER documents_fields_values_ins_trg
  BEFORE INSERT
  ON {$this->scheme}.documents_fields_values
  FOR EACH ROW
  EXECUTE PROCEDURE {$this->scheme}.documents_fields_values_ins_func();

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

		if (!isset($params['order']))
		{
			$params['order'] = 'd.id DESC';
		}
		else
		{// - $params['order'] ID поля для сортировки
			if ($params['order'] == 0)
			{
				$params['order'] = 'd.id DESC';
			}
			else
			{
				$params['fields_to_join'][] = $params['order']; // ожидается в $params['order'] ID поля для сортировки
				$field_info = $this->fields_model->getRow($params['order']);
				$field_name = $this->fields_model->value_field_names[$field_info['value_type']];
				//так будет работать всё, кроме словарных полей
				$params['order'] = "v{$params['order']}.{$field_name} DESC NULLS LAST, d.id DESC";
			}
		}

		//чтобы не было в выдаче рутового документа
		$params['where'][] = "d.id > 0";

		if (isset($params['parent_id']) && $params['parent_id'] > 0)
		{
			$params['where'][] = "parent_id = {$params['parent_id']}";
		}

		if (isset($params['filter']) && (count($params['filter']) > 0))
		{
			//структура: $params['filter']
			// ID-поля => значение для поиска
			foreach ($params['filter'] as $field_id => $value)
			{
				$value = trim($value);
				if ($value != '')
				{
					$params['where'][] = "v{$field_id}.value = '{$value}'";
					$params['fields_to_join'][] = $field_id;
				}
			}
		}
		//da($params);

		foreach (array_unique($params['fields_to_join']) as $field_id)
		{
			$params['from'] .= "\n\tLEFT OUTER JOIN {$this->scheme}.documents_fields_values AS v{$field_id} ON (v{$field_id}.document_id = d.id AND v{$field_id}.field_id = {$field_id})\n";
		}

		$params['select'] = isset($params['select']) ? $params['select'] : "d.*";

		$list = parent::getList($params);
		//$this->db->print_r();

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
	    $result['id'] = $field_id;
	    $field_info = $this->fields_model->getRow($field_id);

	    $field_name = $this->fields_model->value_field_names[$field_info['value_type']];

	    $result['value'] = $result[$field_name] = $value;

	    /* delete it
		if ($field_info['value_type'] == 'A')
		{
		    $result['value'] = $result['text_value'] = $value;
		}
		if ($field_info['value_type'] == 'I')
		{
		    $result['value'] = $result['int_value'] = $value;
		}
		if ($field_info['value_type'] == 'F')
		{
		    $result['value'] = $result['float_value'] = $value;
		}
		if ($field_info['value_type'] == 'K')
		{
		    $result['value'] = $result['int_value'] = $value;
		}
		if ($field_info['value_type'] == 'D')
		{
		    $result['value'] = $result['date_value'] = $value;
		}*/
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
		$delete_clause = "DELETE FROM {$this->scheme}.documents_fields_values WHERE document_id = $1 AND field_id = $2";
		//установка value -> null - удаляет поле
		if (!isset($value) || trim($value) == '')
		{
			$this->db->exec($delete_clause, $document_id, $field_id);
			return;
		}
		//далее value уже точно не null
		$value = trim($value);
		$result = "Field [{$field_info['title']}] with value [$value]: Unknown value_type [{$field_info['value_type']}]";//ошибка по-умолчанию
		$insert_clause = '';
		if ($field_info['value_type'] == 'A')
		{
			if ($value == '')
			{
				return "{$field_info['title']} - [$value]: Ожидается непустая строка";
			}
			$insert_clause = "INSERT INTO {$this->scheme}.documents_fields_values (document_id, field_id, text_value) VALUES ($1,$2,$3)";
			$result = '';
		}
		if ($field_info['value_type'] == 'I')
		{
			$value = preg_replace("/\D/", '', $value);
			if ($value == '')
			{
				return "{$field_info['title']} - [$value]: Ожидается целое число";
			}
			$insert_clause = "INSERT INTO {$this->scheme}.documents_fields_values (document_id, field_id, int_value) VALUES ($1,$2,$3)";
			$result = '';
		}
		if ($field_info['value_type'] == 'F')
		{
			$value = preg_replace("/\,/", '.', $value);
			$value = preg_replace("/\s+/", '', $value);
			if (!is_numeric($value + 0))
			{
				return "{$field_info['title']} - [$value]: Ожидается вещественное число";
			}
			$insert_clause = "INSERT INTO {$this->scheme}.documents_fields_values (document_id, field_id, float_value) VALUES ($1,$2,$3)";
			$result = '';
		}
		if ($field_info['value_type'] == 'K')
		{
			$value = preg_replace("/\D/", '', $value);
			if ($value == '')
			{
				return "{$field_info['title']} - [$value]: Ожидается индекс в словаре";
			}
			if (!isset($field_info['values'][$value]))
			{
				return "Значение $value не найдено в словаре для поля {$field_info['title']}";
			}
			$insert_clause = "INSERT INTO {$this->scheme}.documents_fields_values (document_id, field_id, int_value) VALUES ($1,$2,$3)";
			$result = '';
		}
		if ($field_info['value_type'] == 'D')
		{
			$value = trim($value);
			if (!preg_match("/(\d{1,2})[\.\-](\d{1,2})[\.\-](\d{4})/", $value, $matches))
			{
				return "{$field_info['title']} - [$value]: Ожидается формат даты ДД-ММ-ГГГГ";
			}
			if (!checkdate($matches[2],$matches[1],$matches[3]))
			{
				return "{$field_info['title']} - [$value]: Неверная дата. Формат даты ДД-ММ-ГГГГ";
			}
			$value = $matches[1].'.'.$matches[2].'.'.$matches[3];
			$insert_clause = "INSERT INTO {$this->scheme}.documents_fields_values (document_id, field_id, date_value) VALUES ($1,$2,$3)";
			$result = '';
		}

		if ($result == '')
		{
			$this->db->exec("BEGIN");
			$this->db->exec($delete_clause, $document_id ,$field_id);
			$this->db->exec($insert_clause, $document_id ,$field_id, $value);
			$this->db->exec("COMMIT");
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

	public function saveRow()
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
	];

	public $value_field_names = [
		'A'	=> 'text_value', // alphabet
		'I'	=> 'int_value', // integer
		'F'	=> 'float_value', // float
		'D'	=> 'date_value', // date
		'K'	=> 'int_value', // key values
	];

	function __construct($scheme, $document_type_id = 0)
	{
		parent::__construct($scheme.'.documents_fields', 'id', [
			'title', 'field_group_id', 'value_type', 'measure', 'sort_order',
		]);
		$this->scheme = $scheme;
		$this->document_type_id = $document_type_id;
	}

	public function getValues($key_value)
	{
		return $this->__parent->values_dicts_model->getList([
			'field_id'	=> $key_value,
		]);
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
				$this->data_cash[$key_value]['values'] = $this->getValues($key_value);
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

		if (isset($params['field_group_id']) && $params['field_group_id'] > 0)
		{
			$params['where'][] = "field_group_id = {$params['field_group_id']}";
		}

		$list = parent::getList($params);
		foreach ($list as $id => $r)
		{
			if ($r['value_type'] == 'K')
			{
				$list[$id]['values'] = $this->getValues($id);
			}
		}
		return $list;
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
				'field_group_id'	=> [
					'title'	=> 'Группа',
					'width' => 10,
					'type'	=> 'integer',
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
		{
			return "Потерян ID поля.";
		}
		if (!isset($data['sort_order']))
		{
			$data['sort_order'] = 0;
		}
		if (!isset($data['field_group_id']))
		{
			$data['field_group_id'] = 0;
		}
		if (trim($data['title']) == '')
		{
			return "Описание поля не может быть пустым";
		}
		$ar = $this->db->exec("UPDATE {$this->scheme}.documents_fields SET title = $2, measure = $3, sort_order = $4 WHERE id = $1",
			$data['id'], trim($data['title']), trim($data['measure']), $data['sort_order'])->affectedRows();
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