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
 * DATE: 2025-01-15
 * Рефакторинг под case-sensitive УРЛы проекта cloudstorage
 *
 * public function parseURI(): void переделана в public function routeToClassName(): string - возвращает имя класса.
 * теперь загружалки контроллера и представления сами подставляют префиксы к классу
 *
 *
 *
 * DATE: 2023-05-27
 * убран fatalError
 * fileNotFound теперь понимает режим JSON
 * добавлен вызов метода init контроллера сразу после создания класса контроллера и заполнения его полей типа $this->running_method_name
 *
 */

//приложение работает в одном из 3х режимов: веб интерфейс WebUI, API и CLI
if (!defined('APPLICATION_RUNNING_MODE') || trim(APPLICATION_RUNNING_MODE) == '')
{
	define('APPLICATION_RUNNING_MODE', 'WebUI');//режим по-умолчанию
}

if (!defined('CONTROLLERS_BASE_PATH'))
{
	//тут выясняем, где брать контроллеры
	if (APPLICATION_RUNNING_MODE == 'API')
	{//нет представления, контроллер всегда отдает JSON или файл
		define('CONTROLLERS_BASE_PATH', 'api');
	}
	elseif (APPLICATION_RUNNING_MODE == 'CLI')
	{//режим командной строки - нет пользовательской сессии, все делается от администратора.
		define('CONTROLLERS_BASE_PATH', 'cli');
	}
	elseif (APPLICATION_RUNNING_MODE == 'WebUI')
	{//режим веб-интерфейса - работает всё, выдаем HTML или что решит контроллер
		define('CONTROLLERS_BASE_PATH', 'controllers');
	}
	else
	{//а всякую дичь отметаем сразу
		die('Wrong APPLICATION_RUNNING_MODE: '.APPLICATION_RUNNING_MODE);
	}
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
		if (APPLICATION_ENV == 'production')
		{
			print "<h1>Фатальная ошибка:</h1> <xmp>{$error['message']}</xmp>";
		}
		sendBugReport('FATAL ERROR', "Fatal error: {$error['message']}\nfile: {$error['file']}, line :{$error['line']}");
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

	$user_message = "<h1>Фатальная ошибка.</h1>
<h2>Письмо администратору будет отправлено, но можно сообщить ему лично вместе с нижеследующим сообщением:</h2>
<h2>{$message}</h2>
<h3>Пожалуйста, делая скриншот или копируя текст ошибки, по возможности не переводите сообщение об ошибке с английского на русский язык - это затрудняет поиск источника ошибки.</h3>";
	if (APPLICATION_ENV != 'production')
	{
		$user_message .= "<div>BACKTRACE\n<xmp>".__getBacktrace()."</xmp></div>";
	}
	if (1|| APPLICATION_RUNNING_MODE == 'WebUI')
	{
		print $user_message;
	}
	sendBugReport('FATAL exception', $message);
}
set_exception_handler('\\YAVPL\\__my_exception_handler');

/** Класс "Приложение"
 *
 * Порядок работы приложения:
 *
 * 1. контроллер
 * в приложении создается контроллер, вызывается его конструктор (а в нем и все его предки по желанию)
 * конроллеру устанавливаются поля $this->running_method_name (а ткуже модуль и класс)
 * вызывается метод контроллера init()
 * вызывается запрошенный метод контроллера
 *
 * 2. представление
 * в приложении создается представление ($application->view = new XXYYView())
 * вызывается метод представления render()
 * метод run приложения вызывает либо view->render (обертка), либо сразу запрошенный метод из view, если рендер заблокирован
 * view->render печатает в вывод стандартную обертку для HTML файла (тэг <html>, заголовки <head> и тэг <body>)
 * и вызывает метод view->body($method_name) внутри тэга <body>
 * view->body должен быть переопределен где-то в наследниках View (как правило в defaultView проекта) и
 * отрисовывает обертку конкретного проекта (шапку, меню, футер и т.п.) и где-то среди нее вызывает this->$method_name
 * !!! представление имеет доступ ко всем полям класса вызвавшего его контроллера через магию __get
 *
 * 3. вызывается метод done() контроллера и экземпляру контроллера делается unset()
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
 * 3. формировать бинарники экселя и даже csv ответ. и вообще - представление только в HTML

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
 * 		исключения, например, для ACL - можно в представлении делать if ($this->user->isAllowed('resource_id')) {print "...";}
 * * и вообще, представление как бы ничего не знает о моделях. но иногда влом объявлять в контроллере shortcut
 * 		на какую-нибудь структуру в модели только для того, чтобы ее один раз показать в каком-нибудь селекторе
*/

