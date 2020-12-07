<?php
/**
 * @NAME: Application
 * @DESC: Application prototype
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2009- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/* CHANGELOG
 * 1.11
 * DATE: 2020-05-22
 * Добавлена возможность работы через CLI

 * 1.10
 * DATE: 2020-04-12
 * в методе run теперь вызывается метод для JSON вызовов
 * if ($this->controller->__is_json) $this->view->default_JSON_Method();

 * 1.09
 * DATE: 2018-12-12
 * Добавлена уведомлялка sendNotification() - просто посылает письмо без остановки процесса.
 *
 * 1.08
 * DATE: 2018-09-04
 * В sendBugReport() добавлен третий параметр - (....., $is_fatal = false)
 *
 * 1.07
 * DATE: 2018-05-24
 * Добавлена поддержка API контроллеров
 * Концепция: index.php разбирает URL и если видит, что домен похож на API вызов, то определяет
 * глобальную константу APPLICATION_RUNNING_MODE и
 * контроллеры грузятся не из папки controllers, а из папки api.
 * Через глобальную переменную пришлось делать, т.к. __autoload у нас статический, а делать его не статическим - значить менять
 * index.php во всех проектах. В данном случае проекты, в которых нет API трогать не надо. А в тех у которых есть - надо определить
 * константу APPLICATION_RUNNING_MODE в зависимости, от того, как реализован вызов API (по домену, по URL и т.д.)
 *
 * Наименования классов контроллеров такое же, представлений нет в принципе.
 * Контроллеры API пользуются теми же моделями проекта, но отдают данные через JSON|XML|etc.
 *
 * 1.06
 * DATE: 2017-09-30
 * Добавлен перехват исключений с посылкой письма админу __my_exception_handler
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

if (!defined('APPLICATION_RUNNING_MODE') || trim(APPLICATION_RUNNING_MODE) == '')
{
	define('APPLICATION_RUNNING_MODE', 'ui');
}
if (APPLICATION_RUNNING_MODE == 'api')
{
	define('CONTROLLERS_BASE_PATH', 'api');
}
elseif (APPLICATION_RUNNING_MODE == 'cli')
{
	define('CONTROLLERS_BASE_PATH', 'cli');
}
elseif (APPLICATION_RUNNING_MODE == 'ui')
{
	define('CONTROLLERS_BASE_PATH', 'controllers');
}
else
{
	die('Wrong APPLICATION_RUNNING_MODE: '.APPLICATION_RUNNING_MODE);
}

//регистрируем автозагрузчик для классов библиотеки
spl_autoload_register('Application::__autoload');

/** Мега универсальный отладчик. Название - сокращение от DumpArray
 */
function da($v)
{
	if (APPLICATION_RUNNING_MODE == 'cli')
	{
		print var_export($v, true)."\n";
	}
	else
	{
		print "<xmp>".var_export($v, true)."</xmp>";
	}
}

/** DumpArray in temp File - специально для отладки кукиев и сессий
 */
function daf($v)
{
	if (APPLICATION_ENV != 'production')
	{
		$l = fopen('/tmp/'.($_SERVER['SERVER_NAME']??'SERVER').'__'.date('Y_m_d__H_i_s').'.log', 'a+');
		fwrite($l, var_export($v, true)."\n");
		fclose($l);
	}
}

/** Обертка над print_backtrace - возвращает трейс в красивом виде через print_r
 */
function __getBacktrace()
{
	return print_r(debug_backtrace(0, 5), true);
}

/** Обертка для вывода только на девелопе/тесте, короче, кроме продакшн
 */
function __printBacktrace()
{
	if (defined('APPLICATION_ENV') && (APPLICATION_ENV != 'production'))
	{
		da(__getBacktrace());
	}
}

/** Мегаотладчик по почте - в случае проблем высылаем ошибку по email
 */
