<?php



/**
 * 
 * Библиотека для PHP для подключения и вызова функций SMSimple XMLRPC API.
 *
 */



/*
 *  Файл библиотеки XMLRPC, написанной на PHP.
 *  Раньше использовался только он, но так как в PHP5 появился модуль xmlrpc, разумно использовать его.
 *  По-умолчанию, класс Smsimple() будет пытаться определить, 
 *  Если у Вас установлен этот модуль, то можно смело удалять ./lib/xmlrpc.inc, чтоб не путался под ногами
 *  - всё равно будет использован модуль php5-xmlrpc.
 *  Эта переменная содержит результат импорта билиотеки: 1 (есть) или 0 (нет).
 */
$LIB_EXISTS = include('xmlrpc.inc');


/**
 * Класс исключений. Ошибки происходящие из класса SMSimple выбрасываются в виде этого исключения.
 */

class SMSimpleException extends Exception{}


/**
 * SMSimple() - основной класс для работы с API.
 * 
 * Работа с библиотекой протекает по следующей стандартной схеме:
 * 
 *   1. Создаём экземпляр класса SMSimple()
 *   2. Вызываем функцию авторизации (получаем session_id, который сохраняется как атрибут класса SMSimple)
 *   3. Совершаем один или несколько вызовов прикладных функций (отправка SMS, проверка статуста и т.п.)
 *   4. При возникновении ошибки класс вызвает исключение SMSimpleException с описанием ошибки (сообщение, возвращённое с сервера XMLRPC API, если ошибка произошла на стороне сервера)
 * 
 * Таким образом, минимальный блок работы с API выглядит примерно так:
 * 
 *     require_once('./smsimple.class.php');
 *     
 *     $sms = new SMSimple(array(
 *         'url' => 'http://api.smsimple.ru/',
 *         'username' => 'my_username',
 *         'password' => 'my_password',
 *     ));
 * 
 *     try {
 * 
 *         $sms->connect();
 *         
 *         $sms->call_method_1();
 *         $sms->call_method_2();
 *         $sms->call_method_3();
 * 
 *     }
 *     catch (SMSimpleException $e) {
 *         echo $e->getMessage();
 *     }
 * 
 */
 

class SMSimple
{
    protected $session_id = '';
    protected $username = '';
    protected $password = '';
    protected $encoding = 'UTF-8';
    protected $url = '';
    protected $xmlrpc = null;
    protected $input_encoding = 'UTF-8';
    protected $new_xmlrpc = 'auto';

    /**
     *  Конструктор класса. Не вызывается напрямую. Вызывается интерпретатором PHP в момент создания класса `sms = SMSimple()`.
     *
     *  @param:  $params  array()  Массив параметров, обязательные: `username`, `password`, `url`; опциональные: `encoding`, `new_xmlrpc`.
     *  @param_element:  $params  $params['username']    string    данные, указанные при регистрации (для входа в ЛК)
     *  @param_element:  $params  $params['password']    string    данные, указанные при регистрации (для входа в ЛК)
     *  @param_element:  $params  $params['url']         string    обычно должен быть _http://api.smsimple.ru_, но может меняться на url beta-api в случае, если Вы будете тестировать новые версии апи
     *  @param_element*: $params  $params['encoding']    string    важный параметр, если страницы и скрипты Вашего сайта используют кодировку, отличную от `utf-8`, например, `windows-1251`.
     *  @param_element*: $params  $params['new_xmlrpc']  string/bool   force-флаг, заставляющий при необходимости насильно использовать встроенный модуль `php5-xmlrpc`, а не написанную на PHP библиотеку `./lib/xmlrpc.inc`; может быть `true`, `false`, `'auto'`.
     *
     *  @returns:  SMSimple  Возвращает экземпляр класса `SMSimple`, с которым производить дальнейшие манипуляции (апи-вызовы).
     *
     */
    public function __construct($params=array()) {
        if (isset($params['username']))
            $this->username = $params['username'];
        if (isset($params['password']))
            $this->password = $params['password'];
        if (isset($params['url']))
            $this->url = $params['url'];
        if (isset($params['encoding']))
            $this->input_encoding = $params['encoding'];
        if (isset($params['new_xmlrpc']))
            $this->new_xmlrpc = $params['new_xmlrpc'];

        global $xmlrpc_internalencoding, $xmlrpc_defencoding;
        $xmlrpc_internalencoding = $this->encoding;
        $xmlrpc_defencoding = $this->encoding;
        $this->xmlrpc = new xmlrpc_client($this->url);
        $this->xmlrpc->return_type = 'phpvals';
        $this->xmlrpc->request_charset_encoding = $this->encoding;
    }

