<?php //YAVPL API
namespace YAVPL;
/**
 * @NAME: Controller
 * @DESC: Main Controller
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2023- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** CHANGELOG
 *
 * DATE: 2020-05-31
 * Создан этот прототип контроллера для API.
 */


/** Контроллер API.
 *
 * Наследники перекрывают __contstruct()|init() и там делают авторизацию/аутентификацию.
 * По умолчанию - ВСЁ открыто.
 * Наследники делают бизнес методы и заполняют в них переменную $result.
 * Остальное делаем тут.
 * status на выходе - OK (латиница, заглавные буквы) все хорошо, иначе статус в соответствии с бизнес логикой,
 * http response status отдается http_response_code
 * бизнес-логика не должна отдавать, что-то кроме http_response_code==200.
 * кроме случая - неправильный URL и контроллер действительно не найден, тогда 404. ну, или метод не найден.
 */

class ControllerAPI extends Controller
{
/** Переменная, в которую набирается результат работы.
 * Она будет выведена в конце в виде JSON*/
	public $result = [];

	public function __destruct()
	{
		$this->result['status'] ??= 'OK';//состояние по бизнес логике
		$this->result['http_response_code'] ??= 200;//состояние по HTTP протоколу

		header("Content-type: application/json");
		http_response_code($this->result['http_response_code']);
		print json_encode($this->result);
		parent::__destruct();
	}

/** Обработка ошибок API
 * Если что-то пошло не так - вызываем $this->error('что-то не так') и ВСЁ.
 * Методо сам даже делает exit()
 */
	public function error($message, $status = 'ERROR', $http_response_code = 200)
	{
		$this->result['message'] = $message;
		$this->result['status'] = $status;
		$this->result['http_response_code'] = $http_response_code;
		exit();
	}

/** Если класс контроллера таки сделали, нашли и запустили, а нужный метод реализовать в нем забыли, то попадаем сюда.
 * Перекрыв это дело, можно, наверное, организовать свой маленький роутер в пределах класса контроллера.
 */
	public function defaultMethod($method_name)
	{
		$this->error("Method [{$method_name}] not implemented. RTFM.", 'Not Implemented', 501);
	}
}