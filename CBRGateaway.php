<?php
/**
* Шлюз для общения с API Центробанка России
*
* @name YiiCBRGateway
* @date 15.09.2015
* @author Kicha Vladimir <kicha@kreker.org>
* @version 1.0
* @api http://www.cbr.ru/scripts/Root.asp
* @requirements: PHP >= 5.4 with SOAP
*
* public methods:
* __call($methodName, $params) - указывает, что необходимо использовать для вызова $methodName из WSDL-схемы с указанными параметрами $params
* getCoursesList($_date = null) - Возвращает массив с курсами валют на указанную дату
* getCourse($_currencyStringCode, $_date = null) - Возвращает курс указанной валюты на указанную дату
* getMan() - Отображает мануал (сигнатуру) к методу из текущей WSDL-схемы
* getRawResultInStd() - Возвращает результат запроса в виде объекта StdClass
* getResultInXMLObject() - Возвращает XML-тело ответа в виде объекта SimpleXMLElement
*
* public methods - Доступные схемы:
* daily(): http://www.cbr.ru/scripts/Root.asp?PrtId=DWS - получения ежедневных данных (курсы валют, драгметаллов, итд)
* regions(): http://www.cbr.ru/scripts/Root.asp?PrtId=WSR - получение статистических данных регионов (количество банков, данные о них)
* organizations(): http://www.cbr.ru/scripts/Root.asp?PrtId=WSCO - получение данных о кредитных организациях (список банков, поиск)
* market(): http://www.cbr.ru/scripts/Root.asp?PrtId=SEC - информация о рынке ценных бумаг
*
*
* Пример использования:
* CBRGateaway->SCHEMA_NAME()->FUNCTION_NAME_FROM_WSDL()->HADLER(); OR CBRGateaway->NATIVE_METHOD();
* - Получить документацию по методу из схемы market: Yii::app()->CBRGateaway->market()->DirRepoAuctionParam()->getMan();
* - Получить текущий курс доллара: Yii::app()->CBRGateaway->getCourse("USD");
* - Получить курс доллара за 01.09.2014: Yii::app()->CBRGateaway->getCourse("USD", "01.09.2014") либо Yii::app()->CBRGateaway->getCourse("USD", 1409518800);
* - Получить данные метода DirRepoAuctionParam из схемы market: Yii::app()->CBRGateaway->market()->DirRepoAuctionParam(array('DateFrom'=> '01.02.2014', 'DateTo' => '15.09.2015'))->getResultInXMLObject();
* - Получить динамику цен на драгметаллы с 01.09.2014 по 10.12.2015: Yii::app()->CBRGateaway->daily()->DragMetDynamic("01.09.2014", "10.12.2015")->getResultInXMLObject();
*
*
* NOTE: Даты (On_date) автоматически конвертируются в требуемый для центробанка формат (System.DateTime).
* NOTE: Полученные значения необходимо кешировать
* NOTE: Это компонент для Yii, но его можно использовать и без фреймворка, если убрать наследование
* NOTE: Можно использовать в php >= 5.1, если модифицировать код: убрать funcname()[]
*/

class CBRGateaway extends CApplicationComponent {
    
    private $uri = array(
        'daily' => 'http://www.cbr.ru/DailyInfoWebServ/DailyInfo.asmx?WSDL',
        'regions' => 'http://www.cbr.ru/RegionWebServ/regional.asmx?WSDL',
        'organizations' => 'http://www.cbr.ru/CreditInfoWebServ/CreditOrgInfo.asmx?WSDL',
        'market' => 'http://www.cbr.ru/secinfo/secinfo.asmx?WSDL',
    );
    
    /**
     * Массив для кэша объектов SoapClient
     * @var array 
     */
    private $cachedClients = array();
    
    /**
     * Текущая схема
     * @var string
     */
    private $currentSchema = '';
    
    /**
     * Массив с доступными методами текущей схемы
     * @var array
     */
    private $cachedMethodList = array();
    
    /**
     * Возвращаемый результат от SoapClient
     * @var StdClass object
     */
    private $gettedResult = null;
    
    /**
     * Наименование вызываемого метода
     * @var string
     */
    private $calledMethodName = null;
    
    /**
     * Массив параметров для метода
     * @var array
     */
    private $calledParams = array();
    
