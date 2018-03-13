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
documents_fields - список полей для каждого типа документа
documents_fields_values - значения полей для документа
documents_values_dicts - словарь для словарных полей
documents_attachments - прицепленные файлы
?? (пока пусть будет массивом прямо в коде) attachment_types - типы прикрепляемых файлов

document_type_id - тип документа. по-умолчанию = 0, если надо в схеме реализовать несколько
разных документов и соеденить их в иерархию через parent_id, то вот тут то и надо использовать
разные document_type_id для разных типов в рамках одной таблицы.
Типы документов пока только хардкодить, т.к. по дефолту их может и не быть, т.е. будет всего один тип с id=0 и
ради него не стоит каждый раз городить таблицу вида document_types с одной строкой только для того,
чтобы сделать 2 констрэйнта с documents и documents_fields.
Целостность проверяем в коде модели, т.е. при модификации списка полей и модификации documents.document_type_id)
смотреть во внутреннюю таблицу типов документов на предмет соответствия.
Вариант отдать редактирование типов документов юзеру не рассматривается даже в перспективе.

Поля для документов сгруппированы по типу документа, т.е. в таблице documents_fields есть ссылка на тип документа.

Каждый документ имеет родителя по ссылке в parent_id в таблице documents.
Т.е. в рамках проекта можно констрэйнтами связать дерево документов в единое целое.
Для констрэйнта надо в пустой таблице с документами создать пустой документ с ID=0 (как бы root :) )

Каждый документ имеет текущего владельца, констрэйнтом завязанного на таблицу public.users.
owner_id==0 - anonymous вполне себе валидный владелец.

Статус документа (status_id) - целое число - интерпретация на совести класса-наследника, т.к. это уже бизнес логика
и в этом классе ее нет совсем.

Документы наследуются от SimpleDictionary, но их поведение более сложное, поэтому parent::saveRow() запрещен.
Для создания нового документа применять надо метод creatRow().
Модификация документа допустима через изменение атрибутов и поштучный вызов updateField().

Для инициализации проекта имеет смысл использовать вывод функции
__getDataStructureScript();
см. её код.
*/


class DocumentModel extends SimpleDictionaryModel
{
	protected $document_type_id;//TODO: а оно вообще надо тут?

	function __construct($scheme, $document_type_id = 0)
	{
		parent::__construct($scheme_name.'.documents', 'id', [
			'document_type_id',
			'status_id',
			'parent_id',
			'owner_id',
			'creation_ts',
		]);
		$this->scheme = $scheme;
		$this->document_type_id = $document_type_id;
	}

	public function __getDataStructureScript()
	{
		return "
DROP TABLE {$this->scheme}.documents_attachments;
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
      ON UPDATE CASCADE ON DELETE CASCADE,
)
WITH (
  OIDS=FALSE
);
ALTER TABLE {$this->scheme}.documents
  OWNER TO postgres;

CREATE TABLE {$this->scheme}.documents_fields
(
  id serial NOT NULL,
  document_type_id integer NOT NULL DEFAULT 0,
  title character varying, -- заголовок поля для форм и таблиц
  field_type integer NOT NULL DEFAULT 0,-- тип поля с т.з. функционала, отображения и т.п. (hidden, например)
  value_type character(1), -- тип значения
  measure character varying, -- ед.изм. значения. например длина в метрах, кредит-нота в долларах
  sort_order integer NOT NULL DEFAULT 0, -- для сортировки в списке полей
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

CREATE OR REPLACE FUNCTION {$this->scheme}.documents_fields_values_ins_func()
  RETURNS trigger AS
\$BODY\$
DECLARE
    field_info {$this->scheme}.fields%ROWTYPE;
BEGIN
	SELECT * INTO field_info FROM {$this->scheme}.documents_fields WHERE id = NEW.field_id;
	NEW.value = CASE WHEN field_info.value_type = 'K'
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


CREATE TABLE {$this->scheme}.documents_attachments
(
  id serial NOT NULL,
  document_id integer,
  file_name text,
  file_ext character varying,
  file_size integer,
  file_type integer, --тип файла - (например: отчет, скан запроса, скан кредитноты), ссылка на таблицу или массив в коде
  title text,--заголовок для списков
  CONSTRAINT documents_attachments_pkey PRIMARY KEY (id),
  CONSTRAINT documents_attachments_document_id_fkey FOREIGN KEY (document_id)
      REFERENCES {$this->scheme}.documents (id) MATCH SIMPLE
      ON UPDATE CASCADE ON DELETE CASCADE
)
WITH (
  OIDS=FALSE
);
ALTER TABLE {$this->scheme}.documents_attachments
  OWNER TO postgres;


CREATE TRIGGER log_history AFTER INSERT OR UPDATE OR DELETE ON {$this->scheme}.documents FOR EACH ROW EXECUTE PROCEDURE log_history();
CREATE TRIGGER log_history AFTER INSERT OR UPDATE OR DELETE ON {$this->scheme}.documents_fields FOR EACH ROW EXECUTE PROCEDURE log_history();
CREATE TRIGGER log_history AFTER INSERT OR UPDATE OR DELETE ON {$this->scheme}.documents_fields_values FOR EACH ROW EXECUTE PROCEDURE log_history();
CREATE TRIGGER log_history AFTER INSERT OR UPDATE OR DELETE ON {$this->scheme}.documents_values_dicts FOR EACH ROW EXECUTE PROCEDURE log_history();
CREATE TRIGGER log_history AFTER INSERT OR UPDATE OR DELETE ON {$this->scheme}.documents_attachments FOR EACH ROW EXECUTE PROCEDURE log_history();
";
	}

