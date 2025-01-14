<?php
namespace YAVPL;
/**
 * @NAME: BasicUserModel
 * @DESC: Prototype for users models
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2017- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** CHANGELOG
 * DATE: 2025-01-12
 * серьезный рефакторинг

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
			'fields'		=> [name, email, passkey],
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
 * не_всегда_нужное. Картинки, логи и т.п. надо хранить в отдельных связанных 1to1 таблицах.
 */

// без этой константы авторизация не работает от слова вообще.
// без нее много чего еще не работает (например сессии), так что она должна быть.
if (!defined('COOKIE_DOMAIN'))
{
	$msg = 'COOKIE_DOMAIN was not defined!';
	sendBugReport($msg);
	die($msg);
}

class BasicUserModel extends DbTable
{
	// нужен для реализации шаблона Singleton
	private static $current_instance;

	// 0 - anonymous, запись может физически присутствовать в базе! Например, под именем Anonymous.
	// и как правило она там и должна присутсвовать, т.к. должен быть внешний ключ на таблицу юзеров с таблицы логов активностей.
	public int $id = 0;

	// данные из select * по юзеру. использовать: $this->user->data['name_r'], например.
	public ?array $data;

	// дефолтные настройки проекта. всё, что не нравится, надо указать явно при вызове конструктора.
	protected array $options = [
		'autologin_field_name'			=> 'autologin',
		'autologin_cookie_name'			=> 'autologin',
		'autologin_cookie_ttl'			=> 60*60*24*365,// 1 год
		'log_id_session_parameter'		=> 'log_id',
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
	public function __construct(array $params = [])
	{
		$params['table_name'] ??= 'public.users';
		$params['key_field'] ??= 'id';
		$params['fields'] ??= [];//в худшем случае будет таблица из одного PK
//предок - DBTable, отдаем ему таблицу, ПК и поля
		parent::__construct($params['table_name'], $params['key_field'], $params['fields']);

//опции для параметров работы класса, которые отличаются от дефолтных
		foreach (array_keys($this->options) as $k)
		{
			$this->options[$k] = $params['options'][$k] ?? $this->options[$k];
		}
	}

/** Просто shortcut.
 */
	public function is(int $id): bool
	{
		return ($this->id == $id);
	}

/** Получить строку данных по уникальному полю (код авторизации, логин, почта, телефон и т.п.)
 */
	/*
	public function getRowByUniqueField(string $field_name, string $value): ?array
	{
		if (!in_array($field_name, $this->fields))
		{
			die(__METHOD__." requires field $field_name");
		}
		return $this->db->exec("SELECT * FROM {$this->table_name} WHERE {$field_name} = $1", $value)->fetchRow();
	}*/

/** Получить запись по уникальному полю (код авторизации, логин, почта, телефон и т.п.).
 * Если не найден - вернем NULL, проверять if (empty(....))
 * Работа с анонимами в контроллерах всегда отдельная!
 */

	public function getRowByUniqueField(string $field_name, string $value): ?array
	{
		if (!in_array($field_name, $this->fields))
		{
			die(__METHOD__." requires field $field_name");
		}
		return $this->db->exec("
SELECT *
FROM {$this->table_name}
WHERE {$field_name} = $1", $value)->fetchRow();
	}

/** Получить ID по уникальному полю (код авторизации, логин, почта, телефон и т.п.).
 * Если не найден - вернем 0, как-бы аноним.
 * У анонимов не должно быть валидных уникальных полей, либо они должны быть зарезервированы.
 * Работа с анонимами в контроллерах всегда отдельная!
 */
	public function getIdByUniqueField(string $field_name, string $value): int
	{
		if (!in_array($field_name, $this->fields))
		{
			die(__METHOD__." requires field $field_name");
		}
		return $this->db->exec("
SELECT {$this->key_field}
FROM {$this->table_name}
WHERE {$field_name} = $1", $value)->fetchRow()[$this->key_field] ?? 0;
	}

/** Получить ID юзера по почте и одноразовому ключу.
 */
	public function getIdByEmailAndPasskey(string $email, string $passkey): int
	{
		return $this->db->exec("-- ".get_class($this).", method: ".__METHOD__."
SELECT {$this->key_field}
FROM {$this->table_name}
WHERE email = $1 AND passkey = $2 AND now() < passkey_valid_till", $email, $passkey)->fetchRow()[$this->key_field] ?? 0;
	}

	public function clearPasskey(int $user_id): void
	{
		$this->db->exec("-- ".get_class($this).", method: ".__METHOD__."
UPDATE {$this->table_name}
SET passkey = NULL, passkey_valid_till = NULL
WHERE id = $1", $user_id);
	}

/** Начать новую сессию пользователя. ID берется после проверки логина/пароля/OAuth/смс с кодом - как угодно.
 * В любом случае, приходим сюда и стартуем сессию.
 * Вызываем из всех скриптов типа login, после успешной проверки ID юзера.
 * Метод просто стартует сессию, ничего больше не проверяя!!!
 */
	public function startCurrentSession(int $id): void
	{
		//daf(__METHOD__);
		if (!isset($_SESSION)){ session_start();}
		$this->id = $id;
		$_SESSION[$this->options['log_id_session_parameter']] = $id;
		session_write_close();//to avoid locking
		$this->loadData();
		//daf("session started id = $id");
		//daf($this->data);
		$this->setAutologin();
		$this->notifyOnStartCurrentSession();
	}

/** Продолжить текущую сессию пользователя.
 * Если есть PHP сессия - работаем с ее данными (log_id), если ее нет, то пытаемся авторизоваться по секретному ключу из
 * куки с автологином.
 * Вызывается, как правило, из синглтона.
 */
	public function continueCurrentSession(): void
	{
		//daf(__METHOD__);

		$log_id_session_parameter = $this->options['log_id_session_parameter'];

		if (!isset($_SESSION)){ session_start();}
		$this->id = 0;//аноним

		//daf('session');daf($_SESSION);daf('cookies');daf($_COOKIE);
		if (isset($_SESSION[$log_id_session_parameter]) && intval($_SESSION[$log_id_session_parameter]) > 0)
		{
			$this->id = $_SESSION[$log_id_session_parameter];
			//daf('continue old session with user id = '.$this->id);
			$this->loadData();
		}
		elseif (isset($_COOKIE[$this->options['autologin_cookie_name']]))
		{
			//daf('continue on cookies');
			$id = $this->getIdByUniqueField($this->options['autologin_field_name'], $_COOKIE[$this->options['autologin_cookie_name']]);
			if ($id > 0)
			{
				//daf('found id '.$id. ' by cookie '.$_COOKIE[$this->options['autologin_cookie_name']]);
				//if (!isset($_SESSION)){ session_start();}
				$this->id = $_SESSION[$log_id_session_parameter] = $id;
				session_write_close();//to avoid locking
				$this->loadData();
				//daf('got user id from cookies '.$this->id);
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
		//daf('user data after attempts to get user ID');
		//daf($this->data);

		$this->setAutologin();//just extend cookie's time

		//нашли юзера под номером $this->id ?
		if ($this->id > 0)
		{
			if ((empty($this->data)) || //какой-то неправильный юзер
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
	public function destroyCurrentSession(): void
	{
		daf(__METHOD__);
//стираем куки
		setcookie($this->options['autologin_cookie_name'], '', time() - 3600, '/', COOKIE_DOMAIN);
//останавливаем текущую сессию
		if (!isset($_SESSION)){ session_start();}
		unset($_SESSION[$this->options['log_id_session_parameter']]);
		session_write_close();

//обнуляем юзера до анонима
		$this->id = 0;
//грузим данные и настройки для анонимуса.
		$this->loadData();
		$this->notifyOnDestroyCurrentSession();
	}

/** Разлогинить все открытые сессии, не допустить автологина больше нигде.
 */
	public function forceLogOut(): void
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
	public function setAutologin(): void
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
	}

/** Тут можно послать письмо
 */
	public function notifyOnStartCurrentSession(): void
	{
	}

/** Тут можно послать письмо
 */
	public function notifyOnContinueCurrentSession(): void
	{
	}

/** Тут можно послать письмо
 */
	public function notifyOnDestroyCurrentSession(): void
	{
	}

/** Тут можно послать письмо
 */
	public function notifyOnBannedUserLoggedIn(): void
	{
	}

/** Юзер не забанен?
 * Надо перекрыть метод и в нем проверять забаненных юзеров.
 * Предка (этот код) не вызываем!!!
 */
	public function isUserAllowedToLogin(): bool
	{
		return true;
	}

/** Записать текущую активность в лог.
 * Недовольные набором полей или самим методом логгирования перекрывают метод и не вызывают предка.
 */
	public function writeActivityLog(): void
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
	}

/** Если не устраивает SELECT * по PK, перекрывают и делают сложные селекты, грузят картинки и пр.
 * Должно работать быстро, т.к. делается при каждом посещении каждой страницы.
 */
	public function loadData(): void
	{
		$this->data = $this->getRawRow($this->id);
	}

/** Можно перекрыть этот метод, если автологин требуется привязать к паролю, например.
 * И при этом отвязать от адреса/времени, т.е. получать всё время один и тот же автологин для одного и того же юзера (пароля/логина)
 * По умолчанию, автологин случайный каждый раз.
 */
	public function generateNewAutologin(): string
	{
		return md5(time().REMOTE_ADDR);
	}


/** Если в проекте есть класс вида
class User extends \YAVPL\BasicUserModel{...}

Использование в главном контроллере:
namespace Controllers;

class Controller extends \YAVPL\Controller
{
	public function __construct()
	{
		parent::__construct();
		$this->user = \Models\User::getCurrentInstance();
	}
}
 */
	public static function getCurrentInstance()
	{
		if (is_null(self::$current_instance))
		{
			$class_name = get_called_class();
			self::$current_instance = new $class_name();
			self::$current_instance->continueCurrentSession();
		}
		return self::$current_instance;
	}

	public static function hasInstance()
	{
		return !is_null(self::$current_instance);
	}
}