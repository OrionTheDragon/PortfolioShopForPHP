<?php
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    require_once __DIR__ . '/Util.php';
    require_once __DIR__ . '/User.php';
    require_once __DIR__ . '/db.php';

    class AdminPanel {
        private PDO $pdo;

        public function __construct(PDO $pdo) {
            $this->pdo = $pdo;
        }

        public function addAdmin(string $userName) : void {
            try {
                $sql = 'SELECT * FROM `User_data` WHERE `Name` = :name LIMIT 1';
                $stmt = $this -> pdo -> prepare($sql);
                $stmt -> execute([':name' => $userName]);
                $exists = $stmt -> fetch(PDO::FETCH_ASSOC);

                if (!$exists) {
                    echo "Ошибка: имя пользователя не найдено.";
                    return;
                }
                else {
                    $stmt = $this->pdo->prepare('UPDATE User_data SET Access = :new WHERE Name = :name');
                    $stmt -> execute([':new' => 'admin', ':name' => $userName]);
                    echo $stmt -> rowCount() . " строк(и) изменено.";
                }
            }
            catch (PDOException $e) {
                echo '';
            }
        }

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

        public function getGoodsByCategory(int $category) : array {
            $stmt = $this -> pdo -> prepare("
                SELECT SKU, productName 
                FROM Goods1 
                WHERE category = :cat
                ORDER BY SKU ASC
            ");

            $stmt -> execute([':cat' => (string)$category]);
            return $stmt -> fetchAll(PDO::FETCH_ASSOC);
        }

        public function getGoodBySku(string $sku) : ?array {
            $stmt = $this -> pdo -> prepare("
                SELECT SKU, productName, manufacturer, country, category, type, price, quantity
                FROM Goods1
                WHERE SKU = :sku
                LIMIT 1
            ");

            $stmt -> execute([':sku' => $sku]);
            $row = $stmt -> fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        }

        public function addGoods(array $data) : void {
            try {
                $names = $this -> getCategoryNames();

                $categoryId = isset($data['category']) ? (int)$data['category'] : -1;

                if (!array_key_exists($categoryId, $names)) {
                    echo "Ошибка: неверная категория.";
                    return;
                }

                $productName = trim($data['productName'] ?? '');
                $manufacturer = trim($data['manufacturer'] ?? '');
                $country = trim($data['country'] ?? '');
                $typeRaw = trim($data['type'] ?? '');
                $price = (float)($data['price'] ?? 0);
                $quantity = (int)($data['quantity'] ?? 0);

                if ($productName === '' || $manufacturer === '' || $country === '') {
                    echo "Ошибка: заполните все текстовые поля.";
                    return;
                }

                if ($price <= 0 || $quantity < 0) {
                    echo "Ошибка: некорректные цена или количество.";
                    return;
                }

                // Тип
                $type = ($typeRaw === 'Весовой') ? 'Весовой' : 'Штучный';

                // Генерируем следующий SKU
                $sqlMax = "SELECT MAX(SKU) AS maxSku FROM Goods1";
                $stmtMax = $this -> pdo -> query($sqlMax);
                $rowMax = $stmtMax -> fetch(PDO::FETCH_ASSOC);

                $maxSku = isset($rowMax['maxSku']) ? (int)$rowMax['maxSku'] : 0;
                $nextSkuNum = $maxSku + 1;

                $sku = str_pad((string)$nextSkuNum, 5, '0', STR_PAD_LEFT);

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

                echo "Товар добавлен. SKU: " . htmlspecialchars($sku, ENT_QUOTES, 'UTF-8');
            }
            catch (PDOException $e) {
                echo "Ошибка при добавлении товара.";
            }
        }

        public function viewOrders(int $userID) : array {
            $stmt = $this -> pdo -> prepare("
                SELECT ID, Goods, Status 
                FROM Basket_User 
                WHERE User_ID = :uid 
                AND Status IN ('open', 'orders', 'cleared')
                ORDER BY ID ASC
            ");
            $stmt -> execute([':uid' => $userID]);
            $rows = $stmt -> fetchAll(PDO::FETCH_ASSOC);

            $result = [
                'open'    => [],
                'orders'  => [],
                'cleared' => [],
            ];

            if (!$rows) {
                return $result;
            }

            foreach ($rows as $row) {
                $status = $row['Status'] ?? '';

                // Перестраховка, если вдруг в обозначении статуса будет ошибка, мало ли что
                if (!isset($result[$status])) {
                    continue;
                }

                $basketID = (int)$row['ID'];

                $raw = $row['Goods'] ?? '{}';
                $items_json = json_decode($raw, true);

                if (!is_array($items_json) || empty($items_json)) {
                    
                    // Для пустой корзины
                    $result[$status][] = [
                        'basketID' => $basketID,
                        'items'    => [],
                    ];
                    continue;
                }

                // Преобразуем JSON в SKU -> количество
                $basketItems = [];
                foreach ($items_json as $sku => $quantity) {
                    $basketItems[(string)$sku] = (int)$quantity;
                }

                $skus = array_keys($basketItems);

                if (empty($skus)) {
                    $result[$status][] = [
                        'basketID' => $basketID,
                        'items'    => [],
                    ];
                    continue;
                }

                // Подтягиываем инфу
                $placeholders = rtrim(str_repeat('?,', count($skus)), ',');
                $sql = "SELECT SKU, productName, price FROM Goods1 WHERE SKU IN ($placeholders)";
                $stmt2 = $this -> pdo -> prepare($sql);
                $stmt2 -> execute($skus);
                $goodsInfo = $stmt2 -> fetchAll(PDO::FETCH_ASSOC);

                $finalItems = [];
                foreach ($goodsInfo as $good) {
                    $sku = $good['SKU'];
                    if (isset($basketItems[$sku])) {
                        $finalItems[] = [
                            'productName' => $good['productName'],
                            'price'       => (float)$good['price'],
                            'Quantity'    => $basketItems[$sku],
                        ];
                    }
                }

                $result[$status][] = [
                    'basketID' => $basketID,
                    'items'    => $finalItems,
                ];
            }

            return $result;
        }

        public function editGoods() : void {
            if (empty($_POST['sku'])) {
                echo "Ошибка: не передан SKU товара.";
                return;
            }

            $sku = $_POST['sku'];

            $productName = trim($_POST['productName']  ?? '');
            $manufacturer = trim($_POST['manufacturer'] ?? '');
            $country = trim($_POST['country'] ?? '');
            $typeRaw = trim($_POST['type'] ?? '');
            $price = (float)($_POST['price'] ?? 0);
            $quantity = (int)($_POST['quantity'] ?? 0);

            if ($productName === '' || $manufacturer === '' || $country === '') {
                echo "Ошибка: заполните все текстовые поля.";
                return;
            }

            if ($price <= 0 || $quantity < 0) {
                echo "Ошибка: некорректные цена или количество.";
                return;
            }

            $type = ($typeRaw === 'Весовой') ? 'Весовой' : 'Штучный';

            try {
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

                echo "Товар с SKU " . htmlspecialchars($sku, ENT_QUOTES, 'UTF-8') . " обновлён.";
            }
            catch (PDOException $e) {
                echo "Ошибка при обновлении товара.";
            }
        }

        /**
         * вспомогательная функция для {@link self::viewOrders()}
         * @return array -> Возвращаем список ID и Name пользователей
         */
        public function getUsersList() : array {
            $stmt = $this -> pdo -> query("SELECT ID, Name FROM User_data ORDER BY Name ASC");
            return $stmt -> fetchAll(PDO::FETCH_ASSOC);
        }

        /**
         * Доступ к картам категорий.
         * @return array - Возвращаем список
         */
        public function getCategoriesMap() : array {
            return $this -> getCategoryNames();
        }
    }

    $admin = new AdminPanel($pdo);

    $action = $_GET['action'] ?? '';

    if ($action === 'addAdminForUser' && !empty($_POST['userRegName'])) {
        $admin -> addAdmin($_POST['userRegName']);
    }

    if ($action === 'addGoods' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $admin -> addGoods($_POST);
    }

    if ($action === 'editGoods' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
        $admin -> editGoods();
    }

    $categoryNames = $admin -> getCategoriesMap();

    $editCategoryId = -1;
    $editGoodsList = [];
    $editSku = '';
    $editGood = null;

    if ($action === 'editGoods') {
        // что выбрано сейчас (из GET или POST – чтобы не ломаться после сохранения)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $editCategoryId = isset($_POST['category']) ? (int)$_POST['category'] : -1;
            $editSku = $_POST['sku'] ?? '';
        }
        else {
            $editCategoryId = isset($_GET['category']) ? (int)$_GET['category'] : -1;
            $editSku = $_GET['sku'] ?? '';
        }

        if ($editCategoryId >= 0 && array_key_exists($editCategoryId, $categoryNames)) {
            $editGoodsList = $admin -> getGoodsByCategory($editCategoryId);
        }

        if ($editSku !== '') {
            $editGood = $admin -> getGoodBySku($editSku);
        }
    }

    $usersList = [];

    $basketItems = [
        'open' => [],
        'orders' => [],
        'cleared' => [],
    ];

    $selectedUserId = 0;

    $statusNames = [
        'open' => 'Открыта',
        'orders' => 'Оформлен',
        'cleared' => 'Отменён',
    ];

    if ($action === 'viewOrders') {
    $usersList = $admin -> getUsersList();

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
            .hidden-page {
            display: none;
            padding: 20px;
            background: #eef;
            border: 1px solid #99c;
            margin-top: 20px;
            }

            #showPage:checked ~ .hidden-page {
                display: block;
            }
        </style>
    </head>
    <body>
        <main class="basic">
            <?php if ($action === ''): ?>
                <!-- Главное меню админ-панели -->
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
                <h1>Выдать админку</h1>
                <form method="post" action="?action=addAdminForUser">
                    <section>
                        <h5>Имя зарегистрированного пользователя.<br></h5>
                        <input type="text" id="user_reg_name" name="userRegName" maxlength="30" placeholder="Имя" required value="">
                    </section>
                    <section>
                         <button type="submit">
                            Выдать админ роль
                        </button>
                    </section>
                </form>
            <?php elseif ($action === 'addGoods'): ?>
                <h1>Добавить товар</h1>
                <?php
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
                            <input type="number" step="0.01" name="price" required>
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
                <h1>Редактировать товар</h1>

                <!-- Выбор категории -->
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
                    <!-- Выбор товара в категории -->
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
                    <p>
                        В этой категории пока нет товаров.
                    </p>
                <?php endif; ?>

                <?php if ($editGood): ?>
                    <!-- Форма редактирования конкретного товара -->
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
                            <button type="submit" name="save" disabled>
                                Сохранить
                            </button>
                            <a href="AdminPanel.php?action=editGoods&category=<?= $editCategoryId ?>&sku=<?= htmlspecialchars($editGood['SKU'], ENT_QUOTES, 'UTF-8') ?>">
                                Сбросить изменения
                            </a>
                        </section>
                    </form>

                    <script>
                    // кнопка "Сохранить" активна только если что-то изменилось
                    document.addEventListener('DOMContentLoaded', function () {
                        var form = document.getElementById('editGoodForm');
                        if (!form) return;

                        var inputs = form.querySelectorAll('input[data-original], select[data-original]');
                        var saveBtn = form.querySelector('button[name="save"]');

                        function checkChanged() {
                            var changed = false;
                            inputs.forEach(function (el) {
                                if (el.value !== el.getAttribute('data-original')) {
                                    changed = true;
                                }
                            });
                            saveBtn.disabled = !changed;
                        }

                        inputs.forEach(function (el) {
                            el.addEventListener('input', checkChanged);
                            el.addEventListener('change', checkChanged);
                        });

                        checkChanged();
                    });
                    </script>

                <?php endif; ?>

            <?php elseif ($action === 'viewOrders'): ?>
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
                        <p>
                            Для этого пользователя заказов пока нет.
                        </p>
                    <?php else: ?>
                        <?php
                            // Чтобы заголовки были по-русски и в нужном порядке
                            $orderStatusList = ['open', 'orders', 'cleared'];
                        ?>
                        <?php foreach ($orderStatusList as $status): ?>
                            <?php if (!empty($basketItems[$status])): ?>
                                <h2>
                                    <?= $statusNames[$status] ?>
                                </h2>

                                <?php foreach ($basketItems[$status] as $basket): ?>
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