    /**
     * Системный метод вызова XML-RPC функций API (вариант через `./lib/xmlrpc.inc`)
     */
    protected function _doApiCall_old($method, $params=array()) {
        $response = $this->xmlrpc->send(new xmlrpcmsg('call', array(new xmlrpcval($method), php_xmlrpc_encode($params))));
        if ($response->faultCode())
            throw new SMSimpleException($response->faultString());
        $result = $response->value();
        if (isset($result['info']) && !empty($result['info']))
            throw new SMSimpleException($result['info']);
        if (isset($result['result']))
            return $result['result'];
        return false;
    }
    
    /**
     * Системный метод вызова XML-RPC функций API (вариант через `php5-xmlrpc`)
     */
    protected function _doApiCall_new($method, $params=array()) {
        $request = xmlrpc_encode_request($method, $params, array('encoding' => $this->encoding, 'escaping' => 'markup')
        );
        $context = stream_context_create(array('http' => array(
            'method' => "POST",
            'header' => "Content-Type: text/xml; charset=".$this->encoding,
            'content' => $request
        )));
        $file = file_get_contents($this->url, false, $context);
        $response = xmlrpc_decode($file);
        if ($response && xmlrpc_is_fault($response)) {
            throw new SMSimpleException($response['faultString']);
        } else {
            if (isset($response['info']) && !empty($response['info']))
                throw new SMSimpleException($response['info']);
            if (isset($response['result']))
                return $response['result'];
        }
        return false;
    }
    
    /**
     * Системный метод вызова XML-RPC функций API (осуществляет выбор варианта, затев вызов этого варианта)
     */
    protected function _doApiCall($method, $params=array()) {
        if ($this->input_encoding != $this->encoding){
            foreach($params as $key => $value)
                if (is_string($value))
                    $params[$key] = iconv($this->input_encoding,$this->encoding,$value);
        }
        // определяем php_xmlrpc library путём проверки доступности функции xmlrpc_encode_request
        // если xmlrpc.inc не был обнаружен, то форсим new_xmlrpc
        $can_use_new_xmlrpc = $this->new_xmlrpc===true || ($this->new_xmlrpc=='auto' && function_exists('xmlrpc_encode_request'));
        global $LIB_EXISTS;
        if (!$LIB_EXISTS && !$can_use_new_xmlrpc)
            throw new SMSimpleException('XmlRpc libraries not available (nor internal, neither external)');
        if ($can_use_new_xmlrpc) {
            return $this->_doApiCall_new($method, $params);
        } else {
            return $this->_doApiCall_old($method, $params);
        }
    }

    /**
     * Соединение с шлюзом SMSimple.ru.
     *
     * @params: Не имеет аргументов, использует `username` и `password`, заданные при создании класса `SMSimple`.
     *
     * @returns: boolean Возвращает true в случае удачи, в случае неудачи выбрасывает `SMSimpleException`.
     */
    public function connect() {
        $res = $this->_doApiCall('pajm.user.auth', array(
            'username' => $this->username,
            'password' => $this->password,
        ));
        if (!$res)
            throw new SMSimpleException('Invalid API username or password');
        $this->session_id = $res['session_id'];
        return true;
    }

