<?php
namespace YAVPL;

/**
 * @NAME: Class: Simple Files Model
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2018 - Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
*/

/** CHANGELOG
 *
 * 1.01
 * DATE: 2022-05-04
 * в метод SaveFile добавлена работа с локальными файлами.
 *
 * 1.00
 * DATE: 2018-03-15
 * файл впервые опубликован
*/

/**
 * Простое хранилище файлов. Использовать как прототип для наследования.
 * В основе (и является предком) класс-таблица DbTable
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
class SimpleFilesModel extends DbTable
{
/** По-умолчанию запрещенные расширения.
 * Используется как шаблон для getDeniedExtensions.
 * Для своих нужд нужно либо использовать getAllowedExtensions, либо таки перекрывать getDeniedExtensions.
 * Если getAllowedExtensions отдает непустой массив, то getDeniedExtensions не используется.
 */
	public array $denied_extensions = [
		'bat', 'bin', 'cmd', 'com', 'cpl', 'exe', 'gadget', 'inf1', 'ins', 'inx', 'isu', 'job', 'jse',
		'lnk', 'msc', 'msi', 'msp', 'mst', 'paf', 'pif', 'ps1', 'reg', 'rgs', 'scr', 'sct',
		'shb', 'shs', 'u3p', 'vb', 'vbe', 'vbs', 'vbscript', 'ws', 'wsf', 'wsh',
	];

/** Если больше нуля, то перед сохранением будет дополнительная проверка на размер
 */
	public int $max_file_size = 0;

	public function __construct(string $table_name, string $key_field, array $fields, string $storage_path)
	{
		foreach (['file_name', 'file_ext', 'file_size'] as $f)
		{
			if (!in_array($f, $fields))
			{//debug stage - косяк на этапе разработки
				die("SimpleFilesModel requires field {$f} in table {$table_name}");
			}
		}
		parent::__construct($table_name, $key_field, $fields);
		$this->storage_path = $storage_path;// путь (HOME_DIR.'/catalog/complains') к папке с файлами или подпапками с файлам
	}

/** $data может содержать доп поля для правильного формирования пути.
 * если оно надо и не было передано, то кому-то придется делать getRow($key_value).
 * по-умолчанию оно в общем-то и не надо.
 */
	public function getStoragePath(int $key_value, array $data = []): string
	{//перекрыть этот метод, если надо иметь путь к файлу исходя из каких-то данных в $data - когда путь формируется со всякими суффиксами-префиксами
		return $this->storage_path;
	}

/** путь к файлу - место где они хранятся + '/' + ID файла.
 * если надо сложное хранилище - перекрыть и делать там что угодно.
 *
 */
	public function getFilePath(int $key_value, array $data = []): string
	{
		return $this->getStoragePath($key_value, $data).'/'.$key_value;
	}

/** просто шорткат, в общем-то
 */
	public function getFileSize(int $key_value): int
	{
		return filesize($this->getFilePath($key_value));
	}

/** когда удаляем файл с инфой из базы, то СНАЧАЛА удаляем его с диска.
 * из базы оно точно удалится, а вот накосячив с правами к папкам, можно файл и не удалить
 */
	public function beforeDeleteRow(int $key_value): string
	{
		$f = $this->getFilePath($key_value);

		if (file_exists($f))
		{
			if (!unlink($f))
			{
				return "Невозможно удалить файл [{$f}]";
			}
		}
		return '';
	}

/** для сложных наследников, у которых список расширений хранится в СУБД - перекрыть и отдавать из базы
 * если явно указан список разрешенных расширений, то игнорим список запрещений.
 * если список разрешений пуст, то проверяем список запрещенных расширений.
 */
	public function getAllowedExtensions(array $data = []): array
	{//наследники могут перекрыть это. например в MPFL
		return [];
	}

/** для сложных наследников, у которых список расширений хранится в СУБД - перекрыть и отдавать из базы
 */
	public function getDeniedExtensions(array $data = [])
	{//наследники могут перекрыть это. например в MPFL
		return $this->denied_extensions;
	}

