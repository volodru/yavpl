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

/* CHANGELOG
 * 1.13
 * DATE: 2020-05-22
 * Добавлена возможность работы через CLI, контроллеры должны располагаться в каталоге cli
 * Параметры берутся из $_SERVER['argv'] - в формате через знак =
 *
 * 1.12
 * DATE: 2020-04-12
 * isJSON() для вызовов ожидающих JSON. Для таких вызовов при отсутсвии представления выдается
 * json по умолчанию
 * 1. если есть переменная $this->result в виде json_encode($this->result);
 * 2. иначе: если есть переменная $this->message в виде ['message' => $this->message]
 * 2.1 если еще и логи есть, то ['message' => $this->message, 'log' => $this->log]
 * 3. если ничего нет, то это считается вполне фатальной ошибкой
 * т.е. после декларации $this->isJSON() надо сделать что-то из списка:
 * 1. сделать нормальное представление
 * 2. заполнить $this->result
 * 3. заполнить $this->message чем-то отличным от null или пустой строки
 *
 * 1.11
 * DATE: 2020-03-21
 * добавлен RegisterHelper, теперь помощники могут быть и у представления и у модели и у контроллеров.
 *
 * 1.10
 * DATE: 2019-11-16
 * Метод setTitle без параметров генерирует дефолтный заголовок из хлебных крошек.
 * Оно надо, когда надо отделить страницы одну от другой, а заголовки везде прописывать не хочется.
 *
 * 1.09
 * DATE: 2019-07-17
 * Убрано поле Controller->title,
 * Поле Controller->__title - доступно через геттер/сеттер и только так (getTitle()/setTitle($title)).
 *
 * 1.08
 * DATE: 2018-12-10
 * добавлена магия __get($name) по умолчанию. на нее ссылается магия View - если не находит прямо в контроллере, она ищет переменную в магии самого контроллера.
 *
 * 1.07
 * DATE: 2017-09-30
 * добавлены функции isEventStream и sendEventStreamMessage для поддержки Server Side Events (https://learn.javascript.ru/server-sent-events)
 *
 * 1.06
 * DATE: 2015-10-30
 * кодировка установлена в UTF8
 *
 * 1.05
 * DATE: 2015-09-29
 * Выведена работа с тулбаром в класс ToolBal (в т.ч. и из View)
 * Убран метод validate()
 * Рефакторинг текста.
 *
 * 1.04
 * DATE: 2014-11-28
 * В порядке эксперимента в getParam() берутся значения из массива $GLOBALS
 * Приоритет самый низкий.
 * Нужно для использования в ЧПУ - т.к. ЧПУ делается на уровне Application,
 * а хочется данные передать конструктору контроллера, а кроме как через глобальные переменные - этого не сделать никак.
 *
 * 1.03
 * DATE: 2014-11-17
 * в private function checkParamType($type, $value, $default_value)
 * добавлено приведение разделителя целой и дробной частей к одному виду
 * $value = preg_replace("/\,/", '.', $value);
 *
 * 1.02
 * DATE: 2013-12-30
 * Добавлены getModuleName(), getClassName() и getMethodName() - для общности (хотя $running_method_name не отменен)
 *
 * 1.02
 * DATE: 2013-06-18
 * изменена логика для CGI параметров:
 * в function checkParamType($type, $value) добавлено значение по-умолчанию.
 * теперь для целых и вещественных чисел если число неформатное, то выдается default_value, а не 0
 * практически, теперь можно со строкового поля ввода отличить 0 от пустой строки и сделать
 * во втором случае, например, NULL, если оно надо.
 *
 * 1.01
 * DATE: 2013-06-14
 * добавлен заголовок с версией, описанием и проч. к этому файлу
 * в function checkParamType($type, $value)
 * добавлена зачистка значения от пробельных символов для числовых типов (integer, float, double)
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

/** Хлебные крошки. Т.к. оно реализовано в виде 1 массива и двух методов, то делать отдельный класс для этого - нунафиг.
Тулбар - штука посложнее, поэтому он в отдельном классе. см. ToolBar.php
 */
	public $__breadcrumbs_delimiter = " &raquo;&raquo;\n\t";
	public $__breadcrumbs = [];

/** Текущий залогиненный юзер. рекомендуется синглтон, как правило - наследник или экземпляр UserModel
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

/** Если это просто JSON вызов (отключаем рендер и отдаем результата в виде json_encode)
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

/** TS документа на случай управления кешированием страниц
 */
	private int $__document_ts = 0;

/** Обеспечение Хелперов - список подключенных методов. Кто подключился последним - тот и работает.
 */
	private array $__methods = [];//for Helper