    /**
     * Отправка одиночного сообщения
     * 
     * @param:  $origin_id  int     номер подписи (номера можно узнать в личном кабинете на странице ["Подписи"](https://smsimple.ru/origin)); можно передать `null`, тогда будет взята "Основная" подпись (см. ЛК ["Подписи"](https://smsimple.ru/origin))
     * @param:  $phone      string  телефонный номер абонента (любой формат с кодом-телефоном, например `'8-916-1234567'` или `'79161234567'`) или несколько через запятую (пробелы разрешены, например `'79031234567, 89161234567'`)
     * @param:  $message    string  текст SMS-сообщения
     * @param*:  $multiple   bool    необязательный параметр, по-умолчанию `false`. Если вы отправляете сообщение сразу нескольким адресатам, то им присвоистся один и тот же (`false`) или разные (`true`) номера.
     *
     * @returns: int Возвращает номер (`id`) сообщения, по которому потом можно получать статус доставки/недоставки. Для `$multiple = false` вернёт одно число, для `$multiple = true` -- массив номеров в той последовательности, в которой шли телефоны абонентов.
     */
    public function send($origin_id, $phone, $message, $multiple = false) {
        $message_id = $this->_doApiCall('pajm.sms.send', array(
            'session_id' => $this->session_id,
            'origin_id'  => $origin_id,
            'phone'      => $phone,
            'message'    => $message,
            'multiple'   => $multiple,
        ));
        return $message_id;
    }

    /**
     * Проверка статуса доставки сообщения, отправленного с помощью метода `send`.
     * 
     * @param:  $message_id  int  номер SMS-сообщения, возвращённый методом `send`
     *
     * @returns: array() Возвращает массив вида:
     * 
     *     array(
     *           'sms_id' => 12341234,  // то, что было передано в параметра $message_id
     *           'sms_count' => 3, // количество SMS-сообщений, соответствующих этому id, 
     *                             // равное <кол-во_абонентов>*<кол-во_частей_сообщения>.
     *                             // Если сообщение состояло из одной части, и был один абонент,
     *                             // тут будет 1 (единица).
     *           'sms_delivered' => 2, // количество SMS-сообщений, которые были доставлены
     *           'sms_failed' => 0,  // количество SMS-сообщений, которые не были доставлены 
     *                               // (то есть являются уже окончательно недоставленными по
     *                               // тем или иным причинам), в данном случае таких нет
     *           'sms_delayed' => 1,   // количество SMS-сообщений, статус доставки которых ещё
     *                                 // неизвестен (доставка в процессе). Для получения статуса 
     *                                 // следует проверить позже. Сообщения могу быть без статуса
     *                                 // до двух суток, в зависимости от оператора.
     *          )
     *
     */
    public function check_delivery($message_id) {
        $message_id = $this->_doApiCall('pajm.sms.get_delivery', array(
            'session_id' => $this->session_id,
            'sms_id'     => $message_id,
        ));
        return $message_id;
    }

    /**
     * Создание новой запланированной рассылки
     * 
     * @param:  $params  array()  Массив параметров, обязательные: `groups`, `title`, `template`; опциональные: `origin_id`, `start_date`, `start_time`, `stop_time`.
     *
     * @param_element:  $params  $params['groups']  array()  массив с номерами групп, по которым произвести рассылку, например `array(123,365,436,2134)`
     * @param_element:  $params  $params['title']  string  называние рассылки (для Вас), это название будет фигурировать только в списке рассылок в ЛК
     * @param_element:  $params  $params['template']  string  текст SMS-сообщения (называется *template*, т.к. это шаблон, и в нём могут быть использованы параметры подстановки `%title%`, `%custom_1%`, `%custom_2%` из групп контактов)
     *
     * @param_element*:  $params  $params['origin_id']  int  номер подписи (номера можно узнать в личном кабинете на странице ["Подписи"](https://smsimple.ru/origins)); если этот параметр отсутствует, будет взята подпись, заданная как "Основная" в ЛК
     * @param_element*:  $params  $params['start_date']  string  дата старта рассылки - дата в формате *YYYY-MM-DD* задаёт день, в который рассылка будет отправлена (если значение `start_date` не задано, то оно будет принять равным текущему дню)
     * @param_element*:  $params  $params['start_time']  string  время старта рассылки - уточняет время старта в формате *HH:MM* или *HH:MM:SS* (если не задано, то будет равно `00:00:00`)
     * @param_element*:  $params  $params['stop_time']  string  время остановки рассылки - уточняет время остановки в формате *HH:MM* или *HH:MM:SS* (если не задано, то будет равно `23:59:59`).
     *                                                  Это время будет использовано только в случае, когда рассылка не успела завершиться. Можно рассматривать его как "аварийное" время завершения.
     *                                                  Это может быть важно по ряду причин, в частности, информация рассылки может потерять актуальность или время рассылки слишком позднее (ночь).
     *
     * @returns:  int  Возвращает номер (id) новой рассылки.
     */
    public function addJob($params=array()) {
        return $this->_doApiCall('pajm.job.add', array(
            'session_id' => $this->session_id,
            'origin_id'  => $params['origin_id'],
            'groups'     => $params['groups_ids'],
            'title'      => $params['title'],
            'template'   => $params['message'],
            'start_date' => $params['start_date'],
            'start_time' => $params['start_time'],
            'stop_time'  => $params['stop_time'],
        ));
    }