#[\AllowDynamicProperties] /* Весь этот фреймворк и стиль кодирования подразумевает динамические свойства и без них ничего не работает.*/
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

	/** список "базовых" моделей проекта (т.е. список "разделов" или "модулей" проекта)
	 * для работы getBasicModel и магии __get на его основе в наследнике надо заполнить и поддерживать актуальный список базовых моделей */
	public array $basic_models_names = [];

	/** кеширование базовых моделей, дабы не плодить дубли.
	 */
	private array $__basic_models_cash = [];

	/** URI как для CGI так и CLI*/
	protected string $__request_uri;

	/** Полное название класса, каким мы пытались его создать по заданному URI */
	protected string $__fq_class_name;

/** Загрузчик Контроллера
 * Создает экземпляр класса. Класс грузится автолоадером.
 * Используется константа CONTROLLERS_BASE_PATH - controllers|api|cli (WebUI|API|CLI) в зависимости от типа вызова.
 * @return bool - загрузили мы класс или не смогли.
 */
	public function loadController(): bool
	{
		$fq_class_name = CONTROLLERS_BASE_PATH.'\\'.$this->__fq_class_name;
		if (class_exists($fq_class_name))//тут запускается автозагружалка
		{
			//присвоение $this->controller теперь в протоконструкторе контроллера, т.к. для error() надо
			//иметь инициализированную $application->controller уже в конструкторе класса любого контроллера, чтобы вызвать loadView
			//НЕ НАДО ТАК! $this->controller = new $fq_class_name();//
			new $fq_class_name();//делаем экземпляр класса автолоадером

			return true;
		}
		else//по идее - фаталити, т.к. куда же без класса контроллера. т.е. хотябы пустой класс контроллера должен быть.
		{
			return false;
		}
	}

/** Загрузчик Представления
 * Представления работают только для UI режима и всегда лежат в namespace (и папке) views
 * @return bool - загрузили мы класс или не смогли.
 * */
	public function loadView(): bool
	{
		$fq_class_name = 'Views\\'.$this->__fq_class_name;
		if (class_exists($fq_class_name))
		{//ссылку вида $this->view = new $fq_class_name() можно и тут поставить, но пусть будет для красоты как с контроллером.
			new $fq_class_name();//грузим автолоадером. в конструкторе ставим ссылки из application на этот view и в view на контроллер приложения
			return true;
		}
		else
		{//else и хрен бы с классом - может это AJAX вызов
			return false;
		}
	}

/** Разборщик URI - т.е. роутер. Здесь формируется имя класса из URI.
 * По умолчанию URL проект состоит из 3 компонентов Модуль/Класс/Метод
 *
 * Если проект не ложится в схему Модуль->Класс->Метод перекрываем этот метод и пишем в module_name, например, пустую строку, если в проекте нет модулей.
 * Если нужно ЧПУ, то перекрываем метод и делаем вообще всё, что угодно.
 *
 * @return string Формируем имя класса контроллера из разобранных module и class names и возвращаем его.
 */
	public function routeToClassName(): string
	{
		$uri = explode('/', $this->__request_uri);
		$this->module_name = ($uri[0] != '') ? $uri[0] : $this->default_module_name;
		$this->class_name = (count($uri) > 1) ? $uri[1] : $this->default_class_name;
		$this->method_name = (count($uri) > 2) ? $uri[2] : $this->default_method_name;

		return (($this->module_name != '') ? $this->module_name."\\" : '').$this->class_name;
	}

