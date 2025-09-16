<?php
declare(strict_types=1);

namespace YAVPL;
/**
 * @NAME: Controller
 * @DESC: Main Controller
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2009- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** CHANGELOG
 * 1.14
 * DATE: 2024-02-07
 * Можно потребовать массив из getParam() если к имени переменной дописать []
 * Например, getParam('person_ids[]', 'integer') - всегда будет возвращать массив.
 * Актуально для чекбоксов, например, из getCheckBoxGroup() с неединичным массивом значений.
 */

/**
 * Контроллеры.
 *
 * Рекомендации по кодированию.
 *
 * Метод контроллера. Примерный порядок действий.
 * Это необязательно, но если есть возможность соблюдать - пусть будет порядок.
 * 1. Получаем информацию об уровне доступа и проверяем доступность метода.
 * Если что не так - простейший путь $this->message = 'Error...'; return;
 * 2. Получаем все CGI параметры и проверяем их валидность.
 * 3. Инициируем модели, получаем данные из моделей.
 * Все данные, которые могут понадобится представлению, сохраняем как поля контроллера.
 * 4. При необходимости делаем $this->redirect();
 */

#[\AllowDynamicProperties]
class Controller
{
	//!!!
	//все поля, которые может видеть представление с помощью магии, должны быть публичными!
	//

/** Текущий залогиненный юзер. рекомендуется синглтон, как правило - наследник или экземпляр UserModel.
 */
	public $user = null;

/** Модуль класса контроллера
 */
	private string $__module_name;

/** Имя класса контроллера
 */
	private string $__class_name;

/** Вызванный метод контроллера
 */
	public string $running_method_name;

/** Включать ли рендер всей страницы (meta/head/body) или это AJAX в виде простого потока HTML
 */
	public bool $__need_render = true;

/** Если это просто JSON вызов (используется в классе Application - отключаем рендер и отдаем результата в виде json_encode)
 */
	public bool $__is_json = false;

/** см. метод getResourceId()
 */
	protected string $default_resource_id;

/** Заголовок страниц проекта по умолчанию.
 */
	private string $__title = 'THIS IS THE TITLE OF THE PROJECT';

/** Массив с параметрами скрипта (берем из GET/POST/ARGV/COOKIES/Globals)
 */
	private array $__params_array = [];

/** Обеспечение Хелперов - список подключенных методов. Кто подключился последним - тот и работает.
 */
	private array $__methods = [];//for Helper

