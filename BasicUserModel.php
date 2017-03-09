<?php
/**
 * @NAME: BasicUserModel
 * @DESC: Prototype for users models
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2009- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** CHANGELOG

 * 1.01
 * DATE: 2017-03-07


 * 1.00
 * DATE: 2017-02-23
 * создание универсального класса для пользователей
*/

/** Концепция.
 *
 * В каждом проекте, где есть юзеры, надо создать класс UserModel и, как минимум, перекрыть конструктор, чтобы
 * заполнить параметры таблицы с пользователями (название, первичный ключ, список полей и всякие тонкости)
class UserModel extends BasicUserModel
{
    function __construct()
	{
		parent::__construct([
			'table_name'	=> 'public.users',
			'key_field'		=> 'id',
			'fields'		=> [name, email, password],
			'options'		=> [],
		]);
	}
}
 *
 * Работа с синглтоном (как правило, в дефолтном контроллере проекта):
 * $this->user = UserModel::getCurrentInstance();
 *
 * Тонкости:
 * Все селекты делают выборку ВСЕХ полей, поэтому стоит избегать хранить в таблице с юзерами, что-то тяжелое и
 * не_всегда_нужное. Картинки, логи и т.п. надо хранить в отдельных связанных таблицах.
 */

// @TODO: на фиг не надо тут эти 2 константы, т.к. используется это дело строго 1 раз в жизни.
// оставим на подумать.
define('BASIC_USER_TABLE_NAME', 'public.users');
define('BASIC_USER_TABLE_KEY_FIELD', 'id');

// без этой константы авторизация не работает от слова вообще.
// без нее много чего еще не работает (например сессии), так что она должна быть.
if (!defined('COOKIE_DOMAIN'))
{
	$msg = 'COOKIE_DOMAIN was not defined!';
	sendBugReport($msg);
	die($msg);
}

/** DumpArray in temp File - специально для отладки кукиев и сессий
 */
function daf($v)
{
	if ($_SERVER['APPLICATION_ENV'] != 'production')
	{
		$l = fopen('/tmp/'.$_SERVER['SERVER_NAME'].'__'.date('Y_m_d__H_i_s').'.log', 'a+');
		//fwrite($l, "\n---".date('Y-m-d H:i:s')."\n".var_export($v, true)."\n");
		fwrite($l, var_export($v, true)."\n");
		fclose($l);
	}
}

class BasicUserModel extends SimpleDictionaryModel
{
	// нужен для реализации шаблона Singleton
	private static $current_instance;

	// 0 - anonymous, запись может физически присутствовать в базе! Например, под именем Anonymous.
	// и как правило она там и должна присутсвовать, т.к. должен быть внешний ключ на таблицу юзеров с таблицу логов активностей.
	public $id = 0;

	// данные из select * по юзеру. использовать: $this->user->data['name_r'], например.
	public $data;