/** Главный метод приложения */
	public function run(): void
	{
//получаем запрос модуля-класса-метода или какого-то алиаса для какого-то класса
		if (APPLICATION_RUNNING_MODE == 'CLI')
		{//CLI - для этого режима первый параметр магическая строка в виде "модуль/класс/метод", потом все остальные параметры для программы
			$this->__request_uri = $_SERVER['argv'][1];
		}
		else
		{//WEB или API запрос
			$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
			if (empty($path))//On seriously malformed URLs, parse_url() may return false.
			{
				$path = '';
			}
			$this->__request_uri = trim($path, '/');
		}

//разбираем URI и формируем название класса контроллера
		$this->__fq_class_name = $this->routeToClassName();//устанавливаем переменные module_name, class_name, method_name и названия классов контроллера и представления

//создаем экземпляр контроллера - вызвали конструктор класса контроллера
		if (!$this->loadController())
		{//ну тогда программа закончена.
			$this->fileNotFound();
			return;//а чё ещё делать, если контроллер не нашелся. пусть программист сам ищет контроллер.
		}

//эти методы устанавливают protected поля. для чего их использовать - см. комментарии в контроллере (\YAVPL\Controller)
		$this->controller->setModuleName($this->module_name);
		$this->controller->setClassName($this->class_name);
		$this->controller->setMethodName($this->method_name);

//дефолтный ресурс для проектов с ACL - полное имя класса
		$this->controller->setDefaultResourceId($this->__fq_class_name);

//"инициализация" класса ПОСЛЕ конструктора, но ПЕРЕД вызовом запрошенного метода.
//именно в этом методе можно проверять имя вызываемого метода и делать ACL по имени running_method_name или проверять доступ к ресурсу
		$this->controller->init();

//вызываем нужный метод контроллера
		if (method_exists($this->controller, $this->method_name))
		{//если такой метод есть
			$this->controller->{$this->method_name}();
		}
		else
		{//если в проекте предусмотрены просто шаблоны (views) без контроллеров, то ничего не делаем, иначе там можно вывести ошибку.
			$this->controller->defaultMethod($this->method_name);//иначе вызываем дефолтный метод
		}

//для WEB режима идем дальше
//загрузили файл и создали представление - вызвали его конструктор
		if (APPLICATION_RUNNING_MODE == 'WebUI')
		{
			//если представление вообще загрузилось, то продолжаем.
			if ($this->loadView())//загрузили сам класс
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
						sendBugReport("Установлен __need_render, но в представлении нет метода render()", "FATALITY!", true);
					}
				}
				else
				{//если Аджакс с отрисовкой через View, рисуем сразу нужный метод без оберток
					if (method_exists($this->view, $this->method_name))
					{
						$this->view->{$this->method_name}();
					}
					else
					{// сюда попадаем, если есть аджаксовый код, но без представления.
					 // скорее всего, контроллер сам отдал данные в виде файла или JSON
					 // хотя еще может быть JSON:
						if ($this->controller->__is_json)//а если прямо явно указано, что это JSON
						{
							if (isset($this->controller->result))//как правило это структура типа хеш
							{//просто выводим ее в ответ
								print json_encode($this->controller->result, JSON_UNESCAPED_UNICODE);
							}
							elseif (//нет результатов, но есть сообщение или логи
								(($this->controller->message ?? '') != '') ||//просто сообщение с логами
								(count($this->controller->log ?? []) > 0)
								)
							{
								print json_encode(['message' => $this->controller->message ?? '', 'log' => ($this->controller->log ?? [])], JSON_UNESCAPED_UNICODE);
							}
							else
							{//надо выдать хоть что-то, а то непонятно, зачем мы все это делали
								sendBugReport("Вызов в режиме isJSON(), но контроллером не заполнены ни \$result, ни \$message, ни \$log", "FATALITY!", true);
							}
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

//явный деструкт контроллера. скорее, для красоты, т.к. это фактический конец исполнения программы
		unset($this->controller);
	}

/** Имитация глобальной переменной - коннектора к основной СУБД.
 * в приложении-наследнике перекрыть этот метод и в нем подключиться к базе и отдать коннектор типа \YAVPL\Db
 */
	public function getMainDBConnector(): \YAVPL\Db
	{
		die('У класса приложения должна быть реализована функция getMainDBConnector()');
	}

/** Ссылка на хранилище сущностей. Для сложных проектов с таким хранилищем.
 */
	public function getEntityTypesInstance()
	{
		die('У класса приложения должна быть реализована функция getEntityTypesInstance()');
	}

/** Получить экземпляр класса модели ("базовой" (например, модель типа "раздел"), т.е. НЕ субмодели, т.е. сразу в папке с моделями - \models\)
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
		if (in_array(strtolower($name), $this->basic_models_names))
		{//путь в нижнем регистре
			$name = strtolower($name);
		}
		elseif (in_array($name, $this->basic_models_names))
		{//путь с учетом регистра - ничего не делаем
		}
		else
		{//никак нет - выходим отсюда
			return null;
		}

		//нашли модель в списке
		if (!isset($this->__basic_models_cash[$name]))//нет в кеше моделей
		{
			$model_name = "\\Models\\".$name;//базовые модели в пространстве \Models
			$model = new $model_name();//модель грузится автолоадером
			$this->__basic_models_cash[$name] = $model;//сохраняем в кеш
		}
		return $this->__basic_models_cash[$name];//отдаем из кеша
	}

/** Обработчик ситуации когда не нашли Контроллер по URI */
	protected function fileNotFound(): void
	{//
		header("HTTP/1.0 404 Not Found");
		$message = "Cannot load appropriate controller class [".CONTROLLERS_BASE_PATH."\\{$this->__fq_class_name}] for URI [{$this->__request_uri}].
Why:
1. Mistyped, malformed or outdated URL (check URL in your browser)
2. File with class not found (check autoloader)
3. Class was not defined in loaded file (check class name in file)";
		if (APPLICATION_RUNNING_MODE == 'API')
		{
			header("Content-type: application/json");
			print json_encode([
				'status'				=> 'Not found',
				'message'				=> preg_replace("/[\s]+/", " ", $message),
				'http_response_code'	=> 404,
			]);
		}
		else
		{
			print "<xmp>{$message}</xmp>";
		}
	}

/** Автозагрузчик всего и вся */
	public static function __autoload(string $class_name): void
	{
		// for DEBUGGING autoloader:
		//print "<p>".__METHOD__." loading: [{$class_name}]</p>";//для отладки
		//__printBackTrace();//для отладки

		// свои библиотечные файлы проверяем первыми.
		// оно меняется раз в несколько лет. пусть лежит в виде массива прямо тут.
		if (preg_match("/^YAVPL\\\\/", $class_name))
		{
			$file_name = preg_replace("/YAVPL\\\\/", "", $class_name);
			if (in_array($file_name, [
				'Db', 'DbPg', 'DbPgSingleton', 'DbTable',//СУБД
				'Mail',//Почта
				'Model', 'SimpleFilesModel', 'BasicUserModel', 'DocumentModel',//модельки
				'Controller', 'ControllerWebUI', 'ControllerAPI', 'ControllerCLI', 'View', 'Helper', //ядро фреймворка
				'ToolBar', //тулбар - библиотека
			]))
			{
				//da("Loading YAVPL file: {$file_name}");
				require_once($file_name.'.php');
				return;
			}//else - нам чего-то не того подложили в папку с библиотекой и хотят запустить. нуихнафиг.
		}

		//print "<xmp>class_name = $class_name\n";


//---- дефолтное поведение для старых проектов - файлы лежат в пути в нижнем регистре
		$s = explode('\\', $class_name);
		$file_name = APPLICATION_PATH.'/'.strtolower(join('/', $s)).".php";
		if (file_exists($file_name))
		{
			require $file_name;
			return;
		}

//---- костыли на переходный период - каталоги controllers,api,models,views в нижнем регистре, а файлы регистрозависимы
		$s = explode('\\', $class_name);
		$catalog_name = array_shift($s);
		$file_name = APPLICATION_PATH.'/'.strtolower($catalog_name).'/'.join('/', $s).".php";
		if (file_exists($file_name))
		{
			require $file_name;
			return;
		}

		$s = explode('\\', $class_name);
		$catalog_name = array_shift($s);
		$sub_catalog_name = array_shift($s);
		$file_name = APPLICATION_PATH.'/'.strtolower($catalog_name).'/'.strtolower($sub_catalog_name).'/'.join('/', $s).".php";
		if (file_exists($file_name))
		{
			require $file_name;
			return;
		}


//----- возможное поведение для новых проектов - все пути регистрозависимы вплоть до имени файла.
		$s = explode('\\', $class_name);
		$file_name = APPLICATION_PATH.'/'.join('/', $s).".php";
		if (file_exists($file_name))
		{
			require $file_name;
			return;
		}
//-------------------------------------------------------------------------------------
	}
}