/** Самый главный контструктор всея контроллеров.
 *
 * Для UI|API режимов тут ничего не делаем.
 *
 * Для консольного режима разбираем командную строку на параметры вида key=value
 * Всё, что не в этом формате - игнорим.
 * Параметры заполняем в глобальный массив через setParam.
 */
	public function __construct()
	{
		/* Заполняем параметры для CLI режима, только в формате key=value */
		//@TODO сделать вменяемый разбор строковых значений, т.к. вот щас нельзя в значениях иметь знак =
		if (APPLICATION_RUNNING_MODE == 'cli')
		{
			foreach ($_SERVER['argv'] ?? [] as $param)
			{
				$a = explode("=", $param);
				if (count($a) == 2)
				{
					$this->setParam($a[0], $a[1]);
				}
			}
		}
	}

/** Что надо сделать ПОСЛЕ конструктора, имея на руках $this->running_method_name
 * Например, глобальный ACL базирующийся на типовых названиях методов (save/delete)
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
	public function defaultMethod(string $method_name)
	{
	}

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
 * По-умолчанию тут имя класса.
 * Если надо иметь ACL сюда пишем вменяемое имя роли для всего класса контроллера (или дефолтного контроллера раздела)
 */
	protected function getResourceId()
	{
		return $this->default_resource_id;
	}

/**
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

/**
 * Перед отдачей бинарника вызвать этот метод. Экономит 1 строчку :)
 */
	public function isBINARY(string $content_type = ''): Controller
	{
		if ($content_type != '')
		{
			header("Content-Type: {$content_type}");
		}
		$this->disableRender();
		return $this;
	}

/**
 * Для аджаксных вызовов, если результат в виде HTML
 */
	public function isAJAX(): Controller
	{//просто отдаём HTML без обёрток из шапки и подвала сайта
		$this->disableRender();
		return $this;
	}

/**
 * Для аджаксных вызовов ожидающих строго JSON формат
 * при этом Application вызовет метод view->default_JSON_Method()
 *
 *
 * по-умолчанию, конечный контроллер заполняет массив $this->result, который просто отдается браузеру через json_encode
 * ну и Content-type тут выставляем, чтобы было красиво.
 */
	public function isJSON(): Controller
	{
		header("Content-type: application/json");
		$this->disableRender();
		$this->__is_json = true;
		return $this;
	}

/**
 * Для вызовов text/event-stream
 */
	public function isEventStream(): Controller
	{
		header('Content-Type: text/event-stream');
		// recommended to prevent caching of event data.
		header('Cache-Control: no-cache');
		//header('Transfer-Encoding: identity');
		header('X-Accel-Buffering: no');

		$this->disableRender();
		return $this;
	}

/**
 * Для вызовов text/event-stream
 */
	public function sendEventStreamMessage($id, $data)
	{
		print "id: {$id}" . PHP_EOL . "data: " . json_encode($data) . PHP_EOL . PHP_EOL;
		ob_flush();
		flush();
	}

/**
 * Больше для нужд тестирования. Хотя где-то может и пригодится.
 */
	protected function resetParams(): Controller
	{
		$this->__params_array = [];
		return $this;
	}

/**
 * Для простых контроллеров без представления - выполнил работу и перешел на другую страницу.
 */
	protected function redirect(string $url = '/', bool $exit = true): void
	{
		header("Location: {$url}");
		if ($exit)
		{
			exit(0);
		}
	}

/**
 *
Получить приватное поле с крошками
 */
	public function getBreadcrumbs(): array
	{
		return $this->__breadcrumbs;
	}

/**
 * добавить хлебную крошку
 * $title заголовок
 * $link гиперссылка (лучше локальная, без протокола)
 */
	protected function addBreadcrumb(string $title, string $link = ''): Controller
	{
		$this->__breadcrumbs[] = ($link != '') ? "<a href='{$link}'>{$title}</a>" : $title;
		return $this;
	}

/**
 * добавить параметр в набор входных параметров CGI.
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

/**
 * Приватный метод для getParam. Валидирует значения в общем случае.
 * Кому тут тесно - берите строку и валидируйте ее самостоятельно.
 */
	private function checkParamType(string $type, $value, $default_value)
	{
		//TODO - надо что-то сделать с массивами
		if (is_array($value))
		{
			return $value;
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
					die("{$value} - слишком большое значение для целого типа 32 длиной бит.
Если Вы попали сюда по внутренней ссылке - сообщите об этом администратору проекта.
Если Вы самостоятельно набрали этот URL, то больше так не делайте.");
				}
			}
			if (in_array($type, ['bigint', 'int64']))
			{
				if (abs(intval($value)) > 9223372036854775806)//целое 64 бит (bigint, bigserial) для Постгреса
				{
					die("{$value} - слишком большое значение для целого типа 64 длиной бит.
Если Вы попали сюда по внутренней ссылке - сообщите об этом администратору проекта.
Если Вы самостоятельно набрали этот URL, то больше так не делайте.");
				}
			}
			return intval($value);
		}
		//elseif ($type == 'float' || $type == 'double')
		elseif (in_array($type,['float', 'double']))
		{//а плавающая точка где-то может быть запятой. тут захардкоден американский формат чисел!
			$value = preg_replace("/\,/", '.', $value);
			$value = preg_replace("/[^\-\d\.]/", '', $value);

			return (is_numeric($value)) ? $value : $default_value;
		}
		elseif ($type == 'string')
		{
			return strval($value);
			/* 2015-09-29
			 * возможно сможем прожить без stripslashes,
			 * т.к. иногда надо принимать от юзера практически программный код.
			 */
			//return stripslashes(strval($value));
		}
		else
		{//значит накосячили при вызове getParam
			die("Неизвестный тип данных [{$type}]");
		}
	}