	//https://developer.mozilla.org/en-US/docs/Web/HTTP/Basics_of_HTTP/MIME_types/Common_types
	public $mime_types_per_extension = [
		'.7z'	=> 'application/x-7z-compressed',
		'.csv'	=> 'text/csv',
		'.doc'	=> 'application/msword',
		'.docx'	=> 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'.docm'	=> 'application/vnd.ms-word.document.macroEnabled.12',
		'.dot'	=> 'application/msword',
		'.dotx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
		'.dotm'	=> 'application/vnd.ms-word.template.macroEnabled.12',
		'.jpg'	=> 'image/jpeg',
		'.jpeg'	=> 'image/jpeg',
		'.mdb'	=> 'application/vnd.ms-access',
		'.mp3'	=> 'audio/mpeg',
		'.mp4'	=> 'video/mp4',
		'.oga'	=> 'audio/ogg',
		'.ogg'	=> 'audio/ogg',
		'.pdf'	=> 'application/pdf',
		'.png'	=> 'image/png',
		'.pot'	=> 'application/vnd.ms-powerpoint',
		'.potm'	=> 'application/vnd.ms-powerpoint.template.macroEnabled.12',
		'.potx'	=> 'application/vnd.openxmlformats-officedocument.presentationml.template',
		'.ppa'	=> 'application/vnd.ms-powerpoint',
		'.pps'	=> 'application/vnd.ms-powerpoint',
		'.ppsx'	=> 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
		'.ppt'	=> 'application/vnd.ms-powerpoint',
		'.pptm'	=> 'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
		'.pptx'	=> 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'.ppam'	=> 'application/vnd.ms-powerpoint.addin.macroEnabled.12',
		'.ppsm'	=> 'application/vnd.ms-powerpoint.slideshow.macroEnabled.12',
		'.rtf'	=> 'application/rtf',
		'.xla'	=> 'application/vnd.ms-excel',
		'.xlam'	=> 'application/vnd.ms-excel.addin.macroEnabled.12',
		'.xls'	=> 'application/vnd.ms-excel',
		'.xlsb'	=> 'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
		'.xlsm'	=> 'application/vnd.ms-excel.sheet.macroEnabled.12',
		'.xlsx'	=> 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'.xlt'	=> 'application/vnd.ms-excel',
		'.xltm'	=> 'application/vnd.ms-excel.template.macroEnabled.12',
		'.xltx'	=> 'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
		'.webm'	=> 'video/webm',
		'.webp'	=> 'image/webp',
	];

/** Самый главный контструктор всея контроллеров.
 *
 */
	public function __construct()
	{
		global $application;
//оно именно тут, т.к. это надо для работы метода error(), который можно вызвать из конструктора класса контроллера наследника
//причем не столько для метода error, сколько для View, который вызывается из error() в режиме показа ошибки через рендер проекта
		$application->controller = $this;
	}

/** Что надо сделать ПОСЛЕ конструктора, имея на руках $this->running_method_name
 * Например, глобальный ACL, базирующийся на типовых названиях методов (save/delete)
 */
	public function init(): void
	{
	}

/** Что надо сделать перед деструктором, когда контроллер еще жив и здоров.
 * А в API наследниках тут (в их done() методе) можно отдать накопленные данные в формате JSON.
 */
	public function done(): void
	{
	}

/**
 * В деструкторе по-умолчанию ничего не делаем.
 */
	public function __destruct()
	{
		//da(__METHOD__);
	}

/** Выводит сообщение о фатальной ошибке и завершает работу скрипта.
 * Внешний вид вывода зависит от контекста - рендер на сайте, ajax или json
 */
	//public function error(string $message, int $http_response_code = 200): void
	public function error(string $message, string $status = 'ERROR', int $http_response_code = 200): void
	{
		if ($http_response_code != 200)
		{
			http_response_code($http_response_code);
		}
		$html_message = preg_replace("/\n/", "<br>\n", $message);
		if ($this->__need_render)
		{// пытаемся впихнуть сообщение об ошибке внутрь стандартной страницы проекта
			global $application;
			if ($application->loadView())
			{
				$this->message = $html_message;
				$application->view->render('');//отдаем несуществующий метод (''), тогда вызывается "дефолтный" метод, который просто выводит $this->message
			}
		}
		elseif ($this->__is_json)
		{//json
			print json_encode(['message' => strip_tags($message), 'status' => $status], JSON_UNESCAPED_UNICODE);
		}
		else
		{//ajax
//@TODO - нехорошая практика ссылка на глобальный класс CSS. надо что-нибудь придумать красивое.
			print "<div class='global_ajax_error_block_class'>{$html_message}</div>";
		}
		die();
	}

/**
 * Вызывается после вызова конструктора контроллера в Application
 */
	public function setModuleName(string $module_name): void
	{
		$this->__module_name = $module_name;
	}

/**
 * Мало ли понадобится, например, для определения текущего каталога исходника
 */
	public function getModuleName(): string
	{
		return $this->__module_name;
	}

/**
 * Вызывается после вызова конструктора контроллера в Application
 */
	public function setClassName(string $class_name): void
	{
		$this->__class_name = $class_name;
	}

/**
 *  см. getModuleName()
 */
	public function getClassName(): string
	{
		return $this->__class_name;
	}

/**
 * Вызывается после вызова конструктора контроллера в Application
 *
 * Бывает нужен в программе. Модуль и класс в программе редко нужен, т.к. это епархия Application.
 * Если контроллер манипулирует названиями методов, то это может пригодиться.
 */
	public function setMethodName(string $method_name): void
	{
		$this->running_method_name = $method_name;
	}

/**
 * Надо иногда получить название метода именно с т.з. фреймворка.
 */
 	public function getMethodName(): string
	{
		return $this->running_method_name;
	}

/**
 * Метод по-умолчанию.
 * По-умолчанию вообще ничего не делаем,
 * Это нужно, чтобы для статических страниц не писать липовых контроллеров, т.к. Application хочет иметь контроллер - ему так проще.
 * Если хочется чуть упростить отладку, можно наследовать метод в главном контроллере проекта:
	public function defaultMethod($method_name)
	{
		die("Declare method [$method_name] or defaultMetod() in descendant controller");
	}

	Можно перекрыть этот метод в контроллере и организовать собственный маршрутизатор в пределах класса.
 * */
	/*
	public function defaultMethod(string $method_name):void
	{
	}*/

/**
 * Устанавливается после вызова конструктора контроллера в Application.
 * Для проектов с ACL.
 */
	public function setDefaultResourceId(string $resource_id): void
	{
		$this->default_resource_id = $resource_id;
	}

/**
 * Для проектов с ACL.
 * По-умолчанию тут имя класса (__controller_fq_class_name).
 * Если надо иметь ACL, сюда пишем вменяемое имя роли для всего класса контроллера (или дефолтного контроллера раздела)
 */
	protected function getResourceId()
	{
		return $this->default_resource_id;
	}

/**
 * TODO
 * $this->__need_render - нужен только в WEBUI режиме.
 * но пока есть вывод бинарников через View метод __sendStreamToClient должен дизаблить рендерер
 * а сам метод __sendStreamToClient используется в API
 * что надо сделать:
 * 1. убрать из всех view вывод бинарников
 * 2. в методе __sendStreamToClient  убрать disableRender() т.к. он тут не нужен
 * 3. переменную $this->__need_render  и disableRender() перенести в контроллер webui
 *
 * Выключает рендеринг через главное представление.
 * Нужно для аджакса, бинарников и некоторых специальных случаев, типа печатных версий или
 * версий для мобильных устройств.
 *
 * Принцип выделения минимального - 99% страниц отдаются в виде именно страницы с шапкой, хлебными крошками и подвалом.
 */