    /**
     * Получение списка подписей: **все** подписи текущего аккаунта
     *  
     * @params: Нет параметров.
     *
     * @returns: array() Возвращает список подписей, доступных для использования в качестве поля "отправитель" (`origin_id`), массив массивов вида
     * 
     *     array(
     *           array(
     *               'id' => 12345,  // номер подписи (id) для использования при отправке send()
     *                               // или создании рассылки addJob
     *               'title' => 'my_origin',  // собственно подпись
     *               'create_date' => '2013-01-22',  // дата создания подписи
     *               'is_default' => 1,  // эта подпис является подписью по-умолчанию, которая будет
     *                                   // использована в случае, если подпись не задана ("Основная", 
     *                                   // этот параметр = 1 только у одной подписи, у остальных 0)
     *          ),
     *         ...
     *     )
     */
    public function origins() {
        return $this->_doApiCall('pajm.origin.select', array(
            'session_id' => $this->session_id,
        ));
    }

    /**
     * Добавление нового контакта в существующую группу
     * 
     * @param:  $params  array()   Массив параметров, обязательные: `group_id`, `phone`; опциональные: `title`, `custom_1`, `custom_2`.
     *
     * @param_element:  $params  $params['group_id']   int   номер группы, в которую добавляем контакт
     * @param_element:  $params  $params['phone']  string  телефонный номер абонента (контакта)
     *
     * @param_element*: $params  $params['title']  string  "Имя" контакта (`%title%` в шаблоне)
     * @param_element*: $params  $params['custom_1']  string  "Доп.поле 1" контакта (`%custom_1%` в шаблоне)
     * @param_element*: $params  $params['cusomt_2']  string  "Доп.поле 2" контакта (`%custom_2%` в шаблоне)
     *
     * @returns:  int  Возвращает номер (id) новой рассылки.
     * 
     * @params_example:
     *         
     *      $params = array(
     *          'group_id' => 1,
     *          'phone'    => '7-926-111-22-33',
     *          'title'    => 'Василий Пупкин',
     *          'custom_1' => '',
     *          'custom_2' => '',
     *      );
     */
    public function addContactToGroup($params=array()) {
        return $this->_doApiCall('pajm.contact.add', array(
            'session_id' => $this->session_id,
            'group_id'   => $params['group_id'],
            'phone'      => $params['phone'],
            'title'      => $params['title'],
            'custom_1'   => $params['custom_1'],
            'custom_2'   => $params['custom_2'],
        ));
    }
    