    /**
     * Сохраняет данные о вызываемом методе
     * @param string $_methodName
     * @param array $_params default null
     * @return $this
     */
    public function __call($_methodName, Array $_params = null) {
        
        $this->calledMethodName = $_methodName;
        $this->calledParams = ($_params) ? $_params[0] : array();
        
        return $this;
    }
    
    /**
     * Возвращает массив с курсами валют на указанную дату
     * @param int/string $date default time() - дата в формате d.m.Y, либо UNIX_TIMESTAMP. По умолчанию текущий день
     * @throws CException
     * @return array
     */
    public function getCoursesList($_date = null) {
        
        $date = $this->getDefaultDate($_date);

        $data = $this->daily()->GetCursOnDate(array('On_date' => $date))->getResultInXMLObject();

        if (!isset($data->ValuteData->ValuteCursOnDate))
            throw new CException('В ответе не найдено данных!');

        return $data->ValuteData->ValuteCursOnDate;
        
    }
    
    /**
     * Возвращает курс указанной валюты на указанную дату
     * @param string $currencyStringCode - код валюты (RUB, EUR, USD, ... etc)
     * @param int/string $date - дата в формате d.m.Y, либо UNIX_TIMESTAMP
     * @return float/null
     */
    public function getCourse($_currencyStringCode, $_date = null) {
        
        $date = $this->getDefaultDate($_date);
        
        $list = $this->getCoursesList($date);

        foreach ($list as $currency) {
            
            if ($currency->VchCode == $_currencyStringCode)
                return round((float)$currency->Vcurs/$currency->Vnom, 4);
            
        }
        
        return null;
        
    }
    
    /**
     * Отображает мануал (сигнатуру) к методу из текущей WSDL-схемы
     * @return void
     */
    public function getMan() {
        
        $this->checkMethodIsExist();
        
        $params = '';
        
        foreach ($this->cachedMethodList[$this->calledMethodName] as $parameter => $type) {
            $params .= $type.' '.$parameter.',';
        }
        
        echo 'Function using: '.html_entity_decode($this->calledMethodName).'('.substr($params, 0, -1).')';
    }
    
    /**
     * Возвращает результат запроса в виде объекта Std-класса
     * @return StdClass object
     */
    public function getRawResultInStd() {
        return (is_null($this->gettedResult)) ? $this->makeRequest() : $this->gettedResult;
    }
    
    /**
     * Возвращает XML-тело ответа в виде SimpleXMLElement-объекта
     * @throws CException
     * @return SimpleXMLElement object
     */
    public function getResultInXMLObject() {
        
        $resultName = $this->calledMethodName.'Result';
        
        $result = $this->getRawResultInStd();
        if (isset($result->{$resultName}, $result->{$resultName}->any))
            return simplexml_load_string($result->{$resultName}->any);
        else
            throw new CException('Невозможно получить данные в виде XMLObject!');
    }
    
    /**
     * Устанавливает текущую WSDL-схему, с которой нужно работать
     * @return object $this
     */
    public function daily() {
        
        $this->currentSchema = 'daily';
        return $this;
        
    }
    
    /**
     * Устанавливает текущую WSDL-схему, с которой нужно работать
     * @return object $this
     */
    public function regions() {
        
        $this->currentSchema = 'regions';
        return $this;
        
    }
    
    /**
     * Устанавливает текущую WSDL-схему, с которой нужно работать
     * @return object $this
     */
    public function organizations() {
        
        $this->currentSchema = 'organizations';
        return $this;
        
    }
    
    /**
     * Устанавливает текущую WSDL-схему, с которой нужно работать
     * @return object $this
     */
    public function market() {
        
        $this->currentSchema = 'market';
        return $this;
        
    }
    
