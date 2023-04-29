<?php
namespace YAVPL;
/**
 * @NAME: Application
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2009- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** CHANGELOG
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

//приложение работает в одном из 3х режимов: веб интерфейс (UI), API и CLI
if (!defined('APPLICATION_RUNNING_MODE') || trim(APPLICATION_RUNNING_MODE) == '')
{
	define('APPLICATION_RUNNING_MODE', 'ui');//режим по-умолчанию
}
//тут выясняем, где брать контроллеры
if (APPLICATION_RUNNING_MODE == 'api')
{//нет представления, контроллер всегда отдает JSON
	define('CONTROLLERS_BASE_PATH', 'api');
}
elseif (APPLICATION_RUNNING_MODE == 'cli')
{//режим командной строки - нет пользовательской сессии, все делается от администратора.
	define('CONTROLLERS_BASE_PATH', 'cli');
}
elseif (APPLICATION_RUNNING_MODE == 'ui')
{//режим веб-интерфейса - работает всё, выдаем HTML или что решит контроллер
	define('CONTROLLERS_BASE_PATH', 'controllers');
}
else
{//а всякую дичь отметаем сразу
	die('Wrong APPLICATION_RUNNING_MODE: '.APPLICATION_RUNNING_MODE);
}

require_once('DebugFunctions.php');

//регистрируем автозагрузчик для классов библиотеки
spl_autoload_register('\YAVPL\Application::__autoload');



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
			print "<xmp>{$info}</xmp>";
		}
	}
//включать тут по большим праздникам, т.к. все это пишется после </body>, рушит валидность страницы и может покорежить дизайн.
	if (0 && APPLICATION_ENV != 'production')
	{
		global $executed_sql;
		if (isset($executed_sql))
		{
			$i = 0;
			foreach ($executed_sql as $s)
			{
				print "<div  style='margin-top: 1em; padding: 1em; background-color: #EEE'><h1>".(++$i)."</h1><xmp>{$s}</xmp></div>";
			}
		}
	}
	return false;
}
register_shutdown_function('\\YAVPL\\__my_shutdown_handler');

/** Свой обработчик фатальных ошибок.
 */
function __my_exception_handler($exception)
{
	$message = $exception->getMessage();
	sendBugReport('Uncaught exception', $message);
	if (APPLICATION_ENV != 'production')
	{
		print '<h1>Uncaught exception</h1>';
		print "<h2>{$message}</h2>";
		print "<div>BACKTRACE\n<xmp>".__getBacktrace()."</xmp></div>";
	}
	else
	{
		print '<h1>Uncaught exception. eMail to the system administrator already has been sent.</h1>';
	}
}
set_exception_handler('\\YAVPL\\__my_exception_handler');

/** Класс приложение
 *
 * Порядок работы:
 * * в приложении создается контроллер, вызывается его конструктор (ну и предки по желанию конструктора контроллера)
 * * вызывается запрошенный метод контроллера
 * * в приложении создается представление ($application->view = new XXYYView())
 * * вызывается метод представления render()
 * * метод run приложения вызывает либо view->render (обертка), либо сразу запрошенный метод из view, если рендер заблокирован
 * * view->render печатает в вывод стандартную обертку для HTML файла (тэг html, заголовки head и тэг body)
 * и вызывает метод view->body($method_name) внутри тэга body
 * * view->body должен быть переопределен где-то в наследниках View (как правило в defaultView проекта) и
 * * отрисовывает обертку конкретного проекта (шапку, меню, футер и т.п.) и где-то среди нее вызывает this->$method_name
 * * !!! представление имеет доступ ко всем полям класса вызвавшего его контроллера через магию __get
 *
 * чтобы отрисовать отдельный элемент в AJAX или iframe в контроллере нужно установить disableRender()
 *
 * для AJAX есть специальный метод controller->isAJAX() устанавливающий заголовок с кодировкой и отключающий рендер
 * для отдачи бинарников есть метод controller->isBINARY()
 * для работы с JSON - вызываем controller->isJSON() и заполняем переменную $this->result (и всё) см. view->default_JSON_Method()
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
 * * представление без большой необходимости не должно использовать ссылки на модели из контроллера.
 * 		исключения, например, для ACL - можно в представлении делать if ($this->user->isAllowe('resource_id')) {print "...";}
 * * и вообще, представление как бы ничего не знает о моделях. но иногда в лом объявлять в контроллере shortcut
 * 		на какую-нибудь структуру в модели только для того, чтобы ее один раз показать в каком-нибудь селекторе
*/
class Application
{
	/** глобальная ссылка на Контроллер*/
	public Controller $controller;