/**
* Входной файл обязателен.
* Сохраняет новый файл или заменяет старый файл новым.
* Создает или обновляет запись в БД.
*
* Возможна загрузка локального файла (в обход CGI) путем указания параметра $i_file['src_file_path']
* Исходный файл размещать в /tmp/! т.к. файл будет ПЕРЕМЕЩЕН!
*
* Для обновления только полей нужно пользоваться saveRow().
*
* Возвращает true|false, а для подробностей смотреть $this->log,
* т.к. там может быть набор ошибок, которые надо исправлять оптом.
*/
	public function saveFile(array &$data, array $i_file): string
	{
		//$this->log = [];//очищаем лог загрузки именно этого файла (важно для нескольких последовательных вызовов)

		$i_file['error'] ??= 0;//для загрузки через файловую систему

		$this->key_value = $data[$this->key_field] ?? 0;

		$old_data = $this->getRow($this->key_value);

		if ($this->key_value == 0)
		{
			$is_new_file = true;
			$data[$this->key_field] = //для сохранения предком
				$this->key_value = //шорткат для остального кода
					$this->db->nextVal($this->getSeqName());
		}
		else
		{
			$is_new_file = false;
		}

		$action = ($is_new_file) ? 'insert' : 'update';

		$message = $this->beforeSaveRow($action, $data, $old_data);
		if ($message != '')
		{
			//$this->log[] = $message;
			return $message;
		}

		//da($data);

		$f = $this->getFilePath($this->key_value, $data);//file name in host OS

		$data['file_name'] = $i_file['name'];
		$matches = [];
		if (preg_match("/^(.+)\.(.+?)$/", $data['file_name'], $matches))
		{
			$data['file_ext'] = strtolower($matches[2]);

			$allowed_extensions = $this->getAllowedExtensions($data);//если там запрос к СУБД - не вызываем его 2 раза


			if (count($allowed_extensions) == 0)
			{//если явно ничего не разрешено, то проверяем на запрещенные и разрешаем всё остальное
				$denied_extensions = $this->getDeniedExtensions($data);
				if (count($denied_extensions) > 0 && in_array($data['file_ext'], $denied_extensions))
				{
					return "Расширение файла {$data['file_ext']} входит в список запрещенных: [".join(', ', $denied_extensions)."]";
				}
			}
			else
			{//если есть список разрешений - используем только его
				if (!in_array($data['file_ext'], $allowed_extensions))
				{
					return "Расширение файла {$data['file_ext']} не входит в список разрешенных: [".join(', ', $allowed_extensions)."]";
				}
			}
		}
		else
		{
			return "Не удалось распознать расширение файла";
		}

		if ($i_file['error'] == UPLOAD_ERR_INI_SIZE)//1
		{//хотя оно просто валится и надо читать логи апача
			return "Размер файла превышает параметр upload_max_filesize в php.ini. Обратитесь к администратору или используйте файл меньшего размера.";
		}
		elseif ($i_file['error'] == UPLOAD_ERR_FORM_SIZE)//2
		{
			return "Размер загружаемого файла превысил значение MAX_FILE_SIZE, указанное в HTML-форме. Обратитесь к администратору или используйте файл меньшего размера.";
		}
		elseif ($i_file['error'] == UPLOAD_ERR_PARTIAL)//3
		{
			return "Загружаемый файл был получен только частично. Обратитесь к администратору или загрузите файл еще раз.";
		}
		elseif ($i_file['error'] == UPLOAD_ERR_NO_FILE)//4
		{
			return "Ошибка: может быть Вы не ввели имя файла?";
		}
		elseif ($i_file['error'] == UPLOAD_ERR_NO_TMP_DIR)//6
		{
			return "Отсутствует временная папка. Обратитесь к администратору.";
		}
		elseif ($i_file['error'] == UPLOAD_ERR_CANT_WRITE)//7
		{
			return "Не удалось записать файл на диск. Обратитесь к администратору.";
		}
		elseif ($i_file['error'] == UPLOAD_ERR_EXTENSION)//8
		{
			return "PHP-расширение остановило загрузку файла. PHP не предоставляет способа определить, какое расширение остановило загрузку файла. Обратитесь к администратору.";
		}
		elseif ($i_file['error'] > 0)
		{
			return "Неопределенная ошибка номер [{$i_file['error']}]";
		}
		elseif (($this->max_file_size > 0) && ($i_file['size'] > $this->max_file_size))
		{//наша локальная проверка - max_file_size должно быть меньше, чем в INI файле.
			return "Размер файла должен быть менее {$this->max_file_size} байт";
		}

		if (file_exists($f) && !unlink($f))
		{
			return "Невозможно удалить старый файл [{$f}]";
		}

		//локальный файл ВСЕГДА передавать через /tmp/ - ему делается MOVE
		if (isset($i_file['src_file_path']))
		{//local source
			if (!file_exists($i_file['src_file_path']))
			{
				return "Исходный файл [{$i_file['src_file_path']}] не найден.";
			}
			else
			{
				if (!rename($i_file['src_file_path'], $f))
				{
					return "Не удалось переименовать файл [{$i_file['src_file_path']}] в [{$f}].";
				}
				//else  - все хорошо
			}
		}
		else
		{//CGI
			if (!move_uploaded_file($i_file['tmp_name'], $f))
			{
				return "Невозможно создать файл ({$i_file['tmp_name']} -> {$f})!";
			}
			//else  - все хорошо
		}
		if (!file_exists($f))
		{
			return "Целевой файл не создался! [{$f}])!";
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
				return $message;
			}
			else
			{
				return '';//все хорошо
			}
		}
		else
		{
			return "Ошибка при сохранении в базу - вместо одной обновлено записей ".$this->affected_rows;
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
	public function saveFiles(array &$data, array $i_files): bool
	{
		$this->log = [];

		//da($i_files['name']);
		if (count($i_files['name']) == 0)
		{
			return true;//все хорошо, если файлов вообще не выбрали
		}
		$data['ids'] = [];
		$i = -1;
		$result = true;
		foreach ($i_files['name'] as $file_name)
		{
			$i++;
			if ($file_name == '') {continue;}
			$file_info = [];
			foreach (['name', 'type', 'error', 'size', 'tmp_name'] as $f)
			{
				$file_info[$f] = $i_files[$f][$i];
			}
			$data['id'] = 0;
			$msg = $this->saveFile($data, $file_info);//она дополняет массив $data
			if ($msg == '')
			{
				$data['files_list'][$data['id']] = $data;
				$this->log[] = "Файл {$file_name} успешно загружен.";
			}
			else
			{
				$this->log[] = "При загрузке файла {$file_name} произошла ошибка:";
				$this->log[] = $msg;
				$result = false;
			}
		}
		return $result;
	}
}