    /**
     * Выполняет запрос к указанному SOAP-серверу
     * @param string $_methodName - наименование вызываемого метода (из WSDL-схемы)
     * @param array $_params - массив с параметрами для метода
     * @throws CException, SoapFault
     * @return StdObject object
     */
    private function makeRequest() {
        
        $startTime = microtime(true);
        try {
            
            $logString = '[START] Query method '.$this->calledMethodName.' with params: '.var_export($this->calledParams, true);
            Yii::log($logString, 'info', 'cbr');
            
            $client = $this->getClient();
            
            //Проверка вызываемого метода и его аргументов
            $this->checkMethodIsExist($this->calledMethodName);
            
            $methodSchemaParams = $this->cachedMethodList[$this->calledMethodName];
            
            if (count($methodSchemaParams) != count($this->calledParams))
                 throw new SoapFault('MustUnderstand', 'Method '.html_entity_decode($this->calledMethodName).' required '.count($methodSchemaParams).' parameters ('.implode(',', array_keys($methodSchemaParams)).'), but only '.count($this->calledParams).' given!');
             
            
            foreach ($this->cachedMethodList[$this->calledMethodName] as $reqiredParam => $dataType) {

                if (!isset($this->calledParams[$reqiredParam])) {
                    throw new SoapFault('VersionMismatch', 'Parameter "'.$reqiredParam.'" with type "'.$this->cachedMethodList[$this->calledMethodName][$reqiredParam].'" for method '.html_entity_decode($this->calledMethodName).' not passed!');
                }
                
                //Конвертация даты в требуемый формат
                if ($dataType == 'dateTime')
                    $this->calledParams[$reqiredParam] = $this->convertToDateTime($this->calledParams[$reqiredParam]);
                    
            }

            //Soap запрос
            $this->gettedResult = $client->__soapCall($this->calledMethodName, array($this->calledParams));

            $logString = '[END] Request successfuly ended (ex.time:'.(microtime(true) - $startTime).'s)'.PHP_EOL.PHP_EOL.PHP_EOL;
            Yii::log($logString, 'info', 'cbr');
            
            return $this->gettedResult;
            
        } catch (SoapFault $fault) {
            
            $errorString = '[ERROR!]'.$fault->faultstring.' on '.$fault->getFile().':'.$fault->getLine().PHP_EOL.PHP_EOL.PHP_EOL;
            
            Yii::log($errorString, $fault->getCode(), 'cbr');
            throw new CException($fault->faultstring);
        } 
    }
    
    /**
     * Возвращает SoapClient с текущей схемой
     * @throws CException
     * @return SoapClient object
     */
    private function getClient() {
        
        if (!$this->currentSchema)
            throw new CException('WSDL schema is not set!');
        
        if (isset($this->cachedClients[$this->currentSchema]))
            return $this->cachedClients[$this->currentSchema];
        else
            return $this->cachedClients[$this->currentSchema] = new SoapClient($this->uri[$this->currentSchema]);
    }
    
    /**
     * Проверяет вызываемый метод на существование в текущей WSDL схеме
     * @throws SoapFault
     * @return void
     */
    private function checkMethodIsExist() {
        
        if (!isset($this->getAvailableFunctions()[$this->calledMethodName]))
            throw new SoapFault('MustUnderstand', 'Function name '.html_entity_decode($this->calledMethodName).' unavailable in this schema ('.$this->uri[$this->currentSchema].')!');
        
    }
    
    /**
     * Возвращает доступные согласно WSDL методы 
     * @return array
     */
    private function getAvailableFunctions() {
        
        if ($this->cachedMethodList)
            return $this->cachedMethodList;
        
        $methods = $this->getClient()->__getTypes();
        
        foreach ($methods as $method) {
            
            preg_match("~struct\s(.*?)\s{(.*?)}~is", $method, $matches);
            //Получаем имя метода
            $name = trim($matches[1]);
            $this->cachedMethodList[$name] = array();
            
            //Получаем требуемые аргументы
            $argsString = explode(';', $matches[2]);
            array_pop($argsString);
            foreach ($argsString as $arg) {
                list($type, $argName) = explode(' ', trim($arg));
                //[ИМЯ_ФУНКЦИИ][ИМЯ_АРГУМЕНТА] => ТИП_АРГУМЕНТА
                $this->cachedMethodList[$name][trim($argName)] = trim($type);
            }
        }
        
        return $this->cachedMethodList;
    }
    
    /**
     * Возвращает System.DateTime от timestamp/date (только Y-m-d конвертация, без времени) для центробанка
     * @param mixed $date - дата (может быть timestamp, либо d.m.Y)
     * @return string
     */
    private function convertToDateTime($_date) {
        
        $timestamp = (is_int($_date)) ? $_date : strtotime($_date);
        
        return date('Y-m-d', $timestamp).'T00:00:00';
        
    }
    
    
    /**
     * Возвращает дату в unix_timestamp. Если даты нет, возвращает текущую.
     * @param mixed $date 
     * @return int
     */
    private function getDefaultDate($_date) {
        
        if (!$_date)
            $timestamp = time();
        else if (is_int($_date)) 
            $timestamp = $_date;
        else
            $timestamp = strtotime($_date);
        
        return $timestamp;
    } 
}