	/** глобальная ссылка на Представление*/
	public View $view;

	/** название модуля по умолчанию*/
	public string $default_module_name = 'index';

	/** название класса по умолчанию*/
	public string $default_class_name = 'index';

	/** название метода по умолчанию*/
	public string $default_method_name = 'index';

	/** URI как для CGI так и CLI*/
	protected string $__request_uri;

/** Загрузчик Контроллера */
	public function loadController(): bool
	{//работает для форматов в виде модуль/класс/метод или класс/метод (для простых проектов)
	// для сложных структур - это надо всё будет переопределить, например для проект/раздел/модуль/класс/метод.
		//$file_name = CONTROLLERS_BASE_PATH.'/'.(($this->module_name != '') ? $this->module_name."/" : '')."{$this->class_name}.php";
		$s2 = CONTROLLERS_BASE_PATH.'\\'.$this->module_name.'\\'.$this->class_name;
		if (class_exists($s2))//тут запускается автозагружалка
		{
			$this->controller = new $s2();//делаем экземпляр класса
			$this->controller->setModuleName($this->module_name);//эти методы там просто устанавливают протектед поля
			$this->controller->setClassName($this->class_name);
			$this->controller->setMethodName($this->method_name);
			$this->controller->setDefaultResourceId((($this->module_name != '') ? $this->module_name.'/' : '') . $this->class_name.'/'.$this->method_name);
			return true;
		}
		else
		{
			$this->fatalError("Cannot load class [{$s2}]");//
			return false;
		}
/*


		if (file_exists(APPLICATION_PATH.'/'.$file_name))
		{
			require_once($file_name);//it does not depend on __autoload - мы тут сами как-нибудь
			$s1 = (($this->module_name != '') ? $this->module_name.'_' : '')."{$this->class_name}Controller";
			$s2 = CONTROLLERS_BASE_PATH.'\\'.$this->module_name.'\\'.$this->class_name;
			if (class_exists($s1, false))
			{
				$this->controller = new $s1();//делаем экземпляр класса
				$this->controller->setModuleName($this->module_name);//эти методы там просто устанавливают протектед поля
				$this->controller->setClassName($this->class_name);
				$this->controller->setMethodName($this->method_name);
				$this->controller->setDefaultResourceId((($this->module_name != '') ? $this->module_name.'/' : '') . $this->class_name.'/'.$this->method_name);
				return true;
			}
			elseif (class_exists($s2, false))
			{
				$this->controller = new $s2();//делаем экземпляр класса
				$this->controller->setModuleName($this->module_name);//эти методы там просто устанавливают протектед поля
				$this->controller->setClassName($this->class_name);
				$this->controller->setMethodName($this->method_name);
				$this->controller->setDefaultResourceId((($this->module_name != '') ? $this->module_name.'/' : '') . $this->class_name.'/'.$this->method_name);
				return true;
			}
			else
			{
				$this->fatalError("Cannot find classes [{$s1}] OR [{$s2}] in file [{$file_name}]");//фаталити - файл есть, а класса в нем нет.
				return false;
			}
		}
		else
		{
			$this->fileNotFound();
			return false;
		}
		*/
	}

/** Загрузчик Представления */
	public function loadView(): bool
	{
		$s2 = 'Views\\'.(($this->module_name != '') ? $this->module_name : '').'\\'.$this->class_name;
		if (class_exists($s2))
		{
			$this->view = new $s2();
			return true;
		}
		else
		{//else и хрен бы с классом
			return false;
		}
		/*

		$file_name = "views/".(($this->module_name != '') ? $this->module_name.'/' : '')."{$this->class_name}.php";

		if (file_exists(APPLICATION_PATH.'/'.$file_name))
		{
			require_once($file_name);//it does not depend on __autoload
			$s1 = (($this->module_name != '') ? $this->module_name.'_' : '' ). "{$this->class_name}View";
			$s2 = 'Views\\'.(($this->module_name != '') ? $this->module_name : '').'\\'.$this->class_name;
			if (class_exists($s1, false))
			{
				$this->view = new $s1();
				return true;
			}
			elseif (class_exists($s2, false))
			{
				$this->view = new $s2();
				return true;
			}
			else
			{//else и хрен бы с классом
				return false;
			}
		}
		else //и хрен бы с файлом
		{
			return false;
		}*/
	}

/** Разборщик URI - если проект не ложится в схему Модуль->Класс->Метод перекрываем этот метод
 * в перекрытом методе надо присвоить значение $this->__request_uri, т.к. его потом использует метод fileNotFound
 * */
	public function parseURI(): void
	{
		if (APPLICATION_RUNNING_MODE == 'cli')
		{//CLI - первый параметр в режиме CLI - модуль/класс/метод, потом все остальные параметры
			$this->__request_uri = $_SERVER['argv'][1];
		}
		else//WEB And API
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
	public function run(): void
	{
//разобрали URI
		$this->parseURI();//sets global module, class, method
//создали экземпляр контроллера - вызвали конструктор класса контроллера
		if (!$this->loadController())
		{
			if (APPLICATION_RUNNING_MODE == 'cli')
			{
				$this->fatalError("CLI mode: Cannot load appropriate controller for URI [{$this->__request_uri}]");
			}
			else
			{
				return;//а чё ещё делать, если контроллер не нашелся. пусть программист сам ищет контроллер.
			}
		}
//вызвали нужный метод контроллера
		if (method_exists($this->controller, $this->method_name))
		{
			$this->controller->{$this->method_name}();//если такой метод есть
		}
		else
		{//если в проекте предусмотрены просто шаблоны (views) без контроллеров, то ничего не делаем, иначе там можно вывести ошибку.
			$this->controller->defaultMethod($this->method_name);//иначе вызываем дефолтный метод
		}

		if (APPLICATION_RUNNING_MODE == 'api' || APPLICATION_RUNNING_MODE == 'cli')
		{
			return;//хватит для API и CLI
		}

//для WEB режима идем дальше
//загрузили файл и создали представление - вызвали его конструктор


		//если представление вообще загрузилось, то продолжаем.
		//if (isset($this->view))
		if ($this->loadView())
		{//вызвали рисовалку представления
			if ($this->controller->__need_render)
			{//обычная отрисовка, html, body, блоки и все дела.
				if (method_exists($this->view, 'render'))
				{
					$this->view->render($this->method_name);//метод представления вызывается из $this->body()
				}
				else
				{//сюда попадаем если представление не наследовано от базового класса View
				 //и чё делать тут? вообще-то это фаталити.
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
					if ($this->controller->__is_json)//а если прямо явно указано, что это JSON
					{
						$this->view->default_JSON_Method();//то выводим данные в формате JSON
					}
				}
			}
		}
		else
		{
			//тут оказываемся если не нашелся класс с представлением.
			//а вот должен он был ли вообще быть? а может его и не было совсем. контроллер с чистыми аджаксами, например.
		}
	}

	public function getMainDBConnector(): \YAVPL\Db
	{
		die('У класса приложения должна быть реализована функция getMainDBConnector()');
	}

	public function getEntityTypesInstance()
	{
		die('У класса приложения должна быть реализована функция getEntityTypesInstance()');
	}

	public function getBasicModel($name)
	{
		global $application;
		if ($application->getBasicModelCash($name) == false)
		{
			$model_name = "\\Models\\".$name;
			//da("new model $model_name");
			$model = new $model_name();
			//da("model created $model_name");
			$application->setBasicModelCash($name, $model);
			//da("after setBasicModelCash");
		}
		return $application->getBasicModelCash($name);
	}

/** Обработчик ситуации когда не нашли Контроллер по URI */
	protected function fileNotFound(): void
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
	public static function __autoload($class_name)
	{
		//print "<p>Application::__autoload loading: {$class_name}</p>";
		//__printBackTrace();
		// свои библиотечные файлы проверяем первыми.
		// оно меняется раз в несколько лет. пусть лежит в виде массива прямо тут.
		$s = preg_replace("/YAVPL\\\\/", "", $class_name);

		if (in_array($s, [
			'Db', 'DbPg', 'DbPgSingleton', 'DbMy',//СУБД
			'Mail',//Почта
			'Model', 'SimpleDictionaryModel', 'SimpleFilesModel', 'BasicUserModel', 'DocumentModel',//модельки
			'Controller', 'View', 'Helper', //ядро фреймворка
			'ToolBar', 'Test',//плюшки
		]))
		{
			//da("Loading YAVPL file: $s");
			require_once($s.'.php');
			return true;
		}

//вызовы классов-предков. непосредственный контроллер грузится в loadController - предки и все остальные грузятся тут

		$matches = [];
		/*
		if (preg_match("/(.+)_(.+)Controller/", $class_name, $matches))
		{//контроллеры
			if ($matches[2] == 'default') {$matches[2] = '_default';}
			$file_name = CONTROLLERS_BASE_PATH."/{$matches[1]}/{$matches[2]}.php";
			if (!(file_exists(APPLICATION_PATH.'/'.$file_name)))
			{
				$file_name = CONTROLLERS_BASE_PATH."/".strtolower($matches[1])."/".strtolower($matches[2]).".php";
			}
		}
		elseif (preg_match("/(.+)_(.+)View/", $class_name, $matches))
		{//представления
			if ($matches[2] == 'default') {$matches[2] = '_default';}
			$file_name = "views/{$matches[1]}/{$matches[2]}.php";
			if (!(file_exists(APPLICATION_PATH.'/'.$file_name)))
			{
				$file_name = "views/".strtolower($matches[1])."/".strtolower($matches[2]).".php";
			}
		}
		elseif ($class_name == 'defaultController')
		{//главный контроллер проекта
			$file_name = CONTROLLERS_BASE_PATH."/_default.php";
		}
		elseif ($class_name == 'defaultView')
		{//главное представление проекта - именно там шаблон всех страниц проекта
			$file_name = "views/_default.php";
		}*/
//новая схема для моделей
		if (preg_match("/^Models\\\\(.+)/", $class_name, $matches))
		{//модельки грузятся по необходимости
			//da('new model naming');da('classname: '.$class_name);da($matches);
			$file_name = strtolower("models/{$matches[1]}.php");
			//da('filename: '.$file_name);
			if (!(file_exists(APPLICATION_PATH.'/'.$file_name)))
			{
				$file_name = "models/".strtolower(preg_replace("/\\\\/", '/', $matches[1])).".php";
				//da('filename for submodels: '.$file_name);
				if (!(file_exists(APPLICATION_PATH.'/'.$file_name)))
				{
					print "<div>BACKTRACE\n<xmp>".__getBacktrace()."</xmp></div>";
					die("Cannot find file while loading model : $file_name");
				}
			}
		}
		/*
		elseif (preg_match("/(.+)(Model)/", $class_name, $matches))
		{//модельки грузятся по необходимости
			$file_name = "models/".preg_replace("/_/", '/', $matches[1]).".php";
			//da($file_name);
			//__printBackTrace();
			if (!(file_exists(APPLICATION_PATH.'/'.$file_name)))
			{
				$file_name = "models/".strtolower(preg_replace("/_/", '/', $matches[1])).".php";
				if (!(file_exists(APPLICATION_PATH.'/'.$file_name)))
				{
					print "<div>BACKTRACE\n<xmp>".__getBacktrace()."</xmp></div>";
					die("Cannot find file: $file_name");
				}
			}
		}
		elseif (preg_match("/(.+)(Helper)/", $class_name, $matches))
		{//хелперы - грузятся по необходимости
			$file_name = "helpers/{$matches[1]}.php";
			if (!(file_exists(APPLICATION_PATH.'/'.$file_name)))
			{
				$file_name = "helpers/".strtolower($matches[1]).".php";
			}
		}*/
		else
		{
			//for namespaces
			//da($class_name);
			$s = explode('\\', strtolower($class_name));
			$file_name = join('/', $s).".php";
			//da($s);			da($file_name);
			if (file_exists(APPLICATION_PATH.'/'.$file_name))
			{
				require_once($file_name);
				return true;
			}

			//continue __autoload chain
			//!!! print "__autoload error: couldn't construct appropriate file for class [$class_name]";
			//!!! __printBackTrace();
			//!!! exit(1);
			return false;
		}

		//are we still here? т.е. пока еще все хорошо - мы сформировали путь к файлу
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