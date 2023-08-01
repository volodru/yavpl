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
 * DATE: 2023-08-01
 * Теперь выдача реализована не через деструктор, а принудительно через метод done()
 * Метод done() контроллера вызывается приложением в конце метода $application->run()
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
	public array $result = [];

/** костыль для более-менее безопасных вызовов done()
 * т.к. он вызывается либо в error() либо в $application->run();
 */
	private bool $result_has_been_sent = false;

/** Окончание работы контроллера.
 * К этому времени должна быть заполнена структура с выдачей $this->result[]
 *
 * Метод формирует http заголовки и отдает $this->result в виде JSON как оно есть.
 * Навязываются поля $this->result['status'] и $this->result['http_response_code'], от них отказаться нельзя, но можно перекрыть
 */
	public function done(): void
	{
		//da(__METHOD__);		da($this->result);
/* сие ($this->result_has_been_sent) есть некий костыль.
 * если в методе error не вызывать принудительно деструктор, то он ИНОГДА не вызывается.
 * если его вызывать принудительно - то будет дубль сообщений.
 * если не пользоваться деструкторами, раз они не всегда работают, то проверяем тут на повторный вызов
 */
		if (!$this->result_has_been_sent)
		{
			$this->result['status'] ??= 'OK';//состояние по бизнес логике
			$this->result['http_response_code'] ??= 200;//состояние по HTTP протоколу

			header("Content-type: application/json");
			http_response_code($this->result['http_response_code']);
			print json_encode($this->result);

			$this->result_has_been_sent = true;
		}
		parent::done();
	}

/** Обработка ошибок API
 * Если что-то пошло не так - вызываем $this->error('что-то не так') и ВСЁ.
 * Метод сам даже делает exit() - что в общем-то антипаттерн "early return". но так удобнее.
 * error() это всегда фаталити.
 */
	public function error(string $message, string $status = 'ERROR', int $http_response_code = 200): void
	{
		//da(__METHOD__);
		$this->result['message'] = $message;
		$this->result['status'] = $status;
		$this->result['http_response_code'] = $http_response_code;

//в норме done() вызывает application в конце работы приложения
		$this->done();
		exit();
	}

/** Если класс контроллера таки сделали, нашли и запустили, а нужный метод реализовать в нем забыли, то попадаем сюда.
 * Перекрыв это дело, можно, наверное, организовать свой маленький роутер в пределах класса контроллера.
 */
	public function defaultMethod(string $method_name): void
	{
		$this->error("Method [{$method_name}] not implemented. RTFM.", 'Not Implemented', 501);
	}
}