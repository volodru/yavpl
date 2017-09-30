<?php
/**
 * @NAME: Application
 * @DESC: Application prototype
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2009- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** CHANGELOG
 *
 * 1.05
 * DATE: 2015-10-30
 * кодировка установлена в UTF8
 *
 * 1.04
 * DATE: 2015-09-29
 * Использован класс ToolBar
 *
 * 1.03
 * Глобальные функции da(), sendBugReport(), __getBacktrace(), __printBacktrace() и __my_shutdown_handler()
 * теперь общие для всех проектов и находятся здесь
 *
 * 1.02
 * DATE: 2014-11-28
 * parseURI() - теперь не возвращает результат, а устанавливает переменные класса.
 * теперь можно не только перекрыть его, но и сделать ЧПУ в методе loadController(),
 * в котором загрузить правильный класс, поправить переменную с классом
 * и правильное представление загрузится само.
 *
 * loadController() - теперь возвращает true или false и фатальная ошибка загрузки контроллера
 * выводится классом Application
 *
 * loadController() и loadView() теперь используют не параметры, а переменные класса
 *
 * fileNotFound() - теперь просто выдает header("HTTP/1.0 404 Not Found"); и если надо обрабатывать
 * ЧПУ, то этот метод надо перекрыть, чтобы он ничего не делал, а 404 выдавать уже в обработчике ЧПУ
 *
 * fatalError($msg) - просто выводит сообщение и умирает. хедеры там не выводятся.
 *
 * 1.01
 * DATE: 2013-10-17
 * добавлен заголовок с версией, описанием и проч. к этому файлу
 * подкорректировано описание концепции
 */

/** Концепция приложения.
 *
 * порядок работы:
 * в приложении создается контроллер, вызывается его конструктор
 * вызывается запрошенный метод контроллера
 * в приложении создается представление ($application->view = new XXYYView())
 * вызывается метод приложения render()
 * render приложения вызывает либо view->render (обертка) либо сразу запрошенный метод из view
 * view->render печатает в вывод стандартную обертку для HTML файла (заголовки) и вызывает метод view->body($method_name)
 * view->body должен быть переопределен где-то в наследниках View (как правило в defaultView проекта) и
 * отрисовывает обертку проекта (шапку, меню, футер и т.п.) и где-то среди нее вызывает this->$method_name
 * !!! представление имеет доступ ко всем полям класса вызвавшего его контроллера через магию __get
 *
 * чтобы отрисовать отдельный элемент в AJAX или iframe в контроллере нужно установить disableRender()
 *
 * для AJAX есть специальный метод controller->isAJAX() устанавливающий заголовок с кодировкой и отключающий рендер
 * для отдачи бинарников есть метод controller->isBINARY()
 *
 * соглашение об именах в библиотеке:
 * классы и методы - верблюжьим горбом, с маленькой буквы
 * свойства и переменные маленькими буквами через подчеркивание
 *
 * в контроллерах:
 * экземпляр класса - название сущности.
 * список чегобытонибыло сущность_list (brands_list).
 * описание сущности сущность_info (brand_info - вся строка из таблицы).
 * контроллер оперирует моделями и получает из моделей данные к себе.
 * контроллер не знает о представлении ничего и никак не влияет на его поля, напротив представление знает о всех полях контроллера.
 *
 * в представлениях:
 * представление берет все данные из контроллера через магию __get. и ими же должно ограничиваться.
 * все вычисления в представлении могут быть только для красивого вывода.
 * представление _без крайней необходимости_ не должно использовать ссылки на модели из контроллера.
 * и вообще, представление ничего не знает о моделях.
 *
 */

//регистрируем автозагрузчик для классов библиотеки
spl_autoload_register('Application::__autoload');

/** мега универсальный отладчик. название - сокращение от DumpArray
 */
function da($v)
{
	print "<xmp>".var_export($v, true)."</xmp>";
}

/** DumpArray in temp File - специально для отладки кукиев и сессий
 */
function daf($v)
{
	if ($_SERVER['APPLICATION_ENV'] != 'production')
	{
		$l = fopen('/tmp/'.$_SERVER['SERVER_NAME'].'__'.date('Y_m_d__H_i_s').'.log', 'a+');
		fwrite($l, var_export($v, true)."\n");
		fclose($l);
	}
}

/** Обертка над print_backtrace
 */
