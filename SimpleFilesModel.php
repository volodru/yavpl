<?php
/*
 * @NAME: Class: Simple Files Model
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2018 - Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
*/

/** CHANGELOG
 *
 * 1.00
 * DATE: 2018-03-15
 * файл впервые опубликован
*/

/**
 * Простое хранилище файлов. Использовать как прототип для наследования.
 * В основе (и является предком) простой словарь SimpleDictionaryModel
 * с целочисленным первичным ключом.
 *
 * Замечание по наименованиям:
 * должно быть file_name, file_size и т.п. но не filename!
 *
 * Структура таблицы передается в конструкторе.
 * Поля file_name, file_ext, file_size обязаны присутствовать в таблице
 * и интерпретируются этим классом как свои.
 *
 */
class SimpleFilesModel extends SimpleDictionaryModel
{
	function __construct($table_name, $key_field, $fields, $storage_path,
		$allowed_extensions = [], $max_file_size = 0)
	{
		foreach (['file_name', 'file_ext', 'file_size'] as $f)
		{
			if (!in_array($f, $fields))
			{//debug stage
				die("SimpleFilesModel requires field $f in table $table_name");
			}
		}
		parent::__construct($table_name, $key_field, $fields);
		$this->storage_path = $storage_path;
		$this->allowed_extensions = $allowed_extensions;
		$this->max_file_size = $max_file_size;

		array_walk($this->allowed_extensions, function(&$value, $key){$value = strtolower($value);});
	}

/** $data может содержать доп поля для правильного формирования пути.
 * если оно надо и не было передано, то кому-то придется делать getRow($key_value).
 * по-умолчанию оно в общем-то и не надо.
 */
	public function getStoragePath($key_value, $data = false)
	{//перекрыть этот метод, если надо иметь путь к файлу исходя из каких-то данных в $data
		return $this->storage_path;
	}

	public function getFilePath($key_value, $data = false)
	{
		return $this->getStoragePath($key_value, $data).'/'.$key_value;
	}

	public function getFileSize($key_value)
	{
		return filesize($this->getFilePath($key_value));
	}

	public function beforeDeleteRow($key_value)
	{
		$f = $this->getFilePath($key_value);

		if (file_exists($f))
		{
			if (!unlink($f))
			{
				return "Невозможно удалить файл [$f]";
			}
		}
		else
		{//если файла нет, то и хрен с ним! задача этого метода сделать так, чтобы его больше не было.
			return '';
		}
	}

