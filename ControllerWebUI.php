<?php
namespace YAVPL;
/**
 * @NAME: ControllerWebUI
 * @DESC: Controller веб интерфейса
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2025- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** CHANGELOG
 * DATE: 2025-01-28
 * Вынесены специфичные функции для веб интерфейсов
 */

class ControllerWebUI extends Controller
{
/** Хлебные крошки. Т.к. оно реализовано в виде 1 массива и двух методов, то делать отдельный класс для этого - нунафиг.
Тулбар - штука посложнее, поэтому он в отдельном классе. см. ToolBar.php
 */
	public $__breadcrumbs_delimiter = " &raquo;&raquo;\n\t";
	public $__breadcrumbs = [];

/** Сообщение юзеру. Если нет View, то будет просто выведено это поле. */
	public $message = '';

/** Логи контроллера. Если нет View выведутся после сообщения $this->message. */
	public $log = [];

/** Включать ли рендер всей страницы (meta/head/body) или это AJAX в виде простого потока HTML
 */
	//public bool $__need_render = true;

/** Заголовок страниц проекта по умолчанию.
 */
	private string $__title = 'THIS IS THE TITLE OF THE PROJECT';

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
	public function defaultMethod(string $method_name):void
	{
	}

/**
 * Выключает рендеринг через главное представление.
 * Нужно для аджакса, бинарников и некоторых специальных случаев, типа печатных версий или
 * версий для мобильных устройств.
 *
 * Принцип выделения минимального - 99% страниц отдаются в виде именно страницы с шапкой, хлебными крошками и подвалом.
 */
	/*
	public function disableRender(): Controller
	{
		$this->__need_render = false;
		return $this;
	}*/

/** Для аджаксных вызовов, если результат в виде HTML
 */
	public function isAJAX(): Controller
	{//просто отдаём HTML без обёрток из шапки и подвала сайта
		$this->disableRender();//как рендерить, проверяет Application когда вызывает View->render
		return $this;
	}

/** Для аджаксных вызовов ожидающих строго JSON формат.
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

/** Для вызовов text/event-stream */
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

/** Для вызовов text/event-stream */
	public function sendEventStreamMessage($id, $data)
	{
		//https://www.php.net/manual/en/function.cli-set-process-title.php
		print "id: {$id}" . PHP_EOL . "data: " . json_encode($data) . PHP_EOL . PHP_EOL;
		ob_flush();
		flush();
	}

/** Для простых контроллеров без представления - выполнил работу и перешел на другую страницу.
 * Редирект если нет сообщения. Если есть сообщение, то будет выведено оно.
 * Если есть сообщение и таки надо сделать редирект - сначала надо обнулить сообщение или
 * разобраться в ситуации - нам таки надо редирект или сообщение.
 */
	protected function redirect(string $url = '/'): void
	{
		if ($this->message == '')
		{
			header("Location: {$url}");
			exit(0);
		}
	}

/** Получить приватное поле с крошками */
	public function getBreadcrumbs(): array
	{
		return $this->__breadcrumbs;
	}

/** Добавить хлебную крошку. Если не передать заголовок, выведет имя метода.
 * $title заголовок
 * $link гиперссылка (лучше локальная, без протокола)
 */
	protected function addBreadcrumb(string $title = '', string $link = ''): Controller
	{
		if ($title == '')
		{
			$title = debug_backtrace()[1]['function'];
		}
		$this->__breadcrumbs[] = ($link != '') ? "<a href='{$link}'>{$title}</a>" : $title;
		return $this;
	}

/**  Устанавливает title Документа. Если не указать title, то выводим все хлебные крошки на данный момент в обратном порядке.
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
 * это не "public Морозов", это только чтение.
 */
	public function getTitle(): string
	{
		return $this->__title;
	}

}