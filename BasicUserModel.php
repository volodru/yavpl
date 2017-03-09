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

//на фиг не надо тут 2 константы, т.к. используется это дело строго 1 раз в жизни.
//оставим на подумать.
define('BASIC_USER_TABLE_NAME', 'public.users');
define('BASIC_USER_TABLE_KEY_FIELD', 'id');

//без этой константы авторизация не работает от слова вообще.
//без нее много чего еще не работает (например сессии), так что она должна быть.
if (!defined('COOKIE_DOMAIN'))
{
	$msg = 'COOKIE_DOMAIN was not defined!';
	sendBugReport($msg);
	die($msg);
}

class BasicUserModel extends SimpleDictionaryModel
{
	//нужен для реализации шаблона Singleton
	private static $current_instance;

	//0 - anonymous, запись может физически присутствовать в базе! Например, под именем Anonymous.
	//и как правило она там и должна присутсвовать, т.к. должен быть внешний ключ на таблицу юзеров с таблицу логов активностей.
	public $id = 0;

	//данные из select * по юзеру. использовать: $this->user->data['name_r'], например.
	public $data;

	//дефолтные настройки проекта. всё, что не нравится, надо указать явно при вызове конструктора.
	protected $options = [
		'autologin_field_name'			=> 'autologin',
		'autologin_cookie_name'			=> 'autologin',
		'autologin_cookie_ttl'			=> 60*60*24*365,// 1 год
		'autologin_is_always_new'		=> false,
		'logout_magic_string'			=> 'logout',
		'auth_by_login_and_password'	=> false,
		'write_all_activities'			=> true,
	];

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
		if (!in_array($field_name, $this->fields)) die ("getRowByUniqueField required field $field_name");
		return $this->db->exec("SELECT * FROM {$this->table_name} WHERE {$field_name} = $1", $value)->fetchRow();
	}

