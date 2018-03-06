<?php
/*
 * @NAME: Class: Document Model
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2018 - Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
*/

/** CHANGELOG
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

Таблицы с документами, полями, значениями полей, прицепленными файлами, словарями полей и т.п.
имеют фиксированные названия в пределах схемы проекта:
documents - документы
fields - список полей для каждого типа документа
fields_values - значения полей для документа
values_dicts - словарь для словарных полей
attachments - прицепленные файлы

Каждый документ имеет родителя по ссылке в parent_id в таблице documents.
Т.е. в рамках проекта можно констрэйнтами связать дерево документов в единое целое.
Для констрэйнта надо создать пустой документ с ID=0

Документы наследуются от SimpleDictionary, но их поведение более сложное, поэтому parent::saveRow() запрещен.
Для создания нового документа применять надо метод creatRow().
Модификация документа допустима через изменение атрибутов и поштучный вызов updateField().

Для инициализации проекта имеет смысл использовать вывод функции
__getDataStructureScript();
см. её код.
*/


class DocumentModel extends SimpleDictionaryModel
{
	public $document_type;

	function __construct($scheme, $document_type)
	{
		parent::__construct($scheme_name.'.documents', 'id', [
			'document_type',
			'owner_id',
			'creation_ts',
			'ts',
		]);
		$this->scheme = $scheme;
		$this->document_type = $document_type;
	}