    /**
     * Поиск контакта по телефону. Поиск может осуществляются по всем группам или по избранным (одной или нескольким).
     * 
     * @param:  $params  array()  Массив параметров, обязательные: `phone`; опциональные: `group_id`.
     *
     * @param_element:  $params  $params['phone']  string  телефонный номер абонента (контакта), который требуется найти
     * @param_element*: $params  $params['group_id']  int|array(int*)  номер группы (`int`) или номера групп (`array()` из `int`), в которой/которых требуется найти контакт (т.е. ограничение поиска на эти группы)
     *
     * @returns: Возвращает список найденных контактов, массив массивов вида
     * 
     *     array(
     *          array(
     *                'id'        => 12345,  // номер контакта
     *                'phone'     => '...' // |
     *                'title'     => '...' // | параметры контакта
     *                'custom_1'  => '...' // |
     *                'custom_2'  => '...' // |
     *                'group_id'  => 222,  // номер группы
     *                'group_title' => 'Группа 1',  //название группы
     *               ),
     *          ...
     *         )
     *
     * @params_example:
     *     
     *    $params = array(
     *        'phone' => '7-926-111-22-33',
     *        'group_id' => array(1,2,3,4), // может также быть = 1 или = null или вообще отсутствовать
     *    );
     *
     */
    public function searchContact($params=array()) {
        $rows = $this->_doApiCall('pajm.contact.search', array(
            'session_id' => $this->session_id,
            'phone'   => $params['phone'],
        ));
        
        // апи-функция ищет по всем группам, удаляем ненужные, если параметр $params['group_id'] задан
        if($params['group_id']){
            if(!is_array($params['group_id']))
                $params['group_id'] = array($params['group_id']);
            foreach($rows as $key => $row)
                if(!in_array($row['group_id'],$params['group_id']))
                    unset($rows[$key]);
        }
        
        return $rows;
    }

    /**
     * Удаление контакта из группы
     * 
     * @param:  $params  array()  Массив параметров, два варианта вызова:
     *
     *   1. с обязательным полем `contact_id`; 
     *   2. с обязательным `phone` и необязательным `group_id`.
     *
     * @param_element:  $params  $params['contact_id']  int|array(int*)  заранее известный номер контакта (или массив номеров), который надо удалить.
     *                                                   Если этот параметр задан, остальные параметры - `phone`, `group_id` - игнорируются, происходит удаление конкретных контактов по их `id`.
     *                                                   Если этот параметр не задан, то происходит поиск (с помощью метода [`searchContact`](#searchContact)) по параметрам `phone`, `group_id`, затем контактов по найденным номерам (`id`)
     * @param_element:  $params  $params['phone']  string  телефонный номер абонента (контакта), который требуется найти и удалить
     * @param_element*:  $params  $params['group_id']  int|array(int*)   номер группы (`int`) или номера групп (`array()` из `int`), в которой/которых требуется найти и удалить контакт (т.е. ограничение поиска и удаления только на этих группы)
     *
     * @returns:  int  Возвращает количество удалённых контактов.
     *
     * @warning: Удаление контактов из заблокированных (участвующих в незавершённых запланированных рассылках) групп невозможно, контакты из таких групп будут молча пропущены.
     */
    public function deleteContact($params=array()) {
        if(!$params['contact_id']){
            $rows = $this->searchContact($params);
            
            foreach($rows as $row)
                $params['contact_id'][]=$row['id'];
        }
        
        if($params['contact_id']){
            return $this->_doApiCall('pajm.contact.delete', array(
                'session_id' => $this->session_id,
                'id' => $params['contact_id'],
            ));
        }else
            return false;
    }
    
    /**
     * Удаление группы
     * 
     * @param:  $params  array()   Массив параметров, обязательные: `group_id`.
     *
     * @param_element:   $params   $params['group_id']  int  номер группы (`int`) для удаления
     *
     * @returns:  bool  Возвращает `true`, если группа обнаружена и удалена. В остальных случаях вызывает исключения: если группы заблрокирована (участвует в незавершённых запланированных рассылках) или вообще не найдена по номеру.
     *
     * @params_example:
     *
     *      $params = array(
     *          'id' => 1234, // номер группы
     *      );
     * 
     */
    public function deleteGroup($params=array()) {
        return $this->_doApiCall('pajm.group.delete', array(
            'session_id' => $this->session_id,
            'id'   => $params['id'],
        ));
    }