	public function disableRender(): Controller
	{
		$this->__need_render = false;
		return $this;
	}

/** Начать отдавать поток данных клиенту.
 * @param $filename string - имя файла с расширением 2-4 символа
 * @param $params array - доп параметры:
 * content_type - тип контента, по умолчанию application/octet-stream
 * content_disposition - attachment - сохранить как файл, inline - открыть сразу в браузере. по умолчанию attachment
 */
	protected function __sendStreamToClient(string $file_name, array $params = []): void
	{
		$content_type = $params['content_type'] ?? 'application/octet-stream';

		// **content_disposition: attachment - сохранить как файл, inline - открыть сразу в браузере
		$content_disposition = $params['content_disposition'] ?? 'attachment';
		if ($content_type == 'application/octet-stream')//абстрактный бинарник
		{//пытаемся по расширению файла угадать наиболее правильный mime-type
			$matches = [];
			if (preg_match("/(\.\w{2,4})$/i", $file_name, $matches))
			{
				$file_extension = strtolower($matches[1]);
				if (isset($this->mime_types_per_extension[$file_extension]))
				{
					$content_type = $this->mime_types_per_extension[$file_extension];
				}
			}
		}

		header("HTTP/1.0 200 Ok");//всегда.
		header("Pragma: private");////по материалам http://www.jspwiki.org/wiki/BugSSLAndIENoCacheBug
		header("Cache-Control: private, must-revalidate");//специально для эксплорера!
		header("Content-type: {$content_type}");
		header("Content-Transfer-Encoding: binary");
		header("Content-Disposition: {$content_disposition}; filename*=UTF-8''".rawurlencode($file_name));

		$this->disableRender();
	}

/** Отдать файл клиенту
 * @param $file_name string имя файла для клиента
 * @param $file_path string путь к файлу в файловой системе
 * @param $params array см. параметры для __sendStreamToClient
 * После отдачи файла скрипт сразу завершается.
 */
	protected function __sendFileToClient(string $file_name, string $file_path, array $params = []): void
	{
		header("Content-Length: ".filesize($file_path));
		$this->__sendStreamToClient($file_name, $params);
		readfile($file_path);
		exit();
	}

/**
 * Для аджаксных вызовов ожидающих строго JSON формат.
 * При этом Application вызовет метод view->default_JSON_Method().
 *
 * По-умолчанию, конечный контроллер заполняет массив $this->result, который просто отдается браузеру через json_encode.
 * Ну и Content-type тут выставляем, чтобы было красиво.
 */
	public function isJSON(): Controller
	{
		header("Content-type: application/json");
		$this->disableRender();
		$this->__is_json = true;
		return $this;
	}

/** Добавить параметр в набор входных параметров CGI.
 * эти значения берутся самыми первыми и перекрывают остальные (cookies, get, post)
 *
 * не подходит для передачи параметров из Application в Controller,
 * т.к. параметры нужны уже в конструкторе Контроллера, а там только $_GET etc. и глобальные переменные.
 */
	public function setParam(string $name, $value): Controller
	{
		$this->__params_array[$name] = $value;
		return $this;
	}

/** Приватный метод для getParam. Валидирует значения в общем случае.
 * Кому тут тесно - берите строку и валидируйте ее самостоятельно. */
	private function checkParamType(string $type, mixed $value, mixed $default_value): mixed
	{
		//TODO - проверить как оно будет работать с массивами
		if (is_array($value))
		{
			$result = [];
			foreach ($value as $v)
			{
				$result[] = $this->checkParamType($type, $v, $default_value);
			}
			return $result;
		}
		if (in_array($type, ['integer', 'int', 'bigint', 'int64', 'float', 'double']))
		{//все числа, особенно из экселя, могут содержать форматирующие пробелы/переносы/неразрывные пробелы
			$value = preg_replace("/[\s\xC2\xA0]/", '', strval($value));
		}
		if (in_array($type, ['integer', 'int', 'bigint', 'int64']))
		{
			$value = preg_match("/^\s*(\+|\-)?\d+\s*$/", strval($value)) ? $value : $default_value;
			if (in_array($type, ['integer', 'int']))
			{
				if (abs(intval($value)) > 2147483647)//целое для Постгреса! в PHP 64 бита
				{
					die("{$value} - слишком большое значение для целого типа длиной 32 бита.
Если Вы попали сюда по внутренней ссылке - сообщите об этом администратору проекта.
Если Вы самостоятельно набрали этот URL, то больше так не делайте.");
				}
			}
			if (in_array($type, ['bigint', 'int64']))
			{
				if (abs(intval($value)) > 9223372036854775806)//целое 64 бит (bigint, bigserial) для Постгреса
				{
					die("{$value} - слишком большое значение для целого типа длиной 64 бита.
Если Вы попали сюда по внутренней ссылке - сообщите об этом администратору проекта.
Если Вы самостоятельно набрали этот URL, то больше так не делайте.");
				}
			}
			//return intval($value);
			return (is_numeric($value)) ? intval($value) : $default_value;
		}
		elseif (in_array($type,['float', 'double']))
		{//а плавающая точка где-то может быть запятой. тут захардкоден американский формат чисел!
			$value = preg_replace("/\,/", '.', $value);
			$value = preg_replace("/[^\-\d\.]/", '', $value);
			//$value = filter_var($value, FILTER_VALIDATE_FLOAT);
			return (is_numeric($value)) ? $value : $default_value;
		}
		elseif ($type == 'string')
		{
			//da($value);da(mb_detect_encoding($value));
			if (mb_detect_encoding($value ?? '', 'UTF-8', true) === false)
			{
				die('Passed string argument is not valid');
			}
			return strval($value);
		}
		else
		{//значит накосячили при вызове getParam
			die("Неизвестный тип данных [{$type}]");
		}
	}

/** Приватный метод для getParam. */
	private function createDefaultValue(string $type, mixed $default_value): mixed
	{
		//da('default_value: ['.$default_value.']');da(isset($default_value));da(is_null($default_value));
		if (!isset($default_value))
		{//если не передали дефолтное значение, то берем его исходя из типа
			if ($type == 'string')
			{
				$default_value = '';
			}
			elseif (in_array($type, ['integer', 'int', 'bigint', 'int64', 'float', 'double']))
			{
				$default_value = 0;
			}
			else
			{//значит накосячили при вызове getParam
				die("Неизвестный тип данных [{$type}] на этапе проверки значения по-умолчанию");
			}
		}
		return $default_value;
	}

/** Особо одаренные наследники могут перекрыть метод и выдавать данные из своих источников с любым приоритетом.
(!)	Сессию тут не используем - все желающие хранить дефолтные значения в сессии перекрывают этот метод.
	$this->__params_array[$name],//что поставили ручками + автотесты
	$GLOBALS[$name], //для передачи данных из глобального контекста, например реализаци ЧПУ в Application
*/
	protected function getValueFromEverywhere(string $name, mixed $default_value = null)
	{//см. setParam() - $this->__params_array
		return $this->__params_array[$name] ?? $_GET[$name] ?? $_POST[$name] ?? $_COOKIE[$name] ?? $GLOBALS[$name] ?? $default_value;
	}

/** Получить параметр извне.
Концепция: Параметр - это параметр. Код не может действовать по-разному в засимости от источника данных.

Приоритет параметров:
1. установленные через setParam
2. GET
3. POST
4. Cookies
5. Globals //since 2014-11-28 - status experimental

Концепция проверки ввода:
- Если передали чушь, то это эквивалентно, что не передали ничего, т.е. отдаем дефолтное значение.
- Тутошние die() - видит только разработчик, который накосячил с вызовом getParam().

Если имя оканчивается на конструкцию [] (например, person_id[] - имя переменной будет person_id),
то будет возвращен массив значений. Использовать для блоков чекбоксов.

experimental: Если тип начинается со знака вопроса И дефолтное значение не передано, то возвращаемое значение может быть null.


 */
	protected function getParam(string $name, string $type, mixed $default_value = null, array $valid_values = []): mixed
	{
		//da("START----------- ($type)  $name");		da('default_value: ['.$default_value.']');da(isset($default_value));da(is_null($default_value));
		/* experimental*/
		$is_nullable = false;
		if (substr($type, 0, 1) == '?')
		{
			$type = substr($type, 1, strlen($type) - 1);
			$is_nullable = is_null($default_value);
		}

		//da('default_value before');da($default_value);
		if (!$is_nullable)
		{
			$default_value = $this->createDefaultValue($type, $default_value);
		}
		//da("----------- ($type)  $name");		da($is_nullable);		da($default_value);

		//da("$name, string $type, mixed $default_value");

		$array_expected = false;
		if ((strpos($name, '[]') > 0) && (strpos($name, '[]') == (strlen($name) - 2)))
		{
			$name = substr($name, 0, strlen($name) - 2);
			$array_expected = true;
		}

		//проверяем дефолтное значение в любом случае, а не только, если до него дошла очередь
		if (!$is_nullable)
		{
			if (is_array($valid_values) && (count($valid_values) > 0) && !in_array($default_value, $valid_values))
			{//значит накосячили при вызове getParam
				die("Значение по-умолчанию параметра {$name} ({$type}) равное [{$default_value}] не входит в список разрешенных значений.");
			}
		}

		//получили данные из окружения - GET|POST|globals|etc
		$value = $this->getValueFromEverywhere($name, $default_value);

		if ($array_expected && !is_array($value))
		{
			if (isset($default_value) && $value == $default_value)
			{
				$value = [];
			}
			else
			{
				$value = [$value];
			}
		}

		if (is_array($value))
		{
			$result = [];
			foreach ($value as $k => $v)
			{
				$result[$k] = $this->checkParamType($type, $v, $default_value);
			}
		}
		else
		{ //проверяем допустимые значения только для скаляров, т.к. для массивов это обычно checkbox-ы, а там трудно накосячить.
			$result = $this->checkParamType($type, $value, $default_value);
			// если значение неправильное и есть массив правильных значений
			if (is_array($valid_values) && (count($valid_values) > 0) && !(in_array($result, $valid_values)))
			{ // тогда возвращаем дефолтное значение
				$result = $default_value;
			}
		}
		return $result;
	}

/**
 * магия по-умолчанию. на нее ссылается View.
 * тут же можно посылать уведомления о неинициализированных переменных.
 */
	public function __get(string $name)
	{
		global $application;
		$this->$name = $application->getBasicModel($name);
		if (isset($this->$name))
		{
			return $this->$name;
		}
//!это всегда ошибка. у контроллера не должно быть неинициализированных переменных. это 99% опечатка в коде и надо проверять.
		sendBugReport(get_class($this).": variable [{$name}] is undefined", $name);
		return null;
	}

/**
 * Обеспечение работы помощников.
 * После регистрации помощника, все его методы доступны в представлении как свои собственные.
 * Используется магия __call()
 *
 * По сути, это имитация trait-ов другими средствами языка.
 */
	public function __call(string $method_name, array $args)
	{
		if (isset($this->__methods[$method_name]))
		{
			$helper = $this->__methods[$method_name];
			return call_user_func_array([$helper, $method_name], $args);
		}
		else
		{
			sendBugReport("__call(".get_class($this)."->{$method_name})", 'call undefined CONTROLLER method');
			return null;
		}
	}

/** По сути, это имитация trait-ов другими средствами языка.
 */
	public function registerHelper(string $helper_class_name): Controller
	{
		$this->__methods = array_merge($this->__methods, Helper::registerHelper($helper_class_name, $this));
		return $this;
	}
}