	public function getEmptyRow()
	{
		return [
			'id'			=> 0,
			'document_type_id'	=> $this->document_type_id,
			'parent_id'		=> 0,
			'owner_id'		=> 0,
			'creation_ts'	=> date('Y-m-d G:i:s'),
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

/**
 * without_fields = true - не грузить атрибуты
 */
	public function getList($params = [])
	{
		$params['from'] = "{$this->table_name} AS d";

		if (!isset($params['where']))
		{
			$params['where'] = [];
		}

		if (!isset($params['index']))
		{
			$params['index'] = 'd.id';
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
					$params['from'] .= "\n\tLEFT OUTER JOIN {$this->scheme}.documents_fields_values AS v{$field_id} ON (v{$field_id}.document_id = d.id AND v{$field_id}.field_id = {$field_id})";
				}
			}
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
//если value равно null - просто удаляем старое значение и выходим
//если поле неправильное, то выводим ошибку и выходим
//если все хорошо, то удаляем поле и вставляем его заново с новым значением
//триггер, обновляющий value в {DOCUMENTS}_fields_values работает только на вставку!
		if ($document_id == 0){die('DocumentModel.saveFieldsValue: $document_id == 0');}	// - absolutely
		if ($field_id == 0){die('DocumentModel.saveFieldsValue: $field_id == 0');}			// - barbaric!

		$field_info = $this->__parent->shipmentsfields->getRow($field_id);
		//$delete_clause = "DELETE FROM {$this->scheme}.documents_fields_values WHERE document_id = $document_id AND field_id = $field_id";
		$delete_clause = "DELETE FROM {$this->scheme}.documents_fields_values WHERE document_id = $1 AND field_id = $2";
		//установка value -> null - удаляет поле
		if (!isset($value) || trim($value) == '')
		{
			$this->db->exec($delete_clause, $document_id, $field_id);
			return;
		}
		//далее value уже точно не null
		$value = trim($value);
		$result = "{$field_info['title']} - [$value]: Unknown value_type [{$field_info['value_type']}]";//ошибка по-умолчанию
		$insert_clause = '';
		if ($field_info['value_type'] == 'A')
		{
			if ($value == '')
			{
				return "{$field_info['title']} - [$value]: Ожидается непустая строка";
			}
			//$insert_clause = "INSERT INTO {$this->scheme}.documents_fields_values (document_id, field_id, text_value) VALUES ($document_id , $field_id, '$value')";
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
			//$insert_clause = "INSERT INTO {$this->scheme}.documents_fields_values (document_id, field_id, int_value) VALUES ($document_id , $field_id, $value)";
			$insert_clause = "INSERT INTO {$this->scheme}.documents_fields_values (document_id, field_id, int_value) VALUES ($1,$2,$3)";
			$result = '';
		}
		if ($field_info['value_type'] == 'F')
		{
			$value = preg_replace("/\,/", '.', $value);
			if (!is_numeric($value + 0))
			{
				return "{$field_info['title']} - [$value]: Ожидается вещественное число";
			}
			//$insert_clause = "INSERT INTO {$this->scheme}.documents_fields_values (document_id, field_id, float_value) VALUES ($document_id , $field_id, $value)";
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
				return "Значение $value не найдено в словаре для этого поля";
			}
			//$insert_clause = "INSERT INTO {$this->scheme}.documents_fields_values (document_id, field_id, int_value) VALUES ($document_id , $field_id, $value)";
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
			//$insert_clause = "INSERT INTO {$this->scheme}.documents_fields_values (document_id, field_id, date_value) VALUES ($document_id , $field_id, '$value')";
			$insert_clause = "INSERT INTO {$this->scheme}.documents_fields_values (document_id, field_id, date_value) VALUES ($1,$2,$3)";
			$result = '';
		}

		if ($result == '')
		{
			$this->db->exec("BEGIN; $delete_clause; $insert_clause; COMMIT;", $document_id ,$field_id, $value);
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
		$this->document_id = $this->db->nextVal($this->getSeqName());
		$ar = $this->db->insert($this->table_name, $this->key_field, $this->fields, $data)->affectedRows();
		return ($ar == 1) ? '' : "Произошла ошибка при сохранении документа [#{$this->document_id}]: количество измененных записей = {$ar}";
	}
}