    /**
     * Добавление (создание) группы
     * 
     * @param:  $params  array()  Массив параметров, обязательные: `title`; опциональные: `description`, `is_blacklist`.
     *
     * @param_element:  $params   $params['title']  string   название группы, заголовок, который будет виден в списке групп
     * @param_element*:  $params   $params['description']   string  более подробное описание (для комментариев); значение по-умолчанию `''`
     * @param_element*:  $params   $params['is_blacklist']   bool  будет ли эта группа использоваться как "чёрный список"; значение по-умолчанию `false`
     *
     * @returns:  int  Возвращает номер вновь созданной группы.
     * 
     * @params_example:
     *
     *      $params = array(
     *          'title'        => 'Название новой группы',
     *          'is_blacklist' => false, // это будет группа для "чёрного списка"?
     *          'description'  => 'Поясняющее описание к группе',
     *      );
     */
    public function addGroup($params=array()) {
        return $this->_doApiCall('pajm.group.add', array(
            'session_id'   => $this->session_id,
            'title'        => $params['title'],
            'is_blacklist' => $params['is_blacklist'],
            'description'  => $params['description'],
        ));
    }

    /**
     * Объём группы (количество контактов в группе)
     * 
     * @param:  $params  array()  Массив параметров, обязательные: `id`.
     *
     * @param_element:  $params  $params['id']  int  номер группы
     *
     * @returns:  int  Возвращает количество контактов в группе.
     * 
     * @params_example:
     * 
     *      $params = array(
     *          'id' => 1234, // номер группы
     *      );
     */
    public function getContactCount($params=array()) {
        return $this->_doApiCall('pajm.contact.select', array(
            'session_id'   => $this->session_id,
            'group_id'     => $params['id'],
            'need_count'   => True,
        ));
    }

    /**
     * Получение контактов из группы, возможно постранично
     * 
     * @param:  $params  array()    Массив параметров, обязательные: `id`; опциональные: `offset`, `limit`;
     *
     * @param_element:  $params   $params['id']  int  номер группы
     * @param_element*: $params   $params['offset']  int  начинать с этого контакта (по аналогии с SQL-командой `OFFSET`); по-умолчанию `0`
     * @param_element*: $params   $params['limit']   int  ограничить объём выдачи (по аналогии с SQL-командой `LIMIT`); по-умолчанию `0`, что означает "не ограничивать"
     *
     * @returns:  array()  Возвращает контакты из группы, в виде массив массивов
     * 
     *     array(
     *           array(
     *                 'id' => 1,
     *                 'title' => 'Вася',
     *                 'phone' => '79031234567',
     *                 'custom_1' => '',
     *                 'custom_2' => '',
     *                ),
     *           ...
     *          )
     *
     * @params_example:
     *
     *      $params = array(
     *          'id' => 1234,    // номер группы
     *          'offset' => 100, //  - для постраничного получения
     *          'limit' => 20,   //  - для постраничного получения
     *      );
     */
    public function getContacts($params=array()) {
        return $this->_doApiCall('pajm.contact.select', array(
            'session_id'   => $this->session_id,
            'group_id'     => $params['id'],
            'offset'   => $params['offset'] ? $params['offset'] : 0,
            'limit'   => $params['limit'] ? $params['limit'] : 0,
        ));
    }

    /**
     * Получение информации о профиле
     * 
     * @params: Не принимает параметров.
     *
     * @returns: array() Возвращает доступные данные по Вашему аккаунту в виде массива, например
     * 
     *     array(
     *           'id' => 52579,
     *           'username' => 'test',
     *           'title' => 'Василий Васильевич Пупкин',
     *           'ussd_enabled' => 1,
     *           'ussd_balance' => -673,
     *           'ussd_leverate' => 0,
     *           'phone' => '03',
     *           'email' => 'vasya@pupkin.ru',
     *           'billing_type' => 'Предоплата',
     *           'balance' => 966.911,
     *           'leverate' => 0.0,
     *     )
     */
    public function get_profile() {
        return $this->_doApiCall('pajm.user.get', array(
            'session_id' => $this->session_id,
        ));
    }
    
