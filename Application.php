<?php
declare(strict_types=1);
namespace YAVPL;
/**
 * @NAME: Application
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2009- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** CHANGELOG
 *
 * DATE: 2023-05-27
 * убран fatalError
 * fileNotFound теперь понимает режим JSON
 * добавлен вызов метода init контроллера сразу после создания класса контроллера и заполнения его полей типа $this->running_method_name
 *
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
		$user_message = "<h1>Uncaught exception</h1><h2>{$message}</h2><div>BACKTRACE\n<xmp>".__getBacktrace()."</xmp></div>";
	}
	else
	{
		$user_message = '<h1>Uncaught exception. eMail to the system administrator already has been sent.</h1>';
	}

	if (1|| APPLICATION_RUNNING_MODE == 'ui')
	{
		print $user_message;
	}
}
set_exception_handler('\\YAVPL\\__my_exception_handler');

/** Класс приложение
 *
 * Порядок работы:
 * в приложении создается контроллер, вызывается его конструктор (ну и предки по желанию конструктора контроллера)
 * конроллеру устанавливаются поля $this->running_method_name (ну и модуль и класс)
 * вызывается метод init()
 * вызывается запрошенный метод контроллера
 * в приложении создается представление ($application->view = new XXYYView())
 * вызывается метод представления render()
 * метод run приложения вызывает либо view->render (обертка), либо сразу запрошенный метод из view, если рендер заблокирован
 * view->render печатает в вывод стандартную обертку для HTML файла (тэг html, заголовки head и тэг body)
 * и вызывает метод view->body($method_name) внутри тэга body
 * view->body должен быть переопределен где-то в наследниках View (как правило в defaultView проекта) и
 * отрисовывает обертку конкретного проекта (шапку, меню, футер и т.п.) и где-то среди нее вызывает this->$method_name
 * !!! представление имеет доступ ко всем полям класса вызвавшего его контроллера через магию __get
 * вызывается метод done() контроллера и экземпляру контроллера делается unset()
 *
 *
 *----------------------------------------------------------------------
 * Разделение труда
 *
 * Контроллер ДОЛЖЕН:
 * 1. проверить авторизацию (с предварительной аутентификацией)
 * 2. проверить пользовательский ввод
 * 3. с помощью моделей собрать данные для представления.
 *
 * Контроллер НЕ ДОЛЖЕН
 * 1. лезть в СУБД
 * 2. выводить HTML
 *
 * Контроллер МОЖЕТ сам отдать бинарный файл (XLSX/DOCX), CSV, JSON
 *
 * Контроллер может быть ТОНКИМ (предпочтительно), ТОЛСТЫМ (допустимо) и ОЧЕНЬ ТОЛСТЫМ (нежелательно).
 * Тонкий контроллер делает 1 запрос к нужной модели и получает сразу всю информацию, нужную для представления.
 * Толстый контроллер реализует бизнес логику путем вызова простых моделей, чтобы ради одного вызова
 * не писать лишний метод в модели, который вызывается строго из одной точки.
 *
 * В исключительных случаях возможен ОЧЕНЬ ТОЛСТЫЙ контроллер, который самостоятельно выполняет SQL запросы.
 * Случаю должны быть редкими и обоснованными. Основанием может быть одноразовость кода, либо полное отсутсвие инфы о
 * перспективах развития, когда невозможно думать на уровне моделей.
 *
 * Представление ДОЛЖНО
 * 1. более или менее красиво вывести собранные контроллером данные
 * 2. имеет право выводить или не выводить инфу исходя из данных, собранных контроллером (не всем можно видеть всё).
 *
 * Представление НЕ ДОЛЖНО
 * 1. лезть в СУБД
 * 2. проверять пользовательский ввод
 * 3. формировать бинарники экселя и даже csv ответ

 *
 * Модель МОЖЕТ
 * 1. отдать данные по запросу и/или изменить данные в СУБД/файловой системе.
 * 2. сделать специфичные для бизнес логики проверки входных данных.
 * 3. сформировать ФАЙЛ (или бинарную переменную) и отдать контроллеру. Вывод в Эксель - это НЕ представление данных.
 *
 * Модели НЕ ДОЛЖНЫ ничего знать об авторизации (но может использовать аутентификацию через синглтон CurrentUser).
 *
 *
 *
 *
 *----------------------------------------------------------------------
 *
 *
 * Чтобы отрисовать отдельный элемент в AJAX или iframe в контроллере нужно установить disableRender().
 *
 * Для AJAX есть специальный метод controller->isAJAX() устанавливающий заголовок с кодировкой и отключающий рендер.
 * Для отдачи бинарников есть метод controller->isBINARY().
 * Для работы с JSON - вызываем controller->isJSON() и заполняем переменную $this->result (и всё) см. view->default_JSON_Method().
 *
 * Соглашение об именах в библиотеке:
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
#[\AllowDynamicProperties]
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

	/** список "базовых" моделей проекта
	 * для работы getBasicModel и магии __get на его основе в наследнике надо заполнить и поддерживать актуальный список базовых моделей */
	public array $basic_models_names = [];

	/** кеширование базовых моделей, дабы не плодить дубли.
	 */
	private array $__basic_models_cash = [];

	/** URI как для CGI так и CLI*/
	protected string $__request_uri;

	/** Полное название класса, каким мы пытались его создать по заданному URI */
	private string $__controller_fq_class_name;

