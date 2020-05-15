<?php
/**
 * @NAME: Controller
 * @DESC: Main Controller
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2009- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/* CHANGELOG
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

class Controller
{
	//!!!
	//все поля, которые может видеть представление с помощью магии, должны быть публичными!
	//
	public $breadcrumbs_delimiter = " &raquo;&raquo;\n\t";

	public $user = null;//текущий залогиненный юзер. рекомендуется синглтон, как правило - наследник или экземпляр UserModel

	public $running_method_name;
	public $__need_render = true;
	public $__is_json = false;
	public $__breadcrumbs = [];
	//public $__toolbar_elements = [];

	protected $default_resource_id;

	private $__title = 'THIS IS THE TITLE OF THE PROJECT';

	private $__params_array = [];
	private $__document_ts = 0;

	private $__module_name;
	private $__class_name;

	private $__methods = [];//for Helper

/**
 * ничего не делаем
 */
	function __construct()
	{
	}

/**
 * ничего не делаем
 */
	function __destruct()
	{
	}

/**
 * Устанавливается после вызова конструктора контроллера в Application
 */
	public function setDefaultResourceId($resource_id)
	{
		$this->default_resource_id = $resource_id;
	}

/**
 * Вызывается после вызова конструктора контроллера в Application
 */
	public function setModuleName($module_name)
	{
		$this->__module_name = $module_name;
	}

/**
 * Мало ли понадобится, например, для определения текущего каталога исходника
 */
	public function getModuleName()
	{
		return $this->__module_name;
	}

/**
 * Вызывается после вызова конструктора контроллера в Application
 */
	public function setClassName($class_name)
	{
		$this->__class_name = $class_name;
	}

/**
 *  см. getModuleName()
 */
	public function getClassName()
	{
		return $this->__class_name;
	}

/**
 * Вызывается после вызова конструктора контроллера в Application
 */
	public function setMethodName($method_name)
	{//он бывает нужен в программе. модуль и класс в программе редко нужен, т.к. это епархия Application
		$this->running_method_name = $method_name;
	}

/**
 * Надо иногда
 */
 	public function getMethodName()
	{
		return $this->running_method_name;
	}

/**
 * По-умолчанию вообще ничего не делаем,
 * чтобы для статических страниц не писать липовых контроллеров.
 * Если хочется чуть упростить отладку, можно наследовать метод:
public function defaultMethod($method_name)
{
		die("Declare method [$method_name] or defaultMetod() in descendant controller");
}
 * */
	public function defaultMethod($method_name)
	{
	}

/**
 * Для проектов с ACL
 */
	protected function getResourceId()
	{
		return $this->default_resource_id;
	}

/**
 * Выключает рендеринг через главное представление.
 * Нужно для аджакса, бинарников и некоторых специальных случаев, типа печатных версий или
 * версий для мобильных устройств.
 */
	public function disableRender()
	{
		$this->__need_render = false;
		return $this;
	}

/**
 * Перед отдачей бинарника вызвать этот метод. Экономит 1 строчку :)
 */
	public function isBINARY($content_type = '')
	{
		if ($content_type != '')
		{
			header("Content-Type: $content_type");
		}
		$this->disableRender();
		return $this;
	}

/**
 * Для аджаксных вызовов
 */
	public function isAJAX()
	{
		$this->disableRender();
		return $this;
	}

/**
 * Для аджаксных вызовов ожидающих строго JSON формат
 */
	public function isJSON()
	{
		$this->disableRender();
		$this->__is_json = true;
		return $this;
	}

/**
 * Для вызовов text/event-stream
 */
	public function isEventStream()
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
		print "id: $id" . PHP_EOL . "data: " . json_encode($data) . PHP_EOL . PHP_EOL;
		ob_flush();
		flush();
	}

/**
 * Больше для нужд тестирования. Хотя где-то может и пригодится.
 */
	protected function resetParams()
	{
		$this->__params_array = [];
		return $this;
	}

/**
 * Для простых контроллеров	без представления - выполнил работу и перешел на другую страницу.
 */
	protected function redirect($url = '/', $exit = true)
	{
		header("Location: {$url}");
		if ($exit)
		{
			exit(0);
		}
	}

//Хлебные крошки. Т.к. оно реализовано в виде 1 массива и двух методов, то делать отдельный класс для этого - нунафиг.
//Тулбар - штука посложнее, поэтому он в отдельном классе. см. ToolBar.php

/**
 * получить приватное поле с крошками
 */
	public function getBreadcrumbs()
	{
		return $this->__breadcrumbs;
	}