    /**
     * Старт USSD сессии, инициирует начало обмена USSD-сообщениями. Эта функция ещё ничего не посылает на телефон абонента, первая отправка USSD-меню осуществляется Вашим
     * скриптом обратного вызова согласно идентификатору Вашего приложения.
     * 
     * @param:  $phone  string  номер абонента, которому отправляется USSD
     * @param:  $optional  string  любые данные для дополнительной идентификации, сопровождают сессию до закрытия, обычно ваш внутренний идентификатор сессии
     * @param:  $encoding  string  `'GSM-7'` для меню на латинице (~120 символов) или `'UCS2'` для меню с русскими буквами (~60 символов)
     * @param:  $app_id  string идентификатор USSD-приложения согласно вашему ЛК (см. ["USSD-приложения"](https://smsimple.ru/ussd/apps) в меню, пункт доступен, если у Вас открыт доуступ к USSD).
     *                    Идентификатор можно не задавать, если у Вас одно USSD-приложение (оно и вызовется), если у Вас несколько USSD-приложений и `app_id` не указан, будет возвращена ошибка
     * 
     * @returns:  int/bool  Если старт сессии прошёл удачно, возвращает номер (`id`) сессии (по нумерации SMSimple), иначе `false` (например, неверный телефон или не установлено/не отвечает корректно скрипт обратного вызова).
     * 
     * @params_example:
     *
     *    $this->ussd_session_start(
     *        '79991234567',  // phone.
     *        'AF13BC57234',  // optional. например, это id сессии, для идентификации диалога на стороне Вашего приложения
     *        'GSM-7',        // encoding. при этом значении Ваши скрипты обратного вызова должны отдавать меню в латинице
     *        'my_ussd_app'   // app_id. идентификатор USSD-приложения, задаётся Вами самостоятельно в ЛК
     *    );
     */
    public function ussd_session_start($phone,$optional=null,$encoding='GSM-7',$app_id=null) {
        return $this->_doApiCall('pajm.ussd.start_session', array(
            'session_id' => $this->session_id,
            'phone' => $phone,
            'optional' => $optional,
            'encoding' => $encoding,
            'app_id' => $app_id,
        ));
    }   

    /**
     * Прерывание USSD сессии.
     * Оставлена для обратной совместимости, ничего реально не делает.
     *
     * @params: В качестве параметра `$ussd_session_id` сюда надо было передавать `id` сессии, полученный вызовом [`ussd_session_start`](#ussd_session_start).
     */
    public function ussd_session_abort($ussd_session_id) {
        return $this->_doApiCall('pajm.ussd.release_session', array(
            'session_id' => $this->session_id,
            'ussd_session_id' => $ussd_session_id,
        ));
    }   
    
    /**
     * Получение полной статистики SMS-сообщений за указанный период.
     *
     * @warning: Внимание! Использование этой функции временно отключено.
     */
    public function sms_statistic($year_begin, $month_begin, $day_begin, $year_end, $month_end, $day_end, $count=false, $start=0, $limit=1000) {
        $arr = $this->_doApiCall('pajm.statistic.detail', array(
            'session_id' => $this->session_id,
            'year_begin' => $year_begin,
            'month_begin' => $month_begin,
            'day_begin' => $day_begin,
            'year_end' => $year_end,
            'month_end' => $month_end,
            'day_end' => $day_end,
            'start' => $start,
            'limit' => $limit,
            #'profile_id' => 1,
        ) + ($count?array('need_count'=>true):array()));
        if (is_array($arr))
            foreach ($arr as $key=>$value)
                $arr[$key]['short_message'] = base64_decode($value['short_message']);
        return $arr;
    }   


    /**
     * Запрос баланса.
     *
     * @params: Без параметров.
     *
     * @returns:  float  Возвращает только баланс, по конечному результату эквивалентно вызову `get_profile()['balance']`, но выгоднее, если Вам нужен только Ваш SMS-баланс, т.к. не передаёт ничего лишнего.
     */
    public function get_balance() {
        return $this->_doApiCall('pajm.user.get_balance', array(
            'session_id' => $this->session_id,
        ));
    }   

