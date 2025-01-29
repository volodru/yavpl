<?php
declare(strict_types=1);
namespace YAVPL;

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

#[\AllowDynamicProperties]
class View
{
/** Ссылка на класс-контроллер.
 * Устанавливается в конструкторе представления из глобальной переменной $application.
 */
	public Controller $controller;

	private array $__header_tags = [
"<meta http-equiv='Content-Type' content='text/html; charset=UTF-8' />",
"<meta http-equiv='Content-Language' content='ru' />",
"<meta name='MS.LOCALE' content='RU' />",
"<link rel='icon' href='/favicon.ico' type='image/x-icon' />",
"<link rel='shortcut icon' href='/favicon.ico' type='image/x-icon' />",
	];

	private string $__doctype_declaration = "<!DOCTYPE html>";
	private string $__html_tag_attributes = "xmlns='http://www.w3.org/1999/xhtml' xml:lang='ru' lang='ru' dir='ltr'";

	private array $__methods = [];//for Helpers

/**
 *  Берем контроллер из глобальной пременной $application, т.к. в наследниках нужна магия _get и данные контроллера сразу в конструкторе
 */
	public function __construct()
	{
/* получаем контроллер именно тут т.е. в конструкторе через глобальную переменную,
* т.к. в конструкторах контроллеров вьшек иногда надо уже знать переменные из контроллера и view->__get должен отработать.
 * например, в JS коде, где надо упоминать переменные из контроллера*/
		global $application;
		$this->controller = $application->controller;
	}

/**
 * Обеспечение работы помощников.
 * После регистрации помощника, все его методы доступны в представлении как свои собственные.
 * Используется магия __call()
 */
	public function __call(string $method_name, array $args): mixed
	{
		if (isset($this->__methods[$method_name]))
		{
			$helper = $this->__methods[$method_name];
			return call_user_func_array([$helper, $method_name], $args);
		}
		else
		{
			sendBugReport("__call(".get_class($this)."->{$method_name})", 'call undefined VIEW method');
			return null;
			/*
			if (APPLICATION_ENV == 'production')
			{
				return null;
			}
			else
			{
				//sendBugReport("Call to undefined method {$method_name}", $method_name, true);
				sendBugReport("__call(".get_class($this)."->{$method_name})", 'call undefined VIEW method');
			}*/
		}
	}

/**
 * Если нет поля, то берем его из своего контроллера
 */
	public function __get(string $name): mixed
	{
		if (!isset($this->controller))
		{
			sendBugReport("View::__get -> Controller was not initialized.", '', true);
		}
		if (isset($this->controller->$name))
		{
			return $this->controller->$name;
		}

		//da("VIEW __get $name");

/* проблема
 * get_object_vars - процедура долгая
 * нужна редко - для переменных которые могут быть null
 * поэтому ее бы поставить в конце
 * НО в конце у нас магия __get контроллера, которая если не найдет переменную сделает фаталити
 *
 * поэтому пока рекомендация: все переменные инициировать в контроллере, тогда они там будут храниться как в кеше и
 * до get_object_vars дело не дойдет.
	 */
		if (array_key_exists($name, get_object_vars($this->controller)))
		{
			return $this->controller->$name;
		}

		return $this->controller->__get($name);
	}

/**
 * Если нет поля, то берем его из своего контроллера
 */
	public function __isset(string $name): bool
	{
		if (!isset($this->controller))
		{
			sendBugReport("View::__isset -> Controller was not initialized.", '', true);
		}
		return isset($this->controller->$name);
	}

/**
 * Регистрирует помощник.
 * Инициируется помощник, из него берутся все методы и складываются в кучку.
 * После этого все методы класса могут пользоваться методами помощника, как своими собственными.
 * Типичный помощник: helpers/html.php
 * Помощник сам может использовать методы вызвавшего его представления через магию __get()
 *
 */
	public function registerHelper(string $helper_class_name): View//class
	{
		$this->__methods = array_merge($this->__methods, \YAVPL\Helper::registerHelper($helper_class_name, $this));
		return $this;
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
	protected function getHeaderTags(): array
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
	protected function setHeaderTags(array $tags): View
	{
		$this->__header_tags = $tags;
		return $this;
	}

/**
 * Добавить тэг к заголовку документа
 * Делать ПОСЛЕ setHeaderTags, т.к. тот игнорирует все выставленное до него.
 */
	protected function addHeaderTag(string $tag): View
	{
		$this->__header_tags[] = $tag;
		return $this;
	}

/**
 * Возвращает <!DOCTYPE... секцию документа
 * Используется в методе render()
 */
	protected function getDoctypeDeclaration(): string
	{
		return $this->__doctype_declaration;
	}

/**
 * Если не устраивает дефолтное значение, то в defaultView проекта
 * можно сделать установку любого <!DOCTYPE...>
 */
	protected function setDoctypeDeclaration(string $doctype): View
	{
		$this->__doctype_declaration = $doctype;
		return $this;
	}

/**
 * Возвращает атрибуты тэга html.
 * Используется в методе render()
 */
	protected function getHtmlTagAttributes(): string
	{
		return $this->__html_tag_attributes;
	}

/**
 * Устанавливает атрибуты тэга html.
 */
	protected function setHtmlTagAttributes(string $attr): View
	{
		$this->__html_tag_attributes = $attr;
		return $this;
	}

/**
 * Добавляет JS файл в заголовок документа
 */
	protected function addJS(string $filename, string $type = 'text/javascript'): View
	{
		if ($filename != '')
		{
			$this->__header_tags[] = "<script type='{$type}' src='{$filename}'></script>";
		}
		return $this;
	}

/**
 * Добавляет CSS файл в заголовок документа
 */
	protected function addCSS(string $filename): View
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
	protected function CSS(string $css): View
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
	protected function JS(string $js, string $type = 'text/javascript'): View
	{
		$js = trim($js);
		if ($js != '')
		{
			$this->__header_tags[] = "<script type='{$type}'>\n{$js}\n</script>";
		}
		return $this;
	}

/**
 * Возвращает накопленные контроллерами "хлебные крошки".
 * Использовать желательно в главном View проекта.
 */
	protected function getBreadcrumbs(): string
	{
		$breadcrumbs = $this->controller->getBreadcrumbs();
		if (count($breadcrumbs) > 0)
		{
			$breadcrumbs[count($breadcrumbs)-1] = strip_tags($breadcrumbs[count($breadcrumbs)-1]);
			return "<div id='breadcrumbs'><span class='breadcrumb'>".join("</span>\n\t{$this->__breadcrumbs_delimiter}<span class='breadcrumb'>", $breadcrumbs)."</span></div>";
		}
		else
		{
			return '';
		}
	}

/** Если есть действие в контроллере, но там особенно нечего рисовать, кроме сообщения или логов -
 * то метод представления можно и не делать - а будет вызван этот код
 */
	protected function defaultMethod(): void
	{
		if ($this->message != '')
		{
			print "<div class='message'>{$this->message}</div>";
		}
		if (isset($this->log) && is_array($this->log) && count($this->log) > 0)
		{
			print "<div class='log'><pre>";
			foreach ($this->log as $line)
			{
				print "{$line}\n";
			}
			print "</pre></div>";
		}
	}

/**
 * Метод должен быть перекрыт в главном View проекта.
 * Метод должен отрисовать содержимое тэга body документа.
 */
	protected function body(string $method): void
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
	public function render(string $method_name): void
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

/** Если установлен режим controler->isJSON() и в представлении нет нужного метода, то вызывается этот метод.
 * это только для ui режима!
 * для режима API используется свой механизм прямо в контроллере.
 */
	/*
	public function default_JSON_Method(): void
	{
		if (isset($this->controller->result))//как правило это структура типа хеш
		{
			print json_encode($this->controller->result);
		}
		elseif ((($this->controller->message ?? '') != '') ||//просто сообщение с логами
			(count($this->log ?? []) > 0))
		{
			print json_encode(['message' => $this->controller->message ?? '', 'log' => $this->log ?? []]);
		}
		else
		{//надо выдать хоть что-то, а то непонятно, зачем мы все это делали
			sendBugReport("Call of __default_JSON() without \$result or \$message", "FATALITY!", true);
		}
	}*/
}