	public function __getDataStructureScript()
	{
		return "
DROP TABLE {$this->scheme}.documents_values_dicts;
DROP TABLE {$this->scheme}.documents_fields_values;
DROP TABLE {$this->scheme}.documents_fields;
DROP TABLE {$this->scheme}.documents;

CREATE TABLE {$this->scheme}.documents
(
  id serial NOT NULL,
  document_type character varying NOT NULL,
  status_id integer NOT NULL DEFAULT 0,
  parent_id integer NOT NULL DEFAULT 0,
  owner_id integer NOT NULL DEFAULT 0,
  creation_ts timestamp without timezone DEFAULT now(),
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
  title character varying,
  field_type character(1),
  measure character varying,
  sort_order integer NOT NULL DEFAULT 0,
  CONSTRAINT documents_fields_pkey PRIMARY KEY (id)
)
WITH (
  OIDS=FALSE
);
ALTER TABLE {$this->scheme}.fields
  OWNER TO postgres;

CREATE TABLE {$this->scheme}.documents_fields_values
(
  document_id integer NOT NULL,
  field_id integer NOT NULL,
  int_value integer,
  float_value double precision,
  date_value date,
  char_value character varying,
  value character varying,
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
  value character varying,
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
    field_info {$this->scheme}.fields%ROWTYPE;
BEGIN
	SELECT * INTO field_info FROM {$this->scheme}.documents_fields WHERE id = NEW.field_id;
	NEW.value = CASE WHEN field_info.field_type = 'K'
	THEN
		(SELECT value FROM {$this->scheme}.documents_values_dicts AS d WHERE d.field_id = NEW.field_id AND d.id = NEW.int_value)
	ELSE
		COALESCE(NEW.int_value::text, NEW.float_value::text, NEW.char_value, to_char(NEW.date_value, 'DD-MM-YYYY'))
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

CREATE TRIGGER log_history AFTER INSERT OR UPDATE OR DELETE ON {$this->scheme}.documents FOR EACH ROW EXECUTE PROCEDURE log_history();
CREATE TRIGGER log_history AFTER INSERT OR UPDATE OR DELETE ON {$this->scheme}.documents_fields FOR EACH ROW EXECUTE PROCEDURE log_history();
CREATE TRIGGER log_history AFTER INSERT OR UPDATE OR DELETE ON {$this->scheme}.documents_fields_values FOR EACH ROW EXECUTE PROCEDURE log_history();
CREATE TRIGGER log_history AFTER INSERT OR UPDATE OR DELETE ON {$this->scheme}.documents_values_dicts FOR EACH ROW EXECUTE PROCEDURE log_history();
";
	}

	public function getEmptyRow()
	{
		return [
			'id'			=> 0,
			'document_type'	=> $this->document_type,
			'owner_id'		=> 0,
			'creation_ts'	=> date('Y-m-d G:i:s'),
			'ts'			=> date('Y-m-d G:i:s'),
		];
	}

	public function getFieldsList()
	{
		return $this->db->exec("
SELECT f.*, f.id AS field_id
FROM {$this->scheme}.documents_fields AS f
ORDER BY f.sort_order")->fetchAll('field_id');
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

	public function getList($params = [])
	{
		$params['from'] = "{$this->table_name} AS d";

		if (!isset($params['where']))
		{
			$params['where'] = [];
		}

		if (!isset($params['index']))
		{
			$params['index'] = 'id';
		}

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
				$params['from'] .= "\n\tLEFT OUTER JOIN {$this->scheme}.documents_fields_values AS v ON (v.document_id = d.id AND v.field_id = {$params['order']})";
				$params['order'] = 'v.value DESC NULLS LAST, d.id DESC';
			}
		}
		if (isset($params['owner_id']) && $params['owner_id'] > 0)
		{
			$params['where'][] = "owner_id = {$params['owner_id']}";
		}
		$params['select'] = isset($params['select']) ? $params['select'] : "d.*";

		$list = parent::getList($params);
		foreach ($list as $row)
		{
			if (!isset($params['without_fields']))
			{
				$list[$row['id']]['fields'] = $this->getFieldsValues($row['id']);
			}
		}
		return $list;
	}

	public function saveFieldValue($document_id = 0, $field_id = 0, $value = null)
	{
//если value равно null - просто удаляем и выходим
//если поле неправильное, то выводим ошибку и выходим
//если все хорошо, то удаляем поле и вставляем его заново с новым значением
//триггер, обновляющий value в shipments_fields_values работает только на вставку!
		if ($document_id == 0){die('DocumentModel.saveFieldsValue: $document_id == 0');}	// - absolutely
		if ($field_id == 0){die('DocumentModel.saveFieldsValue: $field_id == 0');}		// - barbaric!

		$field_info = $this->__parent->shipmentsfields->getRow($field_id);
		$delete_clause = "DELETE FROM {$this->scheme}.documents_fields_values WHERE document_id = $document_id AND field_id = $field_id";
		//установка value -> null - удаляет поле
		if (!isset($value) || trim($value) == '' || $value == 0)
		{
			$this->db->exec($delete_clause);
			return;
		}
		//далее value уже точно не null
		$value = trim($value);
		$result = "{$field_info['title']} - [$value]: Unknown field_type [{$field_info['field_type']}]";//ошибка по-умолчанию
		$insert_clause = '';
		if ($field_info['field_type'] == 'A')
		{
			if ($value == '')
			{
				return "{$field_info['title']} - [$value]: Ожидается непустая строка";
			}
			$insert_clause = "INSERT INTO {$this->scheme}.documents_fields_values (document_id, field_id, char_value) VALUES ($document_id , $field_id, '$value')";
			$result = '';
		}
		if ($field_info['field_type'] == 'I')
		{
			$value = preg_replace("/\D/", '', $value);
			if ($value == '')
			{
				return "{$field_info['title']} - [$value]: Ожидается целое число";
			}
			$insert_clause = "INSERT INTO {$this->scheme}.documents_fields_values (document_id, field_id, int_value) VALUES ($document_id , $field_id, $value)";
			$result = '';
		}
		if ($field_info['field_type'] == 'F')
		{
			$value = preg_replace("/\,/", '.', $value);
			if (!is_numeric($value + 0))
			{
				return "{$field_info['title']} - [$value]: Ожидается вещественное число";
			}
			$insert_clause = "INSERT INTO {$this->scheme}.documents_fields_values (document_id, field_id, float_value) VALUES ($document_id , $field_id, $value)";
			$result = '';
		}
		if ($field_info['field_type'] == 'K')
		{
			$value = preg_replace("/\D/", '', $value);
			if ($value == '')
			{
				return "{$field_info['title']} - [$value]: Ожидается индекс в словаре";
			}
			if (!isset($field_info['values'][$value]))
			{
				return "Значение $value не найдено в словаре для этого поля";
			}
			$insert_clause = "INSERT INTO {$this->scheme}.documents_fields_values (document_id, field_id, int_value) VALUES ($document_id , $field_id, $value)";
			$result = '';
		}
		if ($field_info['field_type'] == 'D')
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
			$insert_clause = "INSERT INTO {$this->scheme}.documents_fields_values (document_id, field_id, date_value) VALUES ($document_id , $field_id, '$value')";
			$result = '';
		}

		if ($result == '')
		{
			$this->db->exec("BEGIN; $delete_clause; $insert_clause; COMMIT;");
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

	public function saveRow()
	{
		die("DocumentModel::saveRow() is disabled, Use DocumentModel::createRow() and DocumentModel::updateField() instead!");
	}

	public function createRow($data)
	{
		if (!isset($this->document_type))
		{
			return "Не задан тип документа";
		}
		$data['owner_id'] = $data['owner_id'] ?? 0;
		$data['status_id'] = $data['status_id'] ?? 0;
		$data['parent_id'] = $data['parent_id'] ?? 0;
		$data['creation_ts'] = $data['creation_ts'] ?? date('Y-m-d G:i:s');
		$data['document_type'] = $this->document_type;
		$this->document_id = $this->db->nextVal($this->getSeqName());
		$ar = $this->db->insert($this->table_name, $this->key_field, $this->fields, $data)->affectedRows();
		return ($ar == 1) ? '' : "Произошла ошибка при сохранении документа [#{$this->document_id}]: количество измененных записей = {$ar}";
	}
}