function __getBacktrace()
{
	ob_start();
	debug_print_backtrace();
	$backtrace = ob_get_contents();
	ob_end_clean();
	return $backtrace;
}

/** Обертка для вывода только на девелопе/тесте, короче, кроме продакшн
 */
function __printBacktrace()
{
	if (isset($_SERVER['APPLICATION_ENV']) && ($_SERVER['APPLICATION_ENV'] != 'production'))
	{
		da(__getBacktrace());
	}
}

/** Еще один мегаотладчик
 */
function sendBugReport($subject = 'Bug Report', $message = 'common bug')
{
	if (!isset($_SESSION)){session_start();}

	$m = new Mail(
		ADMIN_EMAIL,
		"[{$_SERVER['SERVER_NAME']}] ".$subject,
		$message."
____________________________________________________

TRACE\n".__getBacktrace()."

SERVER\n" . print_r($_SERVER, true) ."

COOKIE\n" . print_r($_COOKIE, true) ."

SESSION\n" . print_r($_SESSION, true));
	$m->send();
}

/** Ошибки надо исправлять. Непроверенный индекс в массиве или неинициализированная переменная - это ошибки!
 */
function __my_shutdown_handler()
{
	$error = error_get_last();
	if ($error !== NULL)
	{
		$info = "Fatal error: {$error['message']}\nfile: {$error['file']}, line :{$error['line']}";
		if ($_SERVER['APPLICATION_ENV'] == 'production')
		{
			sendBugReport('FATAL Error', $info);
		}
		else
		{
			print "<xmp>$info</xmp>";
		}
	}
//включать тут по большим праздникам, т.к. все это пишется после </body> рушит валидность страницы и может попорежить дизайн.
	if (0 && $_SERVER['APPLICATION_ENV'] != 'production')
	{
		global $executed_sql;
		if (isset($executed_sql))
		{
			$i = 0;
			foreach ($executed_sql as $s)
			{
				print "<div  style='margin-top: 1em; padding: 1em; background-color: #EEE'><h1>".(++$i)."</h1><xmp>$s</xmp></div>";
			}
		}
	}
	return false;
}
register_shutdown_function('__my_shutdown_handler');

function __my_exception_handler($exception) {
  sendBugReport('Uncaught exception', $exception->getMessage());
}
set_exception_handler('__my_exception_handler');

class Application
{
	public $controller;
	public $view;

	public $default_module_name = 'index';
	public $default_class_name = 'index';
	public $default_method_name = 'index';

	function __construct()
	{
	}

	public function loadController()
	{
		$file_name = "controllers/".(($this->module_name != '') ? $this->module_name."/" : '')."{$this->class_name}.php";
		if (file_exists(APPLICATION_PATH.'/'.$file_name))
		{
			require_once($file_name);//it does not depend on __autoload
			$s = (($this->module_name != '') ? $this->module_name.'_' : '')."{$this->class_name}Controller";
			if (class_exists($s))
			{
				$this->controller = new $s();
				$this->controller->setModuleName($this->module_name);
				$this->controller->setClassName($this->class_name);
				$this->controller->setMethodName($this->method_name);
				$this->controller->setDefaultResourceId((($this->module_name != '') ? $this->module_name.'/' : '') . $this->class_name.'/'.$this->method_name);
				return true;
			}
			else
			{
				$this->fatalError("Cannot find class [$s] in file [$file_name]");
				return false;
			}
		}
		else
		{
			$this->fileNotFound();
			return false;
		}
	}

	public function loadView()
	{
		$file_name = "views/".(($this->module_name != '') ? $this->module_name.'/' : '')."{$this->class_name}.php";
		if (file_exists(APPLICATION_PATH.'/'.$file_name))
		{
			require_once($file_name);//it does not depend on __autoload
			$s = (($this->module_name != '') ? $this->module_name.'_':'' ). "{$this->class_name}View";
			if (class_exists($s))
			{
				$this->view = new $s();
			}//else и хрен бы с классом
		}//else и хрен бы с файлом
	}

	public function parseURI()
	{//берем все, что до знака вопроса, убираем последний и первый слэш и разрываем через слэш
		$uri = explode('/', trim(preg_replace("/(.+?)\?.+/", "$1", $_SERVER['REQUEST_URI']), '/'));
		$this->module_name = ($uri[0] != '') ? $uri[0] : $this->default_module_name;
		$this->class_name = (count($uri) > 1) ? $uri[1] : $this->default_class_name;
		$this->method_name = (count($uri) > 2) ? $uri[2] : $this->default_method_name;
	}

	public function run()
	{
//разобрали URI
		$this->parseURI();//sets global module, class, method
//создали экземпляр контроллера - вызвали конструктор
		if (!$this->loadController())
		{
			$this->fatalError("Cannot load appropriate controller for URI: [{$_SERVER['REQUEST_URI']}]");
		}
//вызвали нужный метод контроллера
		if (method_exists($this->controller, $this->method_name))
		{
			$this->controller->{$this->method_name}();
		}
		else
		{
			$this->controller->defaultMethod($this->method_name);
		}
//создали представление - вызвали контруктор
		$this->loadView();
//вызвали рисовалку представления
		if ($this->controller->__need_render)
		{//обычная отрисовка, html, body, блоки и все дела.
			if (method_exists($this->view, 'render'))
			{
				$this->view->render($this->method_name);//метод представления вызывается из $this->body()
			}
			else
			{//сюда попадаем если представление не наследовано от базового класса View
			//и чё делать тут?
			}
		}
		else
		{//если Аджакс, рисуем сразу нужный метод без оберток
			if (method_exists($this->view, $this->method_name))
			{

				$this->view->{$this->method_name}();
			}
			else
			{//сюда попадаем, если есть аджаксовый код, но без представления.
			//скорее всего это вопрос к опечаткам, т.к. в реальности такого быть не может.
			//вопрос на рефакторинг, может тут тоже что-то вызвать?
			}
		}
		return $this;
	}

	protected function fileNotFound()
	{//override it to handle 404 errors
		header("HTTP/1.0 404 Not Found");
	}

	public function fatalError($msg)
	{//override method fatalError to log errors or send them via an email
		print $msg;
		exit(0);
	}

	static function __autoload($class_name)
	{
		//print "__autoload loading: $class_name<br />";
		// свои библиотечные файлы проверяем первыми.
		// оно меняется раз в несколько лет. пусть лежит в виде массива прямо тут.
		if (in_array($class_name, [
			'Db', 'DbPg', 'DbPgSingleton', 'DbMy',//СУБД
			'Mail',//Почта
			'Model', 'SimpleDictionaryModel', 'BasicUserModel',//модельки
			'Controller', 'View', 'ViewHelper', //ядро
			'ToolBar', 'Test',//плюшки
		]))
		{
			require_once($class_name.'.php');
			return true;
		}

		if (preg_match("/(.+)_(.+)(Controller|View)/", $class_name, $r))
		{
			if ($r[2] == 'default') $r[2] = '_default';
			$file_name = strtolower($r[3])."s/{$r[1]}/{$r[2]}.php";
			if (!(file_exists(APPLICATION_PATH.'/'.$file_name)))
			{
				$file_name = strtolower($r[3])."s/".strtolower($r[1])."/".strtolower($r[2]).".php";
			}
		}
		elseif ($class_name == 'defaultController')
		{
			$file_name = "controllers/_default.php";
		}
		elseif ($class_name == 'defaultView')
		{
			$file_name = "views/_default.php";
		}
		elseif (preg_match("/(.+)(Model)/", $class_name, $r))
		{
			$file_name = "models/".preg_replace("/_/", '/', $r[1]).".php";
			if (!(file_exists(APPLICATION_PATH.'/'.$file_name)))
			{
				$file_name = "models/".strtolower(preg_replace("/_/", '/', $r[1])).".php";
			}
		}
		elseif (preg_match("/(.+)(Helper)/", $class_name, $r))
		{
			$file_name = "helpers/{$r[1]}.php";
			if (!(file_exists(APPLICATION_PATH.'/'.$file_name)))
			{
				$file_name = "helpers/".strtolower($r[1]).".php";
			}
		}
		else
		{
			//continue __autoload chain
			//!!! print "__autoload error: couldn't construct appropriate file for class [$class_name]";
			//!!! __printBackTrace();
			//!!! exit(1);
			return false;
		}

		//are we still here?
		if (file_exists(APPLICATION_PATH.'/'.$file_name))
		{
			require_once($file_name);
			return true;
		}
		else
		{//can be another helpers models and controllers in third party libraries, ex. PHPExcel*
			//print "__autoload error: file [$file_name] for class [$class_name] doesn't exists";
			//__printBackTrace();
			//exit(1);
			return false;
		}
	}
}