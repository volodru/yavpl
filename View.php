<?php
/**
 * @NAME: View
 * @DESC: View prototype
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2009- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** CHANGELOG
 *
 * 1.05
 * DATE: 2020-04-12
 * добавлен метод default_JSON_Method(), см isJSON контроллера
 *
 * 1.04
 * DATE: 2018-12-10
 * изменена магия __get -
 * если поля нет в представлении - берем его из контроллера, а если его нет и в контроллере - берем через магию контроллера
 * в протоконтроллер (класс Controller) добавлена магия по умолчанию - всегда возвращает null.
 *
 * 1.03
 * DATE: 2015-10-30
 * кодировка установлена в UTF8
 *
 * 1.02
 * DATE: 2015-09-29
 * Выведена работа с тулбаром в класс ToolBal (в т.ч. и из Controller-a)
 *
 * 1.01
 * добавлен заголовок с версией, описанием и проч. к этому файлу
 */

class View
{
	public $controller;

	private $__header_tags = [
"<meta http-equiv='Content-Type' content='text/html; charset=UTF-8' />",
"<meta http-equiv='Content-Language' content='ru' />",
"<meta name='MS.LOCALE' content='RU' />",
"<link rel='icon' href='/favicon.ico' type='image/x-icon' />",
"<link rel='shortcut icon' href='/favicon.ico' type='image/x-icon' />",
	];

	//private $__doctype_declaration = "<!DOCTYPE html PUBLIC '-//W3C//DTD XHTML 1.0 Transitional//EN' 'http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd'>";
	private $__doctype_declaration = "<!DOCTYPE html>";
	private $__html_tag_attributes = "xmlns='http://www.w3.org/1999/xhtml' xml:lang='ru' lang='ru' dir='ltr'";

	private $__methods = [];
	private $breadcrumbs = [];
	//private $toolbar_elements = [];

	//public $title;

	function __construct()
	{
		global $application;
		if (isset($application) && isset($application->controller))
		{
			$this->controller = $application->controller;
		}
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
			if (APPLICATION_ENV == 'production')
			{
				return null;
			}
			else
			{
				sendBugReport("Call to undefined method {$method_name}", $method_name, true);
			}
		}
	}

/**
 * Регистрирует помощник.
 * Инициируется помощник, из него берутся все методы и складываются в кучку.
 * После этого все методы класса могут пользоваться методами помощника, как своими собственными.
 * Типичный помощник: helpers/html.php
 * Помощник сам может использовать методы вызвавшего его представления через магию __get()
 *
 */
	public function registerHelper($helper_class_name)//class
	{
		$this->__methods = array_merge($this->__methods, Helper::registerHelper($helper_class_name, $this));
		return $this;
	}

/**
 * Если нет поля, то берем его из своего контроллера
 */
	public function __get($name)
	{
		if (!isset($this->controller))
		{
			die("View::__get -> Controller was not initialized.");
		}
		if (isset($this->controller->$name))
		{
			return $this->controller->$name;
		}
		else
		{
			return $this->controller->__get($name);
		}
	}

/**
 * Если нет поля, то берем его из своего контроллера
 */
	public function __isset($name)
	{
		return isset($this->controller->$name);
	}

/**
 *
 * FOR DEBUGGING!
 * Если пытаемся установить значение в поле контроллера, то надо бы выдавать ошибку.
 */
/*
	function __set($name, $value)
	{
		if (isset($this->controller->$name))
		{
			__printBacktrace();
			die("Trying to set parent's field.");
		}
		__printBacktrace();
		die('set');
	}
*/
/**
 * Используется в методе render()
 */
	public function getHeaderTags()
	{
		return array_merge(
			["<title>".($this->controller->getTitle())."</title>"],
			$this->__header_tags);
	}

/**
 * Если не устраивают тэги по умолчанию (проект не русскоязычный и т.п.),
 * то можно установить с нуля все тэги в заголовке документа.
 * Рекомендуется делать это только в конструкторе defaultView всего проекта.
 */
	public function setHeaderTags($tags)
	{
		$this->__header_tags = $tags;
		return $this;
	}

/**
 * Добавить тэг к заголовку документа
 * Делать ПОСЛЕ setHeaderTags, т.к. тот игнорирует все выставленное до него.
 */
	public function addHeaderTag($tag)
	{
		$this->__header_tags[] = $tag;
		return $this;
	}