/**
 * добавить хлебную крошку
 * $title заголовок
 * $link гиперссылка (лучше локальная, без протокола)
 */
	protected function addBreadcrumb($title, $link = '')
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
	public function setParam($name, $value)
	{
		$this->__params_array[$name] = $value;
		return $this;
	}

/**
 * Приватный метод для getParam.
 */
	private function checkParamType($type, $value, $default_value)
	{
		if (in_array($type, ['integer', 'float', 'double']))
		{//все числа, особенно из экселя, могут содержать форматирующие пробелы
			$value = preg_replace("/[\s\xA0]/", '', $value);
		}
		if ($type == 'integer')
		{
			return preg_match("/^\s*(\+|\-)?\d+\s*$/", $value) ? $value : $default_value;
		}
		elseif ($type == 'float' || $type == 'double')
		{//а плавающая точка где-то может быть запятой.
			$value = preg_replace("/\,/", '.', $value);
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
		{
			die("Unrecognized type cast [$type]");
		}
	}

/**
 * Приватный метод для getParam.
 */
	private function verifyDefaultValue($type, $default_value)
	{
    	if ($default_value === false)
		{//если не передали дефолтное значение, то берем его исходя из типа
			if ($type == 'string')
			{
				$default_value = '';
			}
			elseif ($type == 'integer' || $type == 'float' || $type == 'double')
			{
				$default_value = 0;
			}
			else
			{
				die("Unrecognized type cast \"$type\"");
			}
		}
		return $default_value;
	}

/** Получить параметр извне.
приоритет параметров:
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
	protected function getParam($name, $type, $default_value = false, $valid_values = false)
	{
	    $default_value = $this->verifyDefaultValue($type, $default_value);

		//проверяем дефолтное значение в любом случае, а не только, если до него дошла очередь
		if (is_array($valid_values) && !in_array($default_value, $valid_values))
		{
			die("Default value [$default_value] is invalid");
		}

/** @TODO: use coalesce in php7
 * ждем coalesce в php7 и уберу этот ужас нах.
 */
/*
		$this->__params_array[$name],//что поставили ручками + автотесты
		$GLOBALS[$name], //для передачи данных из глобального контекста, например реализаци ЧПУ в Application
*/
		$value = (isset($this->__params_array[$name])) ? $this->__params_array[$name] : (
			(isset($_GET[$name])) ? $_GET[$name] : (
				(isset($_POST[$name])) ? $_POST[$name] : (
					(isset($_COOKIE[$name])) ? $_COOKIE[$name] : (
						(isset($GLOBALS[$name])) ? $GLOBALS[$name] : $default_value
					)
				)
			)
		);

		if (is_array($value))
		{
			//reset($value);
			$result = [];
			//while (list ($k, $v) = each($value))
			foreach ($value as $k => $v)
			{
				$result[$k] = $this->checkParamType($type, $v, $default_value);
			}
		}
		else
		{ // проверяем допустимые значения только для скаляров
			$result = $this->checkParamType($type, $value, $default_value);
			// если значение неправильное и есть массив правильных значений
			if (is_array($valid_values) && ! (in_array($result, $valid_values)))
			{ // тогда возвращаем дефолтное значение
				$result = $default_value;
			}
		}
		return $result;
	}

/**
 * Устанавливает TS документа для нужд поисковых систем.
 */
	public function setDocumentTS($ts) //FOR POSTGRESQL: EXTRACT(epoch FROM ts)::integer as epoch_ts
	{//берем только самое новое значение. получаем во времени документа - время самой молодой его части.
		if ($ts > $this->__document_ts)
		{
			$this->__document_ts = $ts;
		}
	}

/**  Устанавливает title Документа.
 */
	public function setTitle($title = '')
	{
		if ($title != '')
		{
			$this->__title = $title;
		}
		else
		{
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
	public function getTitle()
	{
		return $this->__title;
	}

/**
 * магия по-умолчанию. на нее ссылается View.
 * тут же можно посылать уведомления о неициализированных переменных.
 */
	public function __get($name)
	{
		sendBugReport("CONTROLLER: variable {$name} is undefined", $name);
		return null;
	}

/**
 * Обеспечение работы помощников.
 * После регистрации помощника, все его методы доступны в представлении как свои собственные.
 * Используется магия __call()
 */
	public function __call($method_name, $args)
	{
		if (isset($this->__methods[$method_name]))
		{
			$helper = $this->__methods[$method_name];
			return call_user_func_array([$helper, $method_name], $args);
		}
		else
		{
			return null;
		}
	}

	public function registerHelper($helper_class_name)//class
	{
		$this->__methods = array_merge($this->__methods, Helper::registerHelper($helper_class_name, $this));
		return $this;
	}
}