/** Получить ID юзера по логину и незашифрованному паролю. Пароль в базе зашифрован и во время этой проверки закже шифруется.
 * Кому этого мало - перекрывают метод и шифруют пароли, как хотят.
 */
	public function getIdByLoginAndPassword($login, $password)
	{
		if (!$this->options['auth_by_login_and_password']) die('Login by login&password is not supported.');

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
		if (!isset($_SESSION)){ session_start();}
		$this->id = $id;
		$_SESSION['log_id'] = $id;
		session_write_close();//to avoid locking
		$this->loadCurrentData();
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
		//loading user's data and force logout case
		if (isset($_SESSION['log_id']) && intval($_SESSION['log_id']) > 0)
		{
			$this->id = $_SESSION['log_id'];
			$this->loadCurrentData();
		}
		elseif (isset($_COOKIE[$this->options['autologin_cookie_name']]))
		{
			//da($this);
			$this->data = $this->getRowByUniqueField($this->options['autologin_field_name'], $_COOKIE[$this->options['autologin_cookie_name']]);
			if ($this->data !== false)
			{
				if (!isset($_SESSION)){ session_start();}
				$this->id = $_SESSION['log_id'] = $this->data[$this->key_field];
				session_write_close();//to avoid locking
				$this->setAutologin();//just extend cookie's time
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
		//нашли юзера?
		if (($this->data === false) ||
			//а не force logout случай?
			(strpos($this->data[$this->options['autologin_field_name']], $this->options['logout_magic_string']) >= 0))//logout. magic string. set in UserModel->forceLogout
		{//LOG OUT
			$this->data = false;
			$this->destroyCurrentSession();
		}

		//не забанили ещё?
		if (!$this->isUserAllowedToLogin())
		{
			$this->notifyOnNotAllowedUser();//какое гадкое название метода :(
			$this->destroyCurrentSession();
		}
		//загружаем настройки юзера - нормальному юзеру из базы, анониму из кукиёв.
		$this->loadSettings();
		//пишем в лог независимо от результатов авторизации!
		$this->writeActivityLog();
	}

/** Закончить текущую сессию. Все вариатны log-out приводят сюда.
 */
	public function destroyCurrentSession()
	{
//стираем куки
		setcookie($this->options['autologin_cookie_name'], '', time() - 3600, '/', COOKIE_DOMAIN);
//останавливаем текущую сессию
		if (!isset($_SESSION)){ session_start();}
		unset($_SESSION['log_id']);
		session_write_close();

//обнуляем юзера до анонима
		$this->id = 0;
//сбрасываем его текущие данные (может тут сделать $this->loadCurrentData для анонимуса?)
		$this->dropCurrentData();


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
		if ($this->id > 0)
		{
			$autologinkey = $this->data[$this->options['autologin_field_name']];
			if ($autologinkey == '')
			{
				$autologinkey = $this->generateNewAutologin();
			}
			$this->db->update($this->table_name, $this->key_field, $this->options['autologin_field_name'],
					[$this->key_field => $this->id, $this->options['autologin_field_name'] => $autologinkey]);
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
 *@TODO: стоит переименовать метод!!!!
 */
	public function notifyOnNotAllowedUser()
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
		$this->data = $this->getRow($this->id);
		return $this;
	}

/** Просто очищаем текущие данные. Пока надобность в этом сомнительна.
 */
	public function dropCurrentData()
	{
		$this->data = false;//все проверки делаем на === false
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
 */
	public function loadSettings()
	{
	}

/** Изменить отдульную настройку. Меняем данные в памяти и пишем в базу или в куку.
 */
	public function changeSettings($key, $val)
	{
	}



/**
 *
	public function dropAutologin()
	{
		setcookie('autologinkey', '', time() - 3600, '/', COOKIE_DOMAIN);
		$this->db->update('users', 'user_id', 'autologinkey', ['user_id' => $this->id, 'autologinkey' => null]);
		return $this;
	}

	public function updateLastLogin()
	{
		$this->db->exec("--updateLastLogin()
UPDATE users SET user_lastlogin=now() WHERE user_id=$1", $this->id);
		return $this;
	}

	public function loadSettings()
	{
		if ($this->id > 0)
		{
			$this->settings = (isset($this->data['settings'])) ? unserialize($this->data['settings']) : [];
		}
		else
		{
			$this->settings = (isset($_COOKIE['settings'])) ? unserialize(stripslashes($_COOKIE['settings'])) : [];
		}
		if (isset($this->data['settings']))
		{
			unset($this->data['settings']);
		}
		$this->validateSettings();
		return $this;
	}

	private function validateSettings()
	{
		$tmp = [];
		foreach ($this->validator as $k => $v)
		{
			if (isset($this->settings[$k]))
			{
				if (count($v) == 2)
				{
					$tmp[$k] = (in_array($this->settings[$k], $v[1])) ? $this->settings[$k] : $v[0];
				}
				else
				{
					$tmp[$k] = $this->settings[$k];
				}
			}
			else
			{
				$tmp[$k] = $v[0];
			}
		}
		$this->settings = $tmp;
	}

	public function changeSettings($key, $val)
	{
		if (!(isset($this->validator[$key]) && ((count($this->validator[$key])==1) || in_array($val, $this->validator[$key][1]))))
		{
			sendBugReport('attempt to change settings was invalid', "INVALID KEY OR VAL!\nKEY='$key' VALUE='$val'\n\n".print_r($this, true));
			die("your attempt to change settings was invalid. KEY='$key' VALUE='$val'");
		}
		$this->settings[$key] = $val;

		if ($this->id > 0)
		{
			$this->db->exec("--changeSettings()
UPDATE public.users SET settings = $1 WHERE user_id = $2", serialize($this->settings), $this->id);
		}
		else
		{
			setcookie('settings', serialize($this->settings), time() + 365*24*3600, '/', COOKIE_DOMAIN);
		}
		return $this;
	}



	public function generateNewKey($email)
	{
		$passkey = md5(rand().time());
		$this->db->exec('--generateNewKey
UPDATE users SET passkey=$1, autologinkey=null WHERE email=lower($2)', $passkey, $email);
		return $passkey;
	}


*/

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