/**
 * Возвращает <!DOCTYPE... секцию документа
 * Используется в методе render()
 */
	public function getDoctypeDeclaration()
	{
		return $this->__doctype_declaration;
	}

/**
 * Если не устраивает дефолтное значение, то в defaultView проекта
 * можно сделать установку любого <!DOCTYPE...>
 */
	public function setDoctypeDeclaration($doctype)
	{
		$this->__doctype_declaration = $doctype;
		return $this;
	}

/**
 * Возвращает атрибуты тэга html.
 * Используется в методе render()
 */
	public function getHtmlTagAttributes()
	{
		return $this->__html_tag_attributes;
	}

/**
 * Устанавливает атрибуты тэга html.
 */
	public function setHtmlTagAttributes($attr)
	{
		$this->__html_tag_attributes = $attr;
		return $this;
	}

/**
 * Добавляет JS файл в заголовок документа
 */
	public function addJS($filename, $options = '')
	{
		if ($filename != '')
		{
			$this->__header_tags[] ="<script type='text/javascript' src='{$filename}' {$options}></script>";
		}
		return $this;
	}

/**
 * Добавляет CSS файл в заголовок документа
 */
	public function addCSS($filename)
	{
		if ($filename != '')
		{
			$this->__header_tags[] ="<link rel='stylesheet' type='text/css' href='{$filename}' />";
		}
		return $this;
	}

/**
 * Добавляет живой CSS код в заголовок документа
 */
	public function CSS($css)
	{
		$css = trim($css);
		if ($css != '')
		{
			$this->__header_tags[] = "<style type='text/css'>\n{$css}\n\t</style>";
		}
		return $this;
	}

/**
 * Добавляет живой JS код в заголовок документа
 */
	public function JS($js)
	{
		$js = trim($js);
		if ($js != '')
		{
			$this->__header_tags[] = "<script type='text/javascript'>\n{$js}\n\t</script>";
		}
		return $this;
	}

/**
 * Возвращает накопленные контроллерами "хлебные крошки".
 * Использовать желательно в defaultView проекта.
 */
	public function getBreadcrumbs()
	{
		$this->breadcrumbs = $this->controller->getBreadcrumbs();
		if (count($this->breadcrumbs) > 0)
		{
			$this->breadcrumbs[count($this->breadcrumbs)-1] = strip_tags($this->breadcrumbs[count($this->breadcrumbs)-1]);
			return "\n<div id='breadcrumbs'>\n\t<span class='breadcrumb'>".join("</span>\n\t{$this->breadcrumbs_delimiter}<span class='breadcrumb'>", $this->breadcrumbs)."</span>\n</div>\n";
		}
		else
		{
			return '';
		}
	}

/**
 * Метод должен быть перекрыт в defaultView проекта.
 * Метод должен отрисовать содержимое тэга body документа.
 */
	public function body($method_name)
	{
		die("Redeclare method 'body' in descendant view!");
	}

/**
 * Вызывается после окончания работы контроллера.
 * Отрисовывает страницу для метода $method_name соответствующего класса
 *
 * Особо одаренные наследники могут перекрыть весь render(), чтобы
 * вывести данные в pdf, rtf, xls и т.п.
 */
	public function render($method_name)
	{
		print $this->getDoctypeDeclaration().
"<html ".$this->getHtmlTagAttributes().">
<head>
\t".join("\n\t", $this->getHeaderTags())."
</head>
<body>";
		$this->body($method_name);
		print "</body>
</html>";
	}

/** Если установлен режим controler->isJSON() и в представлении нет нужного метода, то вызывается этот метод
 */
	public function default_JSON_Method()
	{
		if (isset($this->controller->result))//как правило это структура типа хеш
		{
			print json_encode($this->controller->result);
		}
		elseif ((isset($this->controller->message) && $this->controller->message != '') ||//просто сообщение с логами
			(isset($this->controller->log) && count($this->log) > 0))
		{
			print json_encode(['message' => $this->controller->message ?? '', 'log' => $this->log ?? []]);
		}
		else
		{
			sendBugReport("Call of __default_JSON() without \$result or \$message", "FATALITY!", true);
		}
	}
}