	public function getAllowedExtensions($data = [])
	{//наследники могут перекрыть это. например в MPFL
		return $this->allowed_extensions;
	}

/**
* Входной файл обязателен.
* Сохраняет новый файл или заменяет старый файл новым.
* Создает или обновляет запись в БД.
*
* Для обновления только полей нужно пользоваться saveRow().
*
* Возвращает true|false, а для подробностей смотреть $this->log,
* т.к. там может быть набор ошибок, которые надо исправлять оптом.
*/
	public function saveFile(&$data, $i_file)
	{
		$this->clearLog();

		$key_value = $data[$this->key_field];
		if ($key_value == 0)
		{
			$is_new_file = true;
			$data[$this->key_field] = $this->key_field_value = $key_value = $this->db->nextVal($this->getSeqName());
		}
		else
		{
			$is_new_file = false;
		}

		$action = ($is_new_file) ? 'insert' : 'update';
		$old_data = $this->getRow($key_value);

		$message = $this->beforeSaveRow($action, $data, $old_data);
		if (isset($message) && ($message != ''))
		{
			$this->log[] = $message;
			return false;
		}

		//da($data);

		$f = $this->getFilePath($key_value, $data);//file name in host OS

		$data['file_name'] = $i_file['name'];
		$matches = [];
		if (preg_match("/^(.+)\.(.+?)$/", $data['file_name'], $matches))
		{
			$data['file_ext'] = strtolower($matches[2]);
			if (!in_array($data['file_ext'], $this->getAllowedExtensions($data)))
			{
				$this->log[] = "Расширение файла {$data['file_ext']} не входит в список разрешенных: [".join(', ', $this->getAllowedExtensions($data))."]";
			}
		}
		else
		{
			$this->log[] = "Не удалось распознать расширение файла";
		}
		if (count($this->log) > 0)
		{
			return false;//stage check point
		}


		if ($i_file['error'] == UPLOAD_ERR_INI_SIZE)//1
		{
			$this->log[] = "Размер файла превышает параметр upload_max_filesize в php.ini. Обратитесь к администратору или используйте файл меньшего размера.";
		}
		elseif ($i_file['error'] == UPLOAD_ERR_FORM_SIZE)//2
		{
			$this->log[] = "Размер загружаемого файла превысил значение MAX_FILE_SIZE, указанное в HTML-форме. Обратитесь к администратору или используйте файл меньшего размера.";
		}
		elseif ($i_file['error'] == UPLOAD_ERR_PARTIAL)//3
		{
			$this->log[] = "Загружаемый файл был получен только частично. Обратитесь к администратору или загрузите файл еще раз.";
		}
		elseif ($i_file['error'] == UPLOAD_ERR_NO_FILE)//4
		{
			$this->log[] = "Ошибка: может быть Вы не ввели имя файла?";
		}
		elseif ($i_file['error'] == UPLOAD_ERR_NO_TMP_DIR)//6
		{
			$this->log[] = "Отсутствует временная папка. Обратитесь к администратору.";
		}
		elseif ($i_file['error'] == UPLOAD_ERR_CANT_WRITE)//7
		{
			$this->log[] = "Не удалось записать файл на диск. Обратитесь к администратору.";
		}
		elseif ($i_file['error'] == UPLOAD_ERR_EXTENSION)//8
		{
			$this->log[] = "PHP-расширение остановило загрузку файла. PHP не предоставляет способа определить, какое расширение остановило загрузку файла. Обратитесь к администратору.";
		}
		elseif ($i_file['error'] > 0)
		{
			$this->log[] = "Неопределенная ошибка номер [{$i_file['error']}]";
		}
		elseif (($this->max_file_size > 0) && ($i_file['size'] > $this->max_file_size))
		{
			$this->log[] = "Размер файла должен быть менее {$this->max_file_size} байт";
		}

		if (count($this->log) > 0)
		{
			return false;//stage check point
		}

		if (file_exists($f) && !unlink($f))
		{
			$this->log[] = "Невозможно удалить старый файл [$f]";
		}

		if (count($this->log) > 0)
		{
			return false;//stage check point
		}

		if (! move_uploaded_file($i_file['tmp_name'], $f))
		{
			$this->log[] = "Невозможно создать файл ({$i_file['tmp_name']} -> {$f})!";
		}
		elseif (!file_exists($f))
		{
			$this->log[] = "И все равно файл не создался! [{$f}])!";
		}
		elseif (filesize($f) == 0)
		{
			$this->log[] = "И все равно файл нулевой длины! [{$f}])!";
		}

		if (count($this->log) > 0)
		{
			return false;//stage check point
		}

		$data['file_size'] = filesize($f);

		$this->affected_rows = ($is_new_file) ?
			$this->db->insert($this->table_name, $this->key_field, $this->fields, $data)->affectedRows()
			:
			$this->db->update($this->table_name, $this->key_field, $this->fields, $data)->affectedRows();

		//delete it: return ($this->affected_rows == 1);

		if ($this->affected_rows == 1)
		{
			$message = $this->afterSaveRow($action, $data, $old_data);
			if (isset($message) && ($message != ''))
			{
				$this->log[] = $message;
				return false;
			}
			else
			{
				return true;//все хорошо
			}
		}
		else
		{
			return false;
		}
	}

/** Сохраняет все переданные файлы с общими параметрами в $data
 * Возвращает true если успешно загружены ВСЕ файлы.
 * иначе грузит только часть из них, подробности смотреть в логе.
 * этот же лог можно показать юзеру - пусть сам разбирается.
 *
 * в контроллере:
 * если true - можно сделать редирект на список сущностей.
 * если нет - показать страницу с 2 ссылками:
 * вернуться к редактированию и вернуться к списку сущностей и туда же вывести лог загрузки.
 *
<form method='post' action='/сохранить' enctype='multipart/form-data'>
<h2>Выберите несколько изображений из вашего компьютера и нажмите Загрузить</h2>
<input name='upload[]' type='file' multiple='multiple' />
<input type='submit' name='ok' value='Загрузить' />

 */
	public function saveFiles(&$data, $i_files)
	{
		//каждая сохранялка отдельного файла ($this->saveFile(&$data, $i_file)) чистит лог. поэтому тут набираем общий лог и его уже отдаем
		$this->log = $this->log ?? [];
		$overall_log = [];
		$i = -1;
		$result = true;
		//da($i_files['name']);
		if (count($i_files['name']) == 0)
		{
			return $result;//все хорошо, если файлов вообще не выбрали
		}
		$data['ids'] = [];
		foreach ($i_files['name'] as $file_name)
		{
			$i++;
			if ($file_name == '') continue;
			#$overall_log[] = "Обрабатываем файл: $file_name";
			$file_info = [];
			foreach (['name', 'type', 'error', 'size', 'tmp_name'] as $f)
			{
				$file_info[$f] = $i_files[$f][$i];
			}
			$data['id'] = 0;
			if ($this->saveFile($data, $file_info))
			{
				$data['files_list'][$data['id']] = $data;
				$overall_log[] = "Файл {$file_name} успешно загружен.";
			}
			else
			{
				$result = false;
				$overall_log[] = "При загрузке файла {$file_name} произошла ошибка:";
				$overall_log = array_merge($overall_log, $this->log);
			}
		}

		//da($overall_log);
		$this->log = $overall_log;
		return $result;
	}
}