/** Загрузчик Контроллера
 *
 *
 * */
	public function loadController(): bool
	{
		$fq_class_name = $this->__controller_fq_class_name;
		if (class_exists($fq_class_name))//тут запускается автозагружалка
		{
			$this->controller = new $fq_class_name();//делаем экземпляр класса
			return true;
		}
		else
		{
			return false;
		}
	}

/** Загрузчик Представления
 *
 * Представления работают только для UI режима и всегда лежат в namespace (и папке) views
 *
 * @return bool - загрузили мы класс или не смогли.
 * */
	public function loadView(): bool
	{
		$fq_class_name = 'Views\\'.(($this->module_name != '') ? $this->module_name.'\\' : '').$this->class_name;
		if (class_exists($fq_class_name))
		{
			$this->view = new $fq_class_name();
			return true;
		}
		else
		{//else и хрен бы с классом - может это AJAX вызов
			return false;
		}
	}

/** Разборщик URI - если проект не ложится в схему Модуль->Класс->Метод перекрываем этот метод и пишем в module_name, например, пустую строку.
 *
 * Формируем имя класса контроллера из разобранных module и class names
 *
 * Используется константа CONTROLLERS_BASE_PATH - controllers|api|cli в зависимости от типа вызова
 *
 *
/module1/sub_module2/class/method1
/module1/sub_module2/class
/module1/class/method1
/class/method1
/class
/
 *
 *
/module1/sub_module/sub_sub_module/class/method1
/module1/sub_module/sub_sub_module/class --  index method
/module1/sub_module/sub_sub_module -- index class + index method
/module1/sub_module/class -- index method
/module1/sub_module -- index class + index method
/module1/class/method1
/class/method1
/class -- index method
/
 * */
	public function parseURI(): void
	{
		$uri = explode('/', $this->__request_uri);
		$this->module_name = ($uri[0] != '') ? $uri[0] : $this->default_module_name;
		$this->class_name = (count($uri) > 1) ? $uri[1] : $this->default_class_name;
		$this->method_name = (count($uri) > 2) ? $uri[2] : $this->default_method_name;

		$this->__controller_fq_class_name = CONTROLLERS_BASE_PATH.'\\'.(($this->module_name != '') ? $this->module_name."\\" : '').$this->class_name;
	}