function sendBugReport($subject = 'Bug Report', $message = 'common bug', $is_fatal = false)
{
	if (!isset($_SESSION)){session_start();}

	if (APPLICATION_ENV != 'production')
	{//на девелопе убивать баги на месте, а с продакшена пусть придет письмо
		print "
<h1>BUG REPORT</h1>
<h2>$subject</h2>
<h2>$message</h2>
<div>TRACE:<xmp>".__getBacktrace()."</xmp></div>";
		exit();
	}

	(new Mail(TECH_SUPPORT_EMAIL, "[{$_SERVER['SERVER_NAME']}] ".$subject, $message."
{$_SERVER['SCRIPT_URI']}".((isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] != '') ? '?'.$_SERVER['QUERY_STRING'] : '')."
____________________________________________________
TRACE\n".__getBacktrace()."
--------------------------
SERVER\n" . print_r($_SERVER, true) ."
--------------------------
GET\n" . print_r($_GET, true) ."
--------------------------
POST\n" . print_r($_POST, true) ."
--------------------------
COOKIE\n" . print_r($_COOKIE, true) ."
--------------------------
SESSION\n" . print_r($_SESSION, true)))->send();
	if ($is_fatal)
	{
		print $subject.CRLF.$message;
		die();
	}
}

/** Инструмент для посылки уведомлений.
 * Уведомления предполагаются административного характера или бизнес-процессы и т.п.
 * Не для ошибочных ситуаций! Для потециальных ошибок и предупреждений использовать sendBugReport()
 */
function sendNotification($subject = 'Notification', $message = 'Message')
{
	if (!isset($_SESSION)){session_start();}

	(new Mail(TECH_SUPPORT_EMAIL, "[{$_SERVER['SERVER_NAME']}] ".$subject, $message."
____________________________________________________
SESSION\n" . print_r($_SESSION, true)))->send();
}

/** Свой обработчик ошибок. Ошибки надо исправлять. Непроверенный индекс в массиве или неинициализированная переменная - это ошибки!
 */
function __my_shutdown_handler()
{
	$error = error_get_last();
	if ($error !== NULL)
	{
		$info = "Fatal error: {$error['message']}\nfile: {$error['file']}, line :{$error['line']}";
		if (APPLICATION_ENV == 'production')
		{
			sendBugReport('FATAL Error', $info);
		}
		else
		{
			print "<xmp>$info</xmp>";
		}
	}
//включать тут по большим праздникам, т.к. все это пишется после </body> рушит валидность страницы и может покорежить дизайн.
	if (0 && APPLICATION_ENV != 'production')
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

/** Свой обработчик фатальных ошибок.
 */
function __my_exception_handler($exception)
{
	$message = $exception->getMessage();
	sendBugReport('Uncaught exception', $message);
	if (APPLICATION_ENV != 'production')
	{
		print '<h1>Uncaught exception</h1>';
		print "<h2>$message</h2>";
		print "<div>BACKTRACE\n<xmp>".__getBacktrace()."</xmp></div>";
	}
	else
	{
		print '<h1>Uncaught exception. eMail to the system administrator already has been sent.</h1>';
	}
}
set_exception_handler('__my_exception_handler');

/** Класс приложение
 *
 * Порядок работы:
 * * в приложении создается контроллер, вызывается его конструктор
 * * вызывается запрошенный метод контроллера
 * * в приложении создается представление ($application->view = new XXYYView())
 * * вызывается метод приложения render()
 * * render приложения вызывает либо view->render (обертка) либо сразу запрошенный метод из view
 * * view->render печатает в вывод стандартную обертку для HTML файла (заголовки) и вызывает метод view->body($method_name)
 * * view->body должен быть переопределен где-то в наследниках View (как правило в defaultView проекта) и
 * * отрисовывает обертку проекта (шапку, меню, футер и т.п.) и где-то среди нее вызывает this->$method_name
 * * !!! представление имеет доступ ко всем полям класса вызвавшего его контроллера через магию __get
 *
 * чтобы отрисовать отдельный элемент в AJAX или iframe в контроллере нужно установить disableRender()
 *
 * для AJAX есть специальный метод controller->isAJAX() устанавливающий заголовок с кодировкой и отключающий рендер
 * для отдачи бинарников есть метод controller->isBINARY()
 *
 * соглашение об именах в библиотеке:
 * - классы и методы - верблюжьим горбом, с маленькой буквы
 * - свойства и переменные маленькими буквами через подчеркивание
 *
 * в контроллерах:
 * * экземпляр класса - название сущности.
 * * список чегобытонибыло сущность_list (brands_list).
 * * описание сущности сущность_info (brand_info - вся строка из таблицы).
 * * контроллер оперирует моделями и получает из моделей данные к себе.
 * * контроллер не знает о представлении ничего и никак не влияет на его поля, напротив представление знает о всех полях контроллера.
 *
 * в представлениях:
 * * представление берет все данные из контроллера через магию __get. и ими же должно ограничиваться.
 * * все вычисления в представлении могут быть только для красивого вывода.
 * * представление _без крайней необходимости_ не должно использовать ссылки на модели из контроллера.
 * * и вообще, представление ничего не знает о моделях.

*/
class Application
{
	/** ссылка на Контроллер*/
	public $controller;
	/** ссылка на Представление*/
	public $view;
	/** название модуля по умолчанию*/
	public $default_module_name = 'index';
	/** название класса по умолчанию*/
	public $default_class_name = 'index';
	/** название метода по умолчанию*/
	public $default_method_name = 'index';
	/** URI как для CGI так и CLI*/
	private $__request_uri;

/** Загрузчик Контроллера */
	public function loadController()
	{
		$file_name = CONTROLLERS_BASE_PATH.'/'.(($this->module_name != '') ? $this->module_name."/" : '')."{$this->class_name}.php";
		if (file_exists(APPLICATION_PATH.'/'.$file_name))
		{
			require_once($file_name);//it does not depend on __autoload
			$s = (($this->module_name != '') ? $this->module_name.'_' : '')."{$this->class_name}Controller";
			if (class_exists($s))
			{
				$this->controller = new $s();
				$this->controller->setModuleName($this->module_name);//эти методы там устанавливают протектед поля
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

/** Загрузчик Представления */
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

/** Разборщик URI - если проект не ложится в схему Модуль->Класс->Метод перекрываем этот метод*/
	public function parseURI()
	{
		if (APPLICATION_RUNNING_MODE == 'cli')
		{
			$this->__request_uri = $_SERVER['argv'][1];
		}
		else//Normal And API
		{
			$this->__request_uri = trim(preg_replace("/(.+?)\?.+/", "$1", $_SERVER['REQUEST_URI']), '/');
		}
//берем все, что до знака вопроса, убираем последний и первый слэш и разрываем через слэш
		$uri = explode('/', $this->__request_uri);
		$this->module_name = ($uri[0] != '') ? $uri[0] : $this->default_module_name;
		$this->class_name = (count($uri) > 1) ? $uri[1] : $this->default_class_name;
		$this->method_name = (count($uri) > 2) ? $uri[2] : $this->default_method_name;
	}

	/** Главный метод приложения */
	public function run()
	{
//разобрали URI
		$this->parseURI();//sets global module, class, method
//создали экземпляр контроллера - вызвали конструктор
		if (!$this->loadController())
		{
			if (APPLICATION_RUNNING_MODE == 'cli')
			{
				$this->fatalError("Cannot load appropriate controller for URI [{$this->__request_uri}]");
			}
			else
			{
				$this->fatalError("Cannot load appropriate controller for page [{$this->__request_uri}].<br/><br/>It seems that page is not available anymore.<br/><br/>Please, try again from <a href='/'>the main page</a>.");
			}
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

		if (APPLICATION_RUNNING_MODE == 'api' || APPLICATION_RUNNING_MODE == 'cli')
		{
			return;//хватит для API и CLI
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
			{// сюда попадаем, если есть аджаксовый код, но без представления.
			// скорее всего, контроллер сам отдал данные в виде файла или JSON
				if ($this->controller->__is_json)
				{
					$this->view->default_JSON_Method();
				}
			}
		}
		return $this;
	}

/** Обработчик ситуации когда не нашли Контроллер по URI */
	protected function fileNotFound()
	{//override it to handle 404 errors
		header("HTTP/1.0 404 Not Found");
	}

/** Обработчик фатальный ситуаций - можно перекрыть, чтобы посылать их себе на почту */
	public function fatalError($msg)
	{//override method fatalError to log errors or send them via an email
		print $msg."\n";
		exit(0);
	}

/** Автозагрузчик всего и вся */
	static function __autoload($class_name)
	{
		//print "__autoload loading: $class_name<br />";
		// свои библиотечные файлы проверяем первыми.
		// оно меняется раз в несколько лет. пусть лежит в виде массива прямо тут.
		if (in_array($class_name, [
			'Db', 'DbPg', 'DbPgSingleton', 'DbMy',//СУБД
			'Mail',//Почта
			'Model', 'SimpleDictionaryModel', 'SimpleFilesModel', 'BasicUserModel', 'DocumentModel',//модельки
			'Controller', 'View', 'Helper', //ядро
			'ToolBar', 'Test',//плюшки
		]))
		{
			require_once($class_name.'.php');
			return true;
		}

		$matches = [];
		if (preg_match("/(.+)_(.+)Controller/", $class_name, $matches))
		{
			if ($matches[2] == 'default') {$matches[2] = '_default';}
			$file_name = CONTROLLERS_BASE_PATH."/{$matches[1]}/{$matches[2]}.php";
			if (!(file_exists(APPLICATION_PATH.'/'.$file_name)))
			{
				$file_name = CONTROLLERS_BASE_PATH."/".strtolower($matches[1])."/".strtolower($matches[2]).".php";
			}
		}
		elseif (preg_match("/(.+)_(.+)View/", $class_name, $matches))
		{
			if ($matches[2] == 'default') {$matches[2] = '_default';}
			$file_name = "views/{$matches[1]}/{$matches[2]}.php";
			if (!(file_exists(APPLICATION_PATH.'/'.$file_name)))
			{
				$file_name = "views/".strtolower($matches[1])."/".strtolower($matches[2]).".php";
			}
		}
		elseif ($class_name == 'defaultController')
		{
			$file_name = CONTROLLERS_BASE_PATH."/_default.php";
		}
		elseif ($class_name == 'defaultView')
		{
			$file_name = "views/_default.php";
		}
		elseif (preg_match("/(.+)(Model)/", $class_name, $matches))
		{
			$file_name = "models/".preg_replace("/_/", '/', $matches[1]).".php";
			if (!(file_exists(APPLICATION_PATH.'/'.$file_name)))
			{
				$file_name = "models/".strtolower(preg_replace("/_/", '/', $matches[1])).".php";
				if (!(file_exists(APPLICATION_PATH.'/'.$file_name)))
				{
					die("Cannot find file: $file_name");
				}
			}
		}
		elseif (preg_match("/(.+)(Helper)/", $class_name, $matches))
		{
			$file_name = "helpers/{$matches[1]}.php";
			if (!(file_exists(APPLICATION_PATH.'/'.$file_name)))
			{
				$file_name = "helpers/".strtolower($matches[1]).".php";
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