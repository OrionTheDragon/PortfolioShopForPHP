<?php
    // Если сессия ещё не запущена — запускаем её.
    // Это нужно для работы с авторизацией/настройками пользователя в других частях проекта.
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Подключаем вспомогательные файлы:
    // Util.php — разные утилитарные функции проекта.
    // User.php — класс пользователя.
    // db.php — создание и конфигурация PDO-подключения ($pdo).
    require_once __DIR__ . '/Util.php';
    require_once __DIR__ . '/User.php';
    require_once __DIR__ . '/db.php';

    /**
     * Class AdminPanel
     *
     * Класс инкапсулирует всю логику админ-панели:
     *  - назначение администраторов;
     *  - добавление/редактирование товаров;
     *  - просмотр заказов пользователей.
     *
     * Работает поверх PDO-подключения к базе данных.
     */
    class AdminPanel {
        /**
         * @var PDO $pdo Объект подключения к базе данных.
         */
        private PDO $pdo;

        /**
         * Конструктор админ-панели.
         *
         * @param PDO $pdo Активное PDO-подключение к базе данных.
         */
        public function __construct(PDO $pdo) {
            // Сохраняем PDO в поле класса для дальнейшего использования.
            $this->pdo = $pdo;
        }

        /**
         * Назначить пользователю роль администратора.
         *
         * Логика:
         *  1. Проверяем, существует ли пользователь с указанным именем.
         *  2. Если не существует — выводим сообщение об ошибке.
         *  3. Если существует — обновляем поле Access до значения 'admin'.
         *  4. Выводим количество изменённых строк.
         *
         * @param string $userName Имя пользователя (значение поля Name в таблице User_data).
         * @return void
         */
        public function addAdmin(string $userName) : void {
            try {
                // Ищем пользователя с указанным именем.
                $sql = 'SELECT * FROM `User_data` WHERE `Name` = :name LIMIT 1';
                $stmt = $this -> pdo -> prepare($sql);
                $stmt -> execute([':name' => $userName]);
                $exists = $stmt -> fetch(PDO::FETCH_ASSOC);

                // Если пользователя с таким именем нет — выводим ошибку и прекращаем выполнение.
                if (!$exists) {
                    echo "Ошибка: имя пользователя не найдено.";
                    return;
                }
                else {
                    // Пользователь найден — назначаем ему роль администратора.
                    $stmt = $this->pdo->prepare('UPDATE User_data SET Access = :new WHERE Name = :name');
                    $stmt -> execute([':new' => 'admin', ':name' => $userName]);

                    // Сообщаем, сколько строк было изменено (в норме должна быть 1).
                    echo $stmt -> rowCount() . " строк(и) изменено.";
                }
            }
            catch (PDOException $e) {
                // В случае ошибки БД
                error_log($e->getMessage());
            }
        }

        /**
         * Внутренний справочник категорий товаров.
         *
         * Возвращает массив "ID категории => читаемое название".
         * Эти ID используются как числовые коды категорий в БД (поле category таблицы Goods1).
         *
         * @return array<int,string> Список доступных категорий.
         */
        private function getCategoryNames() : array {
            return [
                0 => 'Хлеб',
                1 => 'Напитки',
                2 => 'Бакалея',
                3 => 'Мясо',
                4 => 'Молочная продукция',
                5 => 'Овощи и фрукты',
            ];
        }

        /**
         * Получить список товаров по ID категории.
         *
         * @param int $category ID категории (должен совпадать с ключами из getCategoryNames()).
         * @return array<int,array<string,mixed>> Массив товаров (каждый элемент с ключами SKU и productName).
         */
        public function getGoodsByCategory(int $category) : array {
            // Готовим запрос на выборку товаров по категории.
            $stmt = $this -> pdo -> prepare("
                SELECT SKU, productName 
                FROM Goods1 
                WHERE category = :cat
                ORDER BY SKU ASC
            ");

            // Категория приводится к строке, так как в БД, хранится как строковый тип.
            $stmt -> execute([':cat' => (string)$category]);

            // Возвращаем все найденные строки в виде массива ассоциативных массивов.
            return $stmt -> fetchAll(PDO::FETCH_ASSOC);
        }

        /**
         * Получить информацию о конкретном товаре по его SKU.
         *
         * Возвращает полный набор полей, необходимых для отображения и редактирования.
         *
         * @param string $sku Код SKU товара (уникальный идентификатор).
         * @return array<string,mixed>|null Ассоциативный массив полей товара или null, если товар не найден.
         */
        public function getGoodBySku(string $sku) : ?array {
            // Готовим запрос на выборку одного товара по SKU.
            $stmt = $this -> pdo -> prepare("
                SELECT SKU, productName, manufacturer, country, category, type, price, quantity
                FROM Goods1
                WHERE SKU = :sku
                LIMIT 1
            ");

            $stmt -> execute([':sku' => $sku]);
            $row = $stmt -> fetch(PDO::FETCH_ASSOC);

            // Если товар не найден, возвращаем null.
            return $row ?: null;
        }

        /**
         * Добавление нового товара в таблицу Goods1.
         *
         * Ожидает данные из формы (обычно $_POST):
         *  - category      — ID категории;
         *  - productName   — название товара;
         *  - manufacturer  — производитель;
         *  - country       — страна;
         *  - type          — тип ("Штучный" или "Весовой");
         *  - price         — цена;
         *  - quantity      — количество.
         *
         * Логика:
         *  1. Проверяем корректность категории.
         *  2. Валидируем текстовые поля (название, производитель, страна).
         *  3. Проверяем цену и количество.
         *  4. Нормализуем тип (любой кроме "Весовой" считается "Штучный").
         *  5. Генерируем новый SKU как (MAX(SKU) + 1) с добитием нулями до 5 символов.
         *  6. Сохраняем товар в БД и выводим созданный SKU.
         *
         * @param array<string,mixed> $data Данные формы.
         * @return void
         */
        public function addGoods(array $data) : void {
            try {
                // Получаем справочник категорий.
                $names = $this -> getCategoryNames();

                // Извлекаем и приводим к int ID категории.
                $categoryId = isset($data['category']) ? (int)$data['category'] : -1;

                // Проверяем, существует ли такая категория в справочнике.
                if (!array_key_exists($categoryId, $names)) {
                    echo "Ошибка: неверная категория.";
                    return;
                }

                // Забираем значения из массива данных, обрезаем пробелы.
                $productName = trim($data['productName'] ?? '');
                $manufacturer = trim($data['manufacturer'] ?? '');
                $country = trim($data['country'] ?? '');
                $typeRaw = trim($data['type'] ?? '');
                $price = (float)($data['price'] ?? 0);
                $quantity = (int)($data['quantity'] ?? 0);

                // Проверяем обязательные текстовые поля.
                if ($productName === '' || $manufacturer === '' || $country === '') {
                    echo "Ошибка: заполните все текстовые поля.";
                    return;
                }

                // Проверяем числовые значения.
                if ($price <= 0 || $quantity < 0) {
                    echo "Ошибка: некорректные цена или количество.";
                    return;
                }

                // Тип товара: допускаются только два значения.
                // Всё, что не "Весовой" — приравнивается к "Штучный".
                $type = ($typeRaw === 'Весовой') ? 'Весовой' : 'Штучный';

                // Генерируем следующий свободный SKU:
                // берём максимальный существующий SKU, увеличиваем на 1.
                $sqlMax = "SELECT MAX(SKU) AS maxSku FROM Goods1";
                $stmtMax = $this -> pdo -> query($sqlMax);
                $rowMax = $stmtMax -> fetch(PDO::FETCH_ASSOC);

                $maxSku = isset($rowMax['maxSku']) ? (int)$rowMax['maxSku'] : 0;
                $nextSkuNum = $maxSku + 1;

                // Преобразуем число к строке фиксированной длины 5 с ведущими нулями: 1 -> "00001".
                $sku = str_pad((string)$nextSkuNum, 5, '0', STR_PAD_LEFT);

                // Вставляем новый товар в таблицу Goods1.
                $sql = "INSERT INTO Goods1 (SKU, productName, manufacturer, country, category, type, price, quantity)
                        VALUES (:sku, :productName, :manufacturer, :country, :category, :type, :price, :quantity)";

                $stmt = $this -> pdo -> prepare($sql);
                $stmt -> execute([
                    ':sku' => $sku,
                    ':productName' => $productName,
                    ':manufacturer' => $manufacturer,
                    ':country' => $country,
                    ':category' => (string)$categoryId,
                    ':type' => $type,
                    ':price' => $price,
                    ':quantity' => $quantity,
                ]);

                // Выводим пользователю подтверждение и безопасно отображаем SKU.
                echo "Товар добавлен. SKU: " . htmlspecialchars($sku, ENT_QUOTES, 'UTF-8');
            }
            catch (PDOException $e) {
                // При ошибке вставки/подключения выводим общее сообщение.
                echo "Ошибка при добавлении товара.";
            }
        }

        /**
         * Просмотр заказов (корзин) конкретного пользователя.
         *
         * Источник данных — таблица Basket_User:
         *  - User_ID  — владелец корзины;
         *  - Goods    — JSON-строка вида {"SKU1": кол-во, "SKU2": кол-во, ...};
         *  - Status   — статус корзины ('open', 'orders', 'cleared');
         *
         * Результат:
         *  [
         *    'open' => [
         *        [
         *          'basketID' => int,
         *          'items' => [
         *             [
         *                'productName' => string,
         *                'price'       => float,
         *                'Quantity'    => int
         *             ],
         *             ...
         *          ]
         *        ],
         *        ...
         *    ],
         *    'orders' => [...],
         *    'cleared' => [...]
         *  ]
         *
         * @param int $userID ID пользователя, для которого отображаются заказы.
         * @return array<string,array<int,array<string,mixed>>> Структура корзин по статусам.
         */
        public function viewOrders(int $userID) : array {
            // Получаем все корзины пользователя с интересующими статусами.
            $stmt = $this -> pdo -> prepare("
                SELECT ID, Goods, Status 
                FROM Basket_User 
                WHERE User_ID = :uid 
                AND Status IN ('open', 'orders', 'cleared')
                ORDER BY ID ASC
            ");
            $stmt -> execute([':uid' => $userID]);
            $rows = $stmt -> fetchAll(PDO::FETCH_ASSOC);

            // Базовая структура результата по статусам.
            $result = [
                'open'    => [],
                'orders'  => [],
                'cleared' => [],
            ];

            // Если корзин нет — возвращаем пустые массивы по статусам.
            if (!$rows) {
                return $result;
            }

            // Обрабатываем каждую корзину.
            foreach ($rows as $row) {
                $status = $row['Status'] ?? '';

                // Перестраховка: если статус неизвестный (ошибка в БД), пропускаем корзину.
                if (!isset($result[$status])) {
                    continue;
                }

                $basketID = (int)$row['ID'];

                // JSON с товарами: ожидаем что-то вида {"SKU1": кол-во, "SKU2": кол-во}
                $raw = $row['Goods'] ?? '{}';
                $items_json = json_decode($raw, true);

                // Если JSON пустой или некорректный — считаем корзину пустой.
                if (!is_array($items_json) || empty($items_json)) {
                    
                    // Для пустой корзины фиксируем, что items = [].
                    $result[$status][] = [
                        'basketID' => $basketID,
                        'items' => [],
                    ];
                    continue;
                }

                // Преобразуем JSON в массив SKU => количество (с приведением типов).
                $basketItems = [];
                foreach ($items_json as $sku => $quantity) {
                    $basketItems[(string)$sku] = (int)$quantity;
                }

                // Список SKU, которые нужно вытянуть из таблицы Goods1.
                $skus = array_keys($basketItems);

                // Если список SKU пуст — добавляем корзину без позиций.
                if (empty($skus)) {
                    $result[$status][] = [
                        'basketID' => $basketID,
                        'items'    => [],
                    ];
                    continue;
                }

                // Подтягиываем инфу
                // Формируем список плейсхолдеров "?, ?, ?, ..." для IN().
                $placeholders = rtrim(str_repeat('?,', count($skus)), ',');
                $sql = "SELECT SKU, productName, price FROM Goods1 WHERE SKU IN ($placeholders)";
                $stmt2 = $this -> pdo -> prepare($sql);

                // В execute передаём массив SKU, соответствующих ? в запросе.
                $stmt2 -> execute($skus);
                $goodsInfo = $stmt2 -> fetchAll(PDO::FETCH_ASSOC);

                // Формируем итоговый список позиций корзины со связкой на товары.
                $finalItems = [];
                foreach ($goodsInfo as $good) {
                    $sku = $good['SKU'];
                    // Привязываем только те товары, которые реально есть в массиве basketItems.
                    if (isset($basketItems[$sku])) {
                        $finalItems[] = [
                            'productName' => $good['productName'],
                            'price'       => (float)$good['price'],
                            'Quantity'    => $basketItems[$sku],
                        ];
                    }
                }

                // Добавляем корзину в результат по её статусу.
                $result[$status][] = [
                    'basketID' => $basketID,
                    'items'    => $finalItems,
                ];
            }

            return $result;
        }

        /**
         * Редактирование существующего товара.
         *
         * Ожидает данные из формы (обычно $_POST) при отправке формы редактирования:
         *  - sku          — идентификатор товара (скрытое поле, не меняется);
         *  - productName  — новое название;
         *  - manufacturer — новый производитель;
         *  - country      — новая страна;
         *  - type         — новый тип;
         *  - price        — новая цена;
         *  - quantity     — новое количество.
         *
         * Выполняет валидацию и обновляет запись в таблице Goods1.
         *
         * @return void
         */
        public function editGoods() : void {
            // SKU должен быть передан обязательно, иначе мы не знаем, что редактировать.
            if (empty($_POST['sku'])) {
                echo "Ошибка: не передан SKU товара.";
                return;
            }

            // Получаем SKU редактируемого товара.
            $sku = $_POST['sku'];

            // Считываем и нормализуем все поля из формы.
            $productName = trim($_POST['productName']  ?? '');
            $manufacturer = trim($_POST['manufacturer'] ?? '');
            $country = trim($_POST['country'] ?? '');
            $typeRaw = trim($_POST['type'] ?? '');
            $price = (float)($_POST['price'] ?? 0);
            $quantity = (int)($_POST['quantity'] ?? 0);

            // Проверяем обязательные текстовые поля.
            if ($productName === '' || $manufacturer === '' || $country === '') {
                echo "Ошибка: заполните все текстовые поля.";
                return;
            }

            // Проверяем значения цены и количества.
            if ($price <= 0 || $quantity < 0) {
                echo "Ошибка: некорректные цена или количество.";
                return;
            }

            // Нормализация типа товара.
            $type = ($typeRaw === 'Весовой') ? 'Весовой' : 'Штучный';

            try {
                // Обновляем данные товара по его SKU.
                $sql = "UPDATE Goods1
                        SET productName = :productName,
                            manufacturer = :manufacturer,
                            country = :country,
                            type = :type,
                            price = :price,
                            quantity = :quantity
                        WHERE SKU = :sku";

                $stmt = $this -> pdo -> prepare($sql);
                $stmt -> execute([
                    ':productName' => $productName,
                    ':manufacturer' => $manufacturer,
                    ':country' => $country,
                    ':type' => $type,
                    ':price' => $price,
                    ':quantity' => $quantity,
                    ':sku' => $sku,
                ]);

                // Выводим сообщение об успешном обновлении.
                echo "Товар с SKU " . htmlspecialchars($sku, ENT_QUOTES, 'UTF-8') . " обновлён.";
            }
            catch (PDOException $e) {
                // При ошибке обновления выводим общее сообщение.
                echo "Ошибка при обновлении товара.";
            }
        }

        /**
         * Вспомогательная функция для {@link self::viewOrders()}.
         *
         * Возвращает список всех пользователей в виде массива с полями ID и Name.
         * Используется при выборе пользователя для просмотра его заказов в админ-панели.
         *
         * @return array<int,array<string,mixed>> Список пользователей (ID, Name).
         */
        public function getUsersList() : array {
            $stmt = $this -> pdo -> query("SELECT ID, Name FROM User_data ORDER BY Name ASC");
            return $stmt -> fetchAll(PDO::FETCH_ASSOC);
        }

        /**
         * Публичный доступ к справочнику категорий.
         *
         * @return array<int,string> Массив ID категории => название.
         */
        public function getCategoriesMap() : array {
            return $this -> getCategoryNames();
        }
    }

    // Создаём экземпляр админ-панели, передавая ему PDO-подключение.
    $admin = new AdminPanel($pdo);

    // Определяем текущую "команду" админ-панели по параметру action в GET.
    // Возможные значения:
    //  - '' (пусто)         — главная страница админ-панели;
    //  - addAdmin           — форма выдачи админки;
    //  - addAdminForUser    — обработчик POST для выдачи админки;
    //  - addGoods           — форма + обработчик добавления товара;
    //  - editGoods          — страница редактирования товаров;
    //  - viewOrders         — просмотр заказов пользователя.
    $action = $_GET['action'] ?? '';

    // Обработка выдачи админ-прав конкретному пользователю.
    if ($action === 'addAdminForUser' && !empty($_POST['userRegName'])) {
        $admin -> addAdmin($_POST['userRegName']);
    }

    // Обработка отправки формы добавления товара.
    if ($action === 'addGoods' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $admin -> addGoods($_POST);
    }

    // Обработка отправки формы редактирования товара (кнопка "Сохранить").
    if ($action === 'editGoods' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
        $admin -> editGoods();
    }

    // Справочник категорий для использования в шаблоне (в select'ах и т.п.).
    $categoryNames = $admin -> getCategoriesMap();

    // Переменные, связанные с режимом редактирования товара.
    $editCategoryId = -1;   // текущая выбранная категория в блоке "Редактировать товар"
    $editGoodsList = [];    // список товаров выбранной категории
    $editSku = '';          // выбранный SKU для редактирования
    $editGood = null;       // данные конкретного товара по выбранному SKU

    // Если мы на странице редактирования товаров — определяем, какая категория/товар выбраны.
    if ($action === 'editGoods') {
        // что выбрано сейчас (из GET или POST – чтобы не ломаться после сохранения)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // После сохранения данные приходят через POST.
            $editCategoryId = isset($_POST['category']) ? (int)$_POST['category'] : -1;
            $editSku = $_POST['sku'] ?? '';
        }
        else {
            // При обычной навигации/выборе категорий данные приходят через GET.
            $editCategoryId = isset($_GET['category']) ? (int)$_GET['category'] : -1;
            $editSku = $_GET['sku'] ?? '';
        }

        // Если валидная категория выбрана — подгружаем список товаров для select'a.
        if ($editCategoryId >= 0 && array_key_exists($editCategoryId, $categoryNames)) {
            $editGoodsList = $admin -> getGoodsByCategory($editCategoryId);
        }

        // Если выбран конкретный SKU — получаем данные этого товара.
        if ($editSku !== '') {
            $editGood = $admin -> getGoodBySku($editSku);
        }
    }

    // Переменные для режима просмотра заказов.
    $usersList = [];  // Список пользователей для выпадающего списка.

    // Структура корзин по статусам для выбранного пользователя.
    $basketItems = [
        'open' => [],
        'orders' => [],
        'cleared' => [],
    ];

    // ID выбранного пользователя (по умолчанию 0 — не выбран).
    $selectedUserId = 0;

    // читаемые названия статусов заказов.
    $statusNames = [
        'open' => 'Открыта',
        'orders' => 'Оформлен',
        'cleared' => 'Отменён',
    ];

    // Если выбрано действие "viewOrders" — готовим данные для просмотра заказов.
    if ($action === 'viewOrders') {
        // Получаем список всех пользователей для select'а.
        $usersList = $admin -> getUsersList();

        // Если выбран конкретный пользователь — подгружаем его корзины.
        if (isset($_GET['userId'])) {
            $selectedUserId = (int)$_GET['userId'];
            if ($selectedUserId > 0) {
                $basketItems = $admin -> viewOrders($selectedUserId);
            }
        }
    }
?>

<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Админ панель</title>
        <style>
            /* Скрытый блок (страница), который показывается по чекбоксу */
            .hidden-page {
            display: none;
            padding: 20px;
            background: #eef;
            border: 1px solid #99c;
            margin-top: 20px;
            }

            /* Если чекбокс с id="showPage" отмечен, показываем .hidden-page */
            #showPage:checked ~ .hidden-page {
                display: block;
            }
        </style>
    </head>
    <body>
        <main class="basic">
            <?php if ($action === ''): ?>
                <!-- Главное меню админ-панели: выбор нужного действия -->
                <section>
                    <a href="?action=addAdmin">
                        Выдать админку
                    </a>
                </section>
                <section>
                    <a href="?action=addGoods">
                        Добавить товар
                    </a>
                </section>
                <section>
                    <a href="?action=editGoods">
                        Редактировать товар
                    </a>
                </section>
                <section>
                    <a href="?action=viewOrders">
                        Просмотреть список заказов
                    </a>
                </section>
                <section>
                    <a href="PA.php">
                        Назад
                    </a>
                </section>

            <?php elseif ($action === 'addAdmin'): ?>
                <!-- Страница выдачи админ-прав пользователю -->
                <h1>Выдать админку</h1>
                <form method="post" action="?action=addAdminForUser">
                    <section>
                        <h5>Имя зарегистрированного пользователя.<br></h5>
                        <!-- Имя должно совпадать с тем, что хранится в таблице User_data.Name -->
                        <input type="text" id="user_reg_name" name="userRegName" maxlength="30" placeholder="Имя" required value="">
                    </section>
                    <section>
                         <button type="submit">
                            Выдать админ роль
                        </button>
                    </section>
                </form>
            <?php elseif ($action === 'addGoods'): ?>
                <!-- Страница добавления нового товара -->
                <h1>Добавить товар</h1>
                <?php
                    // Локальный массив категорий для выпадающего списка.
                    // Логика дублирует getCategoryNames(), но используется прямо в шаблоне.
                    $names = [
                        0 => 'Хлеб',
                        1 => 'Напитки',
                        2 => 'Бакалея',
                        3 => 'Мясо',
                        4 => 'Молочная продукция',
                        5 => 'Овощи и фрукты',
                    ];
                ?>
                <form method="post" action="?action=addGoods">
                    <section>
                        <label>
                            Категория:<br>
                            <select name="category">
                                <?php foreach ($names as $id => $label): ?>
                                    <option value="<?= $id ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </section>
                    <section>
                        <label>
                            Название товара:<br>
                            <input type="text" name="productName" maxlength="255" required>
                        </label>
                    </section>
                    <section>
                        <label>
                            Производитель:<br>
                            <input type="text" name="manufacturer" maxlength="255" required>
                        </label>
                    </section>
                    <section>
                        <label>
                            Страна:<br>
                            <input type="text" name="country" maxlength="255" required>
                        </label>
                    </section>
                    <section>
                        <label>
                            Тип:<br>
                            <select name="type">
                                <option value="Штучный">Штучный</option>
                                <option value="Весовой">Весовой</option>
                            </select>
                        </label>
                    </section>
                    <section>
                        <label>
                            Цена:<br>
                            <!-- step="5.0" позволяет вводить дробную цену -->
                            <input type="number" step="5.0" name="price" required>
                        </label>
                    </section>
                    <section>
                        <label>
                            Количество:<br>
                            <input type="number" name="quantity" required>
                        </label>
                    </section>
                    <section>
                        <button type="submit">
                            Добавить товар
                        </button>
                    </section>
                </form>
            <?php elseif ($action === 'editGoods'): ?>
                <!-- Страница редактирования товара -->
                <h1>Редактировать товар</h1>

                <!-- Шаг 1: выбор категории -->
                <form method="get" action="">
                    <input type="hidden" name="action" value="editGoods">
                    <section>
                        <label>
                            Категория:<br>
                            <select name="category">
                                <option value="-1">
                                    -- выберите категорию --
                                </option>
                                <?php foreach ($categoryNames as $id => $label): ?>
                                    <option value="<?= $id ?>" <?= $editCategoryId === $id ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </section>
                    <section>
                        <button type="submit">
                            Показать товары
                        </button><br>
                        <a href="AdminPanel.php">
                            Назад
                        </a>
                    </section>
                </form>

                <?php if ($editCategoryId >= 0 && !empty($editGoodsList)): ?>
                    <!-- Шаг 2: выбор товара внутри выбранной категории -->
                    <form method="get" action="">
                        <input type="hidden" name="action" value="editGoods">
                        <input type="hidden" name="category" value="<?= $editCategoryId ?>">
                        <section>
                            <label>
                                Товар:<br>
                                <select name="sku">
                                    <option value="">
                                        -- выберите товар --
                                    </option>
                                    <?php foreach ($editGoodsList as $g): ?>
                                        <?php $sku = $g['SKU']; ?>
                                        <option value="<?= htmlspecialchars($sku, ENT_QUOTES, 'UTF-8') ?>" <?= $editSku === $sku ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($sku . ' — ' . $g['productName'], ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </section>
                        <section>
                            <button type="submit">
                                Редактировать
                            </button>
                        </section>
                    </form>
                <?php elseif ($editCategoryId >= 0): ?>
                    <!-- Если в выбранной категории нет товаров -->
                    <p>
                        В этой категории пока нет товаров.
                    </p>
                <?php endif; ?>

                <?php if ($editGood): ?>
                    <!-- Шаг 3: форма редактирования конкретного товара -->
                    <h2>Редактирование товара</h2>

                    <form method="post" action="?action=editGoods" id="editGoodForm">
                        <!-- SKU и категория только для информации, но не изменяются -->
                        <input type="hidden" name="sku" value="<?= htmlspecialchars($editGood['SKU'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="category" value="<?= (int)$editGood['category'] ?>">

                        <table>
                            <tr>
                                <td>
                                    Категория:
                                </td>
                                <td>
                                    <input type="text"
                                        value="<?= htmlspecialchars($categoryNames[(int)$editGood['category']] ?? $editGood['category'], ENT_QUOTES, 'UTF-8') ?>"
                                        readonly>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    SKU:
                                </td>
                                <td>
                                    <input type="text"
                                        value="<?= htmlspecialchars($editGood['SKU'], ENT_QUOTES, 'UTF-8') ?>"
                                        readonly>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    Название товара:
                                </td>
                                <td>
                                    <!-- data-original хранит исходное значение, чтобы JS мог отслеживать изменения -->
                                    <input type="text"
                                        name="productName"
                                        value="<?= htmlspecialchars($editGood['productName'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-original="<?= htmlspecialchars($editGood['productName'], ENT_QUOTES, 'UTF-8') ?>">
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    Производитель:
                                </td>
                                <td>
                                    <input type="text"
                                        name="manufacturer"
                                        value="<?= htmlspecialchars($editGood['manufacturer'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-original="<?= htmlspecialchars($editGood['manufacturer'], ENT_QUOTES, 'UTF-8') ?>">
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    Страна:
                                </td>
                                <td>
                                    <input type="text"
                                        name="country"
                                        value="<?= htmlspecialchars($editGood['country'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-original="<?= htmlspecialchars($editGood['country'], ENT_QUOTES, 'UTF-8') ?>">
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    Тип:
                                </td>
                                <td>
                                    <select name="type" data-original="<?= htmlspecialchars($editGood['type'], ENT_QUOTES, 'UTF-8') ?>">
                                        <option value="Штучный" <?= $editGood['type'] === 'Штучный' ? 'selected' : '' ?>>
                                            Штучный
                                        </option>
                                        <option value="Весовой" <?= $editGood['type'] === 'Весовой' ? 'selected' : '' ?>>
                                            Весовой
                                        </option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    Цена:
                                </td>
                                <td>
                                    <!-- step=5.0 — шаг изменения цены в интерфейсе, можно менять по желанию -->
                                    <input type="number"
                                        step="5.0"
                                        name="price"
                                        value="<?= htmlspecialchars($editGood['price'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-original="<?= htmlspecialchars($editGood['price'], ENT_QUOTES, 'UTF-8') ?>">
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    Количество:
                                </td>
                                <td>
                                    <input type="number"
                                        name="quantity"
                                        value="<?= (int)$editGood['quantity'] ?>"
                                        data-original="<?= (int)$editGood['quantity'] ?>">
                                </td>
                            </tr>
                        </table>

                        <section>
                            <!-- Кнопка "Сохранить" изначально отключена, пока ничего не изменено -->
                            <button type="submit" name="save" disabled>
                                Сохранить
                            </button>
                            <!-- Ссылка на сброс изменений к исходным значениям -->
                            <a href="AdminPanel.php?action=editGoods&category=<?= $editCategoryId ?>&sku=<?= htmlspecialchars($editGood['SKU'], ENT_QUOTES, 'UTF-8') ?>">
                                Сбросить изменения
                            </a>
                        </section>
                    </form>

                    <script>
                    // кнопка "Сохранить" активна только если что-то изменилось
                    document.addEventListener('DOMContentLoaded', function () {
                        var form = document.getElementById('editGoodForm');
                        if (!form) { 
                            return;
                        }

                        // Берём все элементы, у которых есть атрибут data-original (исходное значение).
                        var inputs = form.querySelectorAll('input[data-original], select[data-original]');
                        var saveBtn = form.querySelector('button[name="save"]');

                        // Проверяем, изменилось ли хоть одно поле по сравнению с исходным значением.
                        function checkChanged() {
                            var changed = false;
                            inputs.forEach(function (el) {
                                if (el.value !== el.getAttribute('data-original')) {
                                    changed = true;
                                }
                            });
                            // Если что-то изменилось — включаем кнопку "Сохранить".
                            saveBtn.disabled = !changed;
                        }

                        // Навешиваем обработчики на изменение значений полей.
                        inputs.forEach(function (el) {
                            el.addEventListener('input', checkChanged);
                            el.addEventListener('change', checkChanged);
                        });

                        // Первичная проверка при загрузке страницы.
                        checkChanged();
                    });
                    </script>

                <?php endif; ?>

            <?php elseif ($action === 'viewOrders'): ?>
                <!-- Страница просмотра заказов пользователя -->
                <h1>Просмотр заказов пользователя</h1>
                <!-- Форма выбора пользователя -->
                <form method="get" action="">
                    <input type="hidden" name="action" value="viewOrders">
                    <section>
                        <label>
                            Пользователь:<br>
                            <select name="userId">
                                <option value="0">
                                    -- выберите пользователя --
                                </option>
                                <?php foreach ($usersList as $u): ?>
                                    <?php $uid = (int)$u['ID']; ?>
                                    <option value="<?= $uid ?>" <?= $selectedUserId === $uid ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($u['Name'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </section>
                    <section>
                        <button type="submit">
                            Показать заказы
                        </button><br>
                        <a href="AdminPanel.php">
                            Назад
                        </a>
                    </section>
                </form>

                <?php if ($selectedUserId > 0): ?>
                    <?php if (empty($basketItems['open']) && empty($basketItems['orders']) && empty($basketItems['cleared'])): ?>
                        <!-- Если у выбранного пользователя пока нет ни одной корзины -->
                        <p>
                            Для этого пользователя заказов пока нет.
                        </p>
                    <?php else: ?>
                        <?php
                            // Чтобы заголовки были по-русски и отображались в заданном порядке.
                            $orderStatusList = ['open', 'orders', 'cleared'];
                        ?>
                        <?php foreach ($orderStatusList as $status): ?>
                            <?php if (!empty($basketItems[$status])): ?>
                                <h2>
                                    <?= $statusNames[$status] ?>
                                </h2>

                                <?php foreach ($basketItems[$status] as $basket): ?>
                                    <!-- Таблица по каждой корзине конкретного статуса -->
                                    <table border="1" cellpadding="5" cellspacing="0">
                                        <thead>
                                            <tr>
                                                <th colspan="4">
                                                    Корзина №
                                                    <?= (int)$basket['basketID'] ?> 
                                                        — статус: 
                                                    <?= $statusNames[$status] ?>
                                                </th>
                                            </tr>
                                            <tr>
                                                <th>
                                                    Наименование товара
                                                </th>
                                                <th>
                                                    Цена за шт.
                                                </th>
                                                <th>
                                                    Кол-во
                                                </th>
                                                <th>
                                                    Сумма
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                                // Подсчитываем итоговую сумму по корзине.
                                                $basketTotal = 0.0;
                                                foreach ($basket['items'] as $item):
                                                    $itemTotal = $item['price'] * $item['Quantity'];
                                                    $basketTotal += $itemTotal;
                                            ?>
                                                <tr>
                                                    <td>
                                                        <?= htmlspecialchars($item['productName'], ENT_QUOTES, 'UTF-8') ?>
                                                    </td>
                                                    <td>
                                                        <?= number_format($item['price'], 2, ',', ' ') ?> руб.
                                                    </td>
                                                    <td>
                                                        <?= (int)$item['Quantity'] ?> шт.
                                                    </td>
                                                    <td>
                                                        <?= number_format($itemTotal, 2, ',', ' ') ?> руб.
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <td colspan="3">
                                                    <b>
                                                        Итого по корзине:
                                                    </b>
                                                </td>
                                                <td>
                                                    <b>
                                                        <?= number_format($basketTotal, 2, ',', ' ') ?> руб.
                                                    </b>
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table><br>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </body>
</html>