	// дефолтные настройки проекта. всё, что не нравится, надо указать явно при вызове конструктора.
	protected $options = [
		'autologin_field_name'			=> 'autologin',
		'autologin_cookie_name'			=> 'autologin',
		'autologin_cookie_ttl'			=> 60*60*24*365,// 1 год
		'autologin_is_always_new'		=> false,
		'logout_magic_string'			=> 'logout',
		'auth_by_login_and_password'	=> false,
		'write_all_activities'			=> true,
		'settings_field_name'			=> 'settings',
		'settings_cookie_name'			=> 'settings',
		'settings_cookie_ttl'			=> 60*60*24*365,// 1 год
	];


/** Формат валидатора:
 * для каждого ключа массив из от 1 до 3 элементов: тип, дефолтное значение, список допустимых значений
 * если не указаны дефолтные значения, то для целого это 0, для строки - ''
 * если указано дефолтное значение и список допустимых значений и при этом в списке нет дефолного значения - будет die() на этапе валидации.
 * такой случай - ошибка программиста и исправлять ее надо сразу.
 * если значение не входит в список допустимых - оно молча преобразуется в дефолтное.
 *
 * допустимые типы данных: integer/double/float/string
 *
 * $this->valid_settings = [
 * 	'key1'	=> ['integer', 0, [0,1,2]], //целое, варианты 0,1,2
 *  'key2'	=> ['string', 'qwqw'], //строка, по дефолту 'qwqw', принимает любые значения
 *  'key2a'	=> ['string'], //строка, по дефолту ''
 *  'key2b'	=> ['string', '', ['aa', '', 'cccc'], //строка, по дефолту пустая, варианты из списка 'aa', '', 'cccc'
 *  'key2e'	=> ['string', 'qq', ['aa', '', 'cccc'], //ошибка, дефолтное значение не входит в список допустимых
 * ];
 */
	// наследники сами объявляют массив валидных настроек.
	protected $valid_settings = [];

/**
 * Конструктор берет массив параметров.
 * Можно не указывать параметры - название таблиц и название поля PK этой таблицы, по дефолту PK - public.users.id
 * Параметр fields - обязателен, перечисление полей описывающих юзера
 * В целом, общим для разных проектов является только id, остальное может отличаться (name против name_r/name_e)
 */
    function __construct(array $params = [])
	{
		$params['table_name'] = isset($params['table_name']) ? $params['table_name'] : BASIC_USER_TABLE_NAME;
		$params['key_field'] = isset($params['key_field']) ? $params['key_field'] : BASIC_USER_TABLE_KEY_FIELD;
		$params['fields'] = isset($params['fields']) ? $params['fields'] : [];//в худшем случае будет таблица из одного PK
//предок - простой словарик, отдаем ему таблицу, ПК и поля
		parent::__construct($params['table_name'], $params['key_field'], $params['fields']);

//опции для параметров работы класса, которые отличаются от дефолтных
		foreach ($this->options as $k => $v)
		{
			$this->options[$k] = isset($params['options'][$k]) ? $params['options'][$k] : $this->options[$k];
		}
//??? а оно тут надо всегда???
//проверки в зависимости от опций
		if ($this->options['auth_by_login_and_password'])
		{
			if (!in_array('login', $this->fields)) die ('auth_by_login_and_password required field login');
			if (!in_array('password', $this->fields)) die ('auth_by_login_and_password required field password');
		}
	}

/** Просто shortcut.
 */
	public function is($id)
	{
		return ($this->id == $id);
	}

/** Максимально быстрая проверка наличия юзера в таблице.
 * Кто знает как быстрее, перекрывает метод :)
 * Технически, это получение экземпляра PK прямо из индекса, без обращения к данным. Быстрее уж некуда.
 */
	public function exists($id)
	{
		return $this->db->exec("-- ".get_class($this).", method: ".__METHOD__."
SELECT {$this->key_field} FROM {$this->table_name} WHERE {$this->key_field} = $1", $id)->rows == 1;
	}

/** Получить строку данных по уникальному полю (код авторизации, логин, почта, телефон и т.п.)
 */
	public function getRowByUniqueField($field_name, $value)
	{
		if (!in_array($field_name, $this->fields)) die(__METHOD__." requires field $field_name");
		return $this->db->exec("SELECT * FROM {$this->table_name} WHERE {$field_name} = $1", $value)->fetchRow();
	}

/** Получить ID по уникальному полю (код авторизации, логин, почта, телефон и т.п.)
 */
	public function getIdByUniqueField($field_name, $value)
	{
		if (!in_array($field_name, $this->fields)) die(__METHOD__." requires field $field_name");
		$this->db->exec("SELECT {$this->key_field} FROM {$this->table_name} WHERE {$field_name} = $1", $value);
		//проверка строго на 1, т.к. это уникальное поле. если в базе это поле не уникально, то нефиг им пользоваться для получения ID
		if ($this->db->rows == 1)
		{
			list($id) = $this->db->fetchRowArray();
		}
		else
		{//не нашли. почему именно false: 0 это валидный аноним, -1 это извращение, IMHO.
			$id = false;
		}
		return $id;
	}

/** Получить ID юзера по логину и незашифрованному паролю. Пароль в базе зашифрован и во время этой проверки закже шифруется.
 * Кому этого мало - перекрывают метод и шифруют пароли, как хотят.
 */
	public function getIdByLoginAndPassword($login, $password)
	{
		if (!$this->options['auth_by_login_and_password']) die(__METHOD__.' Login by login&password is not supported.');

		$this->db->exec("-- ".get_class($this).", method: ".__METHOD__."
SELECT {$this->key_field} FROM {$this->table_name} WHERE login = $1 AND password = $2", $login, md5($password));
		if ($this->db->rows == 1)
		{
			list($id) = $this->db->fetchRowArray();
		}
		else
		{
			$id = 0;
		}
		return $id;
	}

/** Начать новую сессию пользователя. ID берется после проверки логина/пароля/OAuth/смс с кодом - как угодно.
 * В любом случае, приходим сюда и стартуем сессию.
 * Вызываем из всех скриптов типа login, после успешной проверки ID юзера.
 * Метод просто стартует сессию, ничего больше не проверяя!!!
 */
	public function createCurrentSession($id)
	{
		daf(__METHOD__);
		if (!isset($_SESSION)){ session_start();}
		$this->id = $id;
		$_SESSION['log_id'] = $id;
		session_write_close();//to avoid locking
		$this->loadCurrentData();
		daf("session started id = $id");
		daf($this->data);
		$this->setAutologin();
		$this->notifyOnCreateCurrentSession();
	}

/** Продолжить текущую сессию пользователя.
 * Если есть PHP сессия - работаем с ее данными (log_id), если ее нет, то пытаемся авторизоваться по секретному ключу из
 * куки с автологином.
 * Вызывается, как правило, из синглтона.
 */
	public function continueCurrentSession()
	{
		daf(__METHOD__);

		if (!isset($_SESSION)){ session_start();}
		$this->id = 0;//аноним

		daf('session');daf($_SESSION);daf('cookies');daf($_COOKIE);
		if (isset($_SESSION['log_id']) && intval($_SESSION['log_id']) > 0)
		{
			$this->id = $_SESSION['log_id'];
			daf('continue old session with user id = '.$this->id);
			$this->loadCurrentData();
		}
		elseif (isset($_COOKIE[$this->options['autologin_cookie_name']]))
		{
			daf('continue on cookies');
			$id = $this->getIdByUniqueField($this->options['autologin_field_name'], $_COOKIE[$this->options['autologin_cookie_name']]);
			if ($id !== false)
			{
				daf('found id '.$id. ' by cookie '.$_COOKIE[$this->options['autologin_cookie_name']]);
				if (!isset($_SESSION)){ session_start();}
				$this->id = $_SESSION['log_id'] = $id;
				session_write_close();//to avoid locking
				$this->loadCurrentData();
				daf('got user id from cookies '.$this->id);
				$this->notifyOnContinueCurrentSession();
			}
			else
			{
				$this->destroyCurrentSession();
			}
		}
		else
		{
			$this->destroyCurrentSession();
		}
		daf('user data after attempts to get user ID');
		daf($this->data);

		$this->setAutologin();//just extend cookie's time

		//нашли юзера под номером $this->id ?
		if ($this->id > 0)
		{
			if (($this->data === false) || //какой-то неправильный юзер
				//а не force logout случай?
				(strpos($this->data[$this->options['autologin_field_name']], $this->options['logout_magic_string'])))//logout. magic string. set in UserModel->forceLogout
			{//LOG OUT
				$this->destroyCurrentSession();
			}
		}// else - анонимов не трогаем

		//не забанили ещё?
		if (!$this->isUserAllowedToLogin())
		{
			$this->notifyOnBannedUserLoggedIn();
			$this->destroyCurrentSession();
		}

		//пишем в лог независимо от результатов авторизации!
		$this->writeActivityLog();
	}

/** Закончить текущую сессию. Все вариатны log-out приводят сюда.
 */
	public function destroyCurrentSession()
	{
		daf(__METHOD__);
//стираем куки
		setcookie($this->options['autologin_cookie_name'], '', time() - 3600, '/', COOKIE_DOMAIN);
//останавливаем текущую сессию
		if (!isset($_SESSION)){ session_start();}
		unset($_SESSION['log_id']);
		session_write_close();

//обнуляем юзера до анонима
		$this->id = 0;
//грузим данные и настройки для анонимуса.
		$this->loadCurrentData();
		$this->notifyOnDestroyCurrentSession();
	}

/** Разлогинить все открытые сессии, не допустить автологина больше нигде.
 */
	public function forceLogOut()
	{
		if ($this->id > 0)
		{
			//пишем в базу магическую строку, т.е. автологин теперь невозможен, а текущие сессии закроются в самом старте
			$this->db->update($this->table_name, $this->key_field, $this->options['autologin_field_name'],
				[
					$this->key_field => $this->id,
					$this->options['autologin_field_name'] => $this->options['logout_magic_string'].'--'.$this->data[$this->options['autologin_field_name']],
				]);

			$this->destroyCurrentSession();
		}
	}

/** Устанавливает автологиновый ключ в куку и в базу.
 * Чтобы только продлить куку или залогинить юзера на другом устройстве,
 * надо сначала получить старый ключ, а потом его же и поставить.
 */
	public function setAutologin()
	{
		daf(__METHOD__);
		if ($this->id > 0)
		{
			$autologinkey = $this->data[$this->options['autologin_field_name']];
			//da('$autologinkey from base:');
			//da($autologinkey);
			if ($autologinkey == '')
			{
				$autologinkey = $this->generateNewAutologin();
				$this->db->update($this->table_name, $this->key_field, $this->options['autologin_field_name'],
					[$this->key_field => $this->id, $this->options['autologin_field_name'] => $autologinkey]);
			}
			setcookie($this->options['autologin_cookie_name'], $autologinkey, time() + $this->options['autologin_cookie_ttl'], '/', COOKIE_DOMAIN);
		}//else ну какой у анонима автологин?
		return $this;
	}

/** Тут можно послать письмо
 */
	public function notifyOnCreateCurrentSession()
	{
	}

/** Тут можно послать письмо
 */
	public function notifyOnContinueCurrentSession()
	{
	}

/** Тут можно послать письмо
 */
	public function notifyOnDestroyCurrentSession()
	{
	}

/** Тут можно послать письмо
 */
	public function notifyOnBannedUserLoggedIn()
	{
	}

/** Юзер не забанен?
 * Надо перекрыть метод и в нем проверять забаненных юзеров.
 * Предка не вызываем!!!
 */
	public function isUserAllowedToLogin()
	{
		return true;
	}

/** Записать текущую активность в лог.
 * Недовольные набором полей перекрывают метод и не вызывают предка.
 *
 */
	public function writeActivityLog()
	{
/**
 * прототип таблицы с логами активности юзеров
CREATE TABLE public.user_activity_logs
(
  id serial NOT NULL,
  user_id integer NOT NULL,
  ts timestamp without time zone NOT NULL DEFAULT now(),
  uri text,
  referer text DEFAULT ''::text,
  user_agent text DEFAULT ''::text,
  ip character varying(16),
  CONSTRAINT user_activity_logs_pkey PRIMARY KEY (id),
  CONSTRAINT user_activity_logs_users FOREIGN KEY (user_id)
      REFERENCES public.users (id) MATCH SIMPLE
      ON UPDATE RESTRICT ON DELETE RESTRICT
);
*/
		if ($this->options['write_all_activities'])
		{
			$this->db->insert('public.user_activity_logs', 'id', $this->key_field.',uri,referer,user_agent,ip', [
				$this->key_field	=> $this->id,
				'uri'				=> isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
				'referer'			=> isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '',
				'user_agent'		=> isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
				'ip'				=> defined('REMOTE_ADDR') ? REMOTE_ADDR : '-',
			]);
		}
		return $this;
	}

/** Если не устраивает SELECT * по PK, перекрывают и далеют сложные селекты, грузят картинки и пр.
 * Должно работать быстро, т.к. делается при каждом посещении каждой страницы.
 */
	public function loadCurrentData()
	{
		daf(__METHOD__);
		//загружаем данные юзера в память их СУБД.
//@TODO: тут же можно грузить какие-то данные из кукиёв, а можно отдать это наследникам.
		if ($this->id > 0)
		{
			$this->data = $this->getRow($this->id);
		}
		else
		{
			$this->data = (isset($_COOKIE['user_data'])) ? unserialize(stripslashes($_COOKIE['user_data'])) : [];
		}
		//if (($this->data === false) && ($this->id == 0)) die("You must create an anonymous user with ID == 0");
		//загружаем настройки юзера - нормальному юзеру из базы, анониму из кукиёв.
		$this->loadSettings();
		return $this;
	}

/** Можно перекрыть этот метод, если автологин требуется привязать к паролю, например.
 * И при этом отвязать от адреса/времени, т.е. получать всё время один и тот же автологин для одного и того же юзера (пароля/логина)
 * По умолчанию, автологин случайный каждый раз.
 */
	public function generateNewAutologin()
	{
		return md5(time().$_SERVER['REMOTE_ADDR']);
	}

/** Загружаем настройки из сессии или кук, в зависимости от юзер или аноним.
 * Настройки тут же валидируем.
 * Вызывается один раз при старте или продолжении сессии.
 */
	public function loadSettings()
	{
		$settings_field_name = $this->options['settings_field_name'];
		$settings_cookie_name = $this->options['settings_cookie_name'];

		if ($this->id > 0)
		{
			$this->settings = (isset($this->data[$settings_field_name])) ? unserialize($this->data[$settings_field_name]) : [];
		}
		else
		{
			$this->settings = (isset($_COOKIE[$settings_cookie_name])) ? unserialize(stripslashes($_COOKIE[$settings_cookie_name])) : [];
		}
		//и сильно мы так сэкономим память?
		if (isset($this->data[$settings_field_name]))
		{
			unset($this->data[$settings_field_name]);
		}

		//Пропускаем через себя массив настроек юзера.
		$tmp = [];
		foreach ($this->valid_settings as $key => $options)
		{//валидатор сам берез проверяемое значение из $this->settings по ключу $key
			$tmp[$key] = $this->validateSettings($key, $options);
		}
		$this->settings = $tmp;
		return $this;
	}

/** Нужен в validateSettings()
 */
	protected function verifyDefaultValue($type)
	{
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
		return $default_value;
	}

/** Валидируем конкретную настройку.
 * Все настройки, которых нет в валидаторе пропадают.
 * Все значения, которые не входят в список допустимых становятся дефолтными.
 * Описание валидатора смотри в блоке объявлений этого класса.
 * Наследникам с другим форматом валидатора надо полностью перекрыть этот метод и не беспокоить предка.
 * $options - массив [тип, дефотное значение, список допустимых значений]
 */
	protected function validateSettings($key, $options = null)
	{
		// при переборе опции передаются сразу, при проверки одной строки вытягиваем их из валидатора
		$options = isset($options) ? $options : $this->valid_settings[$key];
		// с типами все жестко. он должен быть указан.
		$type = isset($options[0]) ? $options[0] : die("Unknown type for settings key: \"$key\"");
		// вычисляем сразу дефолтное значение. оно нам надо тут в двух местах.
		$default_value = (isset($options[1])) ? $options[1] : $this->verifyDefaultValue($type);

		if (isset($this->settings[$key]))
		{// есть проверяемое значение
			if (isset($options[2]) && is_array($options[2]) && count($options[2]) > 0)
			{// есть список допустимых значений
				$result = (in_array($this->settings[$key], $options[2])) ? $this->settings[$key] : $default_value;
			}
			else
			{// нет списка - ничего не проверяем
				$result = $this->settings[$key];
			}
		}
		else
		{// если не передали значение, то устанавливаем дефолтное из валидатора, либо дефотное к типу
			$result = $default_value;
		}
		return $result;
	}

/** Изменить отдельную настройку. Меняем данные в памяти и пишем в базу или в куку.
 */
	public function changeSettings($key, $val)
	{
		if (!isset($this->valid_settings[$key]))
		{//фаталити. надо сразу звать программиста. где-то ставится настройка, которой нет в валидаторе.
			sendBugReport('attempt to change settings was invalid', "INVALID KEY OR VAL!\nKEY='$key' VALUE='$val'\n\n".print_r($this, true));
			die("your attempt to change settings was invalid. KEY='$key' VALUE='$val'");
		}
		//устанавливаем настройку
		$this->settings[$key] = $val;
		//проверяем ее валидатором
		$this->validateSettings($key);
		//все, что после валидатора останется, мы пишем в хранилище

		if ($this->id > 0)
		{//живых пишем в базу
			$settings_field_name = $this->options['settings_field_name'];
			$this->db->update($this->table_name, $this->key_field, $settings_field_name,
				[$this->key_field => $this->id, $settings_field_name => serialize($this->settings)]);
		}
		else
		{//аноним пишет в куки
			setcookie($this->options['settings_cookie_name'], serialize($this->settings), time() + $this->options['settings_cookie_ttl'], '/', COOKIE_DOMAIN);
		}
		return $this;
	}

/** Использование: $this->user = UserModel::getCurrentInstance();
 */
	public static function getCurrentInstance() {
		if (is_null(self::$current_instance))
		{
			self::$current_instance = new UserModel();
			self::$current_instance->continueCurrentSession();
		}
		return self::$current_instance;
	}
}