/**
 * Приватный метод для getParam.
 */
	private function verifyDefaultValue(string $type, $default_value)
	{
		if ($default_value === false)
		{//если не передали дефолтное значение, то берем его исходя из типа
			if ($type == 'string')
			{
				$default_value = '';
			}
			//elseif ($type == 'integer' || $type == 'float' || $type == 'double')
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

/** Получить параметр извне.

Параметр - это параметр. Код не может действовать различно в засимости от источника данных.

Приоритет параметров:
1. установленные через setParam
2. GET
3. POST
4. Cookies
5. Globals //since 2014-11-28 - status experimental

Концепция проверки ввода:
- Если передали чушь, то это эквивалентно, что не передали ничего, т.е. отдаем дефолтное значение.
- Тутошние die() - видит только разработчик, который накосячил с вызовом getParam().

Особо одаренные наследники могут перекрыть/переписать метод getParam и выдавать данные с любым приоритетом.
Пока (2015-09-29) это еще никому не понадобилось.
 */
	protected function getParam(string $name, string $type, $default_value = false, $valid_values = false)
	{
		$default_value = $this->verifyDefaultValue($type, $default_value);

		//проверяем дефолтное значение в любом случае, а не только, если до него дошла очередь
		if (is_array($valid_values) && !in_array($default_value, $valid_values))
		{//значит накосячили при вызове getParam
			die("Значение по-умолчанию [{$default_value}] не входит в список разрешенных значений.");
		}

/*
	$this->__params_array[$name],//что поставили ручками + автотесты
	$GLOBALS[$name], //для передачи данных из глобального контекста, например реализаци ЧПУ в Application
*/

/** @TODO: use coalesce in php7
 * ждем coalesce в php7 и уберу этот ужас нах.
 */
		/*
		$value = (isset($this->__params_array[$name])) ? $this->__params_array[$name] : (
			(isset($_GET[$name])) ? $_GET[$name] : (
				(isset($_POST[$name])) ? $_POST[$name] : (
					(isset($_COOKIE[$name])) ? $_COOKIE[$name] : (
						(isset($GLOBALS[$name])) ? $GLOBALS[$name] : $default_value
					)
				)
			)
		);
		*/
		$value = $this->__params_array[$name] ?? $_GET[$name] ?? $_POST[$name] ?? $_COOKIE[$name] ?? $GLOBALS[$name] ?? $default_value;

		if (is_array($value))
		{
			$result = [];
			foreach ($value as $k => $v)
			{
				$result[$k] = $this->checkParamType($type, $v, $default_value);
			}
		}
		else
		{ // проверяем допустимые значения только для скаляров
			$result = $this->checkParamType($type, $value, $default_value);
			// если значение неправильное и есть массив правильных значений
			if (is_array($valid_values) && !(in_array($result, $valid_values)))
			{ // тогда возвращаем дефолтное значение
				$result = $default_value;
			}
		}
		return $result;
	}

/**
 * Устанавливает TS документа для нужд поисковых систем.
 */
	public function setDocumentTS(int $ts): void //FOR POSTGRESQL: EXTRACT(epoch FROM ts)::integer as epoch_ts
	{//берем только самое новое значение. получаем во времени документа - время самой молодой его части.
		if ($ts > $this->__document_ts)
		{
			$this->__document_ts = $ts;
		}
	}

/**  Устанавливает title Документа.
 */
	public function setTitle(string $title = ''): Controller
	{
		if ($title != '')
		{//либо рисуем, что передали
			$this->__title = $title;
		}
		else
		{//или рисуем весь путь из хлебных крошек через разделитель
			$a = [];
			foreach ($this->__breadcrumbs as $s)
			{
				$s = trim(strip_tags($s));
				if ($s != '')
				{
					$a[] = $s;
				}
			}
			$this->__title = join(' : ', array_reverse($a));
		}
		return $this;
	}

/**  Возвращает title Документа.
 * "public Морозов", по сути.
 */
	public function getTitle(): string
	{
		return $this->__title;
	}

/**
 * магия по-умолчанию. на нее ссылается View.
 * тут же можно посылать уведомления о неициализированных переменных.
 */
	public function __get(string $name)
	{
		global $application;
		$this->$name = $application->getBasicModel($name);
		if (isset($this->$name))
		{
			return $this->$name;
		}
		//!это всегда ошибка. у контроллера не должно быть необъявленных переменных.
		sendBugReport("CONTROLLER _get(): variable [{$name}] is undefined", $name);
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

	public function registerHelper(string $helper_class_name): Controller
	{
		$this->__methods = array_merge($this->__methods, Helper::registerHelper($helper_class_name, $this));
		return $this;
	}
}