    /**
     * Заказ на создание новой подписи. Запрос на создание новой подписи проходит обязательную премодерацию менеджером и может быть одобрен или отклонён.
     * 
     * @param:  $new_origin  string  какую подпись Вы желаете заказать, например `'vasyapupkin'`
     *
     * @returns: int Возвратит номер заказа. Его можно использовать для проверки статуса заказа с помощью [`originOrderStatus`](#originOrderStatus).
     */
    public function originOrder($new_origin) {
        return $this->_doApiCall('pajm.origin.order_add', array(
            'session_id' => $this->session_id,
            'title' => $new_origin,
        ));
    }  

    /**
     * Проверка статуса заказа на создание новой подписи.
     * 
     * @param:  $order_id  int  id заказа, полученный от метода [`originOrder`](#originOrder)
     *
     * @returns: int/null/string Возвращает:
     * 
     *   - `0` при отказе
     *   - `null` при ожидании (возможен возврат пустой строки `''`)
     *   - ненулевое число - `id` новой подписи, если заказ одобрен и подпись создана
     */
    public function originOrderStatus($order_id) {
        return $this->_doApiCall('pajm.origin.order_get', array(
            'session_id' => $this->session_id,
            'id' => $order_id,
        ));
    }  

	/**
	 * Узнать количество PDU, которое нужно для отправки конкретного текста SMS
     * 
     * @param:  $message  string  Текст смс
     *
     * @returns: int Возвращённое число равно количеству отдельных SMS, которые необходимы для передачи всего текста на мобильный телефон абонента.
	 */
	public function getAmount($message)
	{
        return $this->_doApiCall('pajm.billing.get_amount', array(
            'message' => $message,
        ));
	}

	/**
	 * Узнать количество символов, которое займёт конкретный текст в PDU
     * 
     * @param:  $message  string  Текст смс
     *
     * @returns:  int  Возвращённое число равно количеству символов, которые будут задействованы при передаче SMS-сообщения. Логика работы примерно такая: если в тексте встречается хотя бы один символ не-латиницы, то 
     * все символы SMS буду занимать по 2 символа в конечном PDU, если только латиница - то по одному символу PDU на каждый символ исходного текста.
     * 
     * @note: Под латиницей понимаются также цифры и знаки препинания.
	 */
	public function getSymbolsCount($message)
	{
        return $this->_doApiCall('pajm.billing.get_symbols_count', array(
            'message' => $message,
        ));
	}

	/**
	 * Получить информацию о цене, количестве SMS и оценку, достаточно ли средств на балансе.
     * Функция совершает подсчёт символов в сообщении (аналогично [`getSymbolsCount`](#getSymbolsCount)), затем по ним считает количество частей (PDU), на которое, возможно, придётся разбить сообщение для отправки,
     * затем выбирает наилучшую цену из Ваших пакетов и умножает цену одного PDU на их количество и на количество контактов, указанных в `$phone`.
     * 
     * @param:  $origin_id  int  `id` подписи, `0` для случайно-цифровой
     * @param:  $phone  string  номер абонента, можно использовать несколько номеров через запятую, например, `'89031234567, 79161234567, 89261324354'`
     * @param:  $message  string  Текст сообщения
     *
     * @returns: array() Возвращает массив вида
     * 
     *     array(
     *           'cost' => 1.88,  // суммарная стоимость всех SMS, с учётом количества абонентов
     *                            // и количества частей сообщений
     *           'sms_count' => 2, // количество всех SMS = количество абонентов * количество частей сообщения
     *           'sms_out_of_balance' => 0  // если баланса достаточно для отправки всех этих сообщений, то
     *                                      // тут будет 0; в противном случае тут будет количество SMS, 
     *                                      // на которых не хватат баланса; например, если баланс=0, то тут 
     *                                      // будет такое же число, как и в 'sms_count', что значит, что ни 
     *                                      // одно сообщение не может быть отправлено.
     *     )
     *
     * @note: Цена SMS зависит от подписи.
	 */
	public function getPrice($origin_id, $phone, $message) {
        $result = $this->_doApiCall('pajm.billing.get_price', array(
            'session_id' => $this->session_id,
            'origin_id'  => $origin_id,
            'phone'      => $phone,
            'message'    => $message,
        ));
        return $result;
    }
}