/** Главный метод приложения */
	public function run(): void
	{
		if (APPLICATION_RUNNING_MODE == 'cli')
		{//CLI - для этого режима первый параметр магическая строка в виде "модуль/класс/метод", потом все остальные параметры для программы
			$this->__request_uri = $_SERVER['argv'][1];
		}
		else
		{//WEB And API
			$this->__request_uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
		}
//разбираем URI
		$this->parseURI();//устанавливаем переменные module_name, class_name, method_name

//создали экземпляр контроллера - вызвали конструктор класса контроллера
		if (!$this->loadController())
		{//ну тогда программа закончена.
			$this->fileNotFound();
			return;//а чё ещё делать, если контроллер не нашелся. пусть программист сам ищет контроллер.
		}

//эти методы там просто устанавливают протектед поля
		$this->controller->setModuleName($this->module_name);
		$this->controller->setClassName($this->class_name);
		$this->controller->setMethodName($this->method_name);
//дефолтный ресурс для ACL - полное имя класса
		$this->controller->setDefaultResourceId($this->__controller_fq_class_name);

//инициализация класса ПОСЛЕ конструктора, но ПЕРЕД вызовом запрошенного метода.
//именно в этом методе можно проверять имя вызываемого метода и делать ACL по имени running_method_name
		$this->controller->init();

//вызвали нужный метод контроллера
		if (method_exists($this->controller, $this->method_name))
		{
			$this->controller->{$this->method_name}();//если такой метод есть
		}
		else
		{//если в проекте предусмотрены просто шаблоны (views) без контроллеров, то ничего не делаем, иначе там можно вывести ошибку.
			$this->controller->defaultMethod($this->method_name);//иначе вызываем дефолтный метод
		}

//для WEB режима идем дальше
//загрузили файл и создали представление - вызвали его конструктор
		if (APPLICATION_RUNNING_MODE == 'ui')
		{
			//если представление вообще загрузилось, то продолжаем.
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

//окончательные действия в контроллере, до его деструктора
		$this->controller->done();

//явный деструкт контроллера. пока, скорее, для красоты.
		unset($this->controller);
	}

/** Имитация глобальной переменной - коннектора к основной СУБД
 * в приложении наследнике перекрыть этот метод и в нем подключиться к базе и отдать коннектор типа \YAVPL\Db
 */
	public function getMainDBConnector(): \YAVPL\Db
	{
		die('У класса приложения должна быть реализована функция getMainDBConnector()');
	}

	public function getEntityTypesInstance()
	{
		die('У класса приложения должна быть реализована функция getEntityTypesInstance()');
	}

/** Получить экземпляр класса модели ("базовой", т.е. НЕ субмодели, т.е. сразу в папке с моделями - \models\)
 * вызовы кешируются в глобальной переменной приложения.
 *
 * Имеет смысл использовать в магических методах базовой модели \Models\Main проекта и главном контроллере проекта.
 *
 * Хорошая практика:
 * Все контроллеры должны иметь возможность "создать" (получить экземпляр класса) модель просто упомянув ее. Все модели тоже.
 *
 * @return Model возвращает экземпляр модели или NULL, если нет в списке.
 */
	public function getBasicModel(string $name): ?Model
	{
		$name = strtolower($name);
		if (in_array($name, $this->basic_models_names))
		{
			if (!isset($this->__basic_models_cash[$name]))
			{
				$model_name = "\\Models\\".$name;//базовые модели в пространстве \Models
				$model = new $model_name();
				$this->__basic_models_cash[$name] = $model;
			}
			return $this->__basic_models_cash[$name];
		}
		else
		{
			return null;
		}
	}

/** Обработчик ситуации когда не нашли Контроллер по URI */
	protected function fileNotFound(): void
	{//
		header("HTTP/1.0 404 Not Found");
		$message = "Cannot load appropriate controller [{$this->__controller_fq_class_name}] for URI [{$this->__request_uri}].
Why:
1. Mistyped URL
2. File with class not found (check autoloader)
3. Class was not defined in loaded file (check class name in file)";
		if (APPLICATION_RUNNING_MODE == 'api')
		{
			header("Content-type: application/json");
			print json_encode([
				'status'	=> 'Not found',
				'message'	=> preg_replace("/[\s]+/", " ", $message),
				'http_response_code'	=> 404,
			]);
		}
		else
		{
			print "<xmp>".$message."</xmp>";
		}
	}

/** DEPRECATED
 * Обработчик фатальный ситуаций - можно перекрыть, чтобы посылать их себе на почту */
	/*
	public function fatalError($msg)
	{
		print $msg."\n";
		exit(0);
	}*/

/** Автозагрузчик всего и вся */
	public static function __autoload(string $class_name): bool
	{
		// for DEBUGGING autoloader:
		//print "<p>Application::__autoload loading: {$class_name}</p>";//для отладки
		//__printBackTrace();//для отладки

		// свои библиотечные файлы проверяем первыми.
		// оно меняется раз в несколько лет. пусть лежит в виде массива прямо тут.
		if (preg_match("/^YAVPL\\\\/", $class_name))
		{
			$file_name = preg_replace("/YAVPL\\\\/", "", $class_name);
			if (in_array($file_name, [
				'Db', 'DbPg', 'DbPgSingleton', 'DbMy', 'DbTable',//СУБД
				'Mail',//Почта
				'SimpleDictionaryModel', //DEPRECATED
				'Model', 'SimpleFilesModel', 'BasicUserModel', 'DocumentModel',//модельки
				'Controller', 'ControllerAPI', 'View', 'Helper', //ядро фреймворка
				'ToolBar', //тулбар - библиотека
				'Test',//DEPRECATED
			]))
			{
				//da("Loading YAVPL file: $s");
				require_once($file_name.'.php');
				return true;
			}
		}

		//da("Classname ".$class_name);//DEBUG
		$s = explode('\\', strtolower($class_name));
		$file_name = join('/', $s).".php";
		//da($s);			da("file_name = ".$file_name);
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