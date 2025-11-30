<?php
    ini_set('display_errors', 1);
    error_reporting(E_ALL);

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    require __DIR__ . '/db.php';
    require_once __DIR__ . '/Basket.php';
    require_once __DIR__ . '/Goods.php';
    require_once __DIR__ . '/History.php';

    class Util {
        public ?string $userName = null;
        public ?string $userPassword = null;
        public float $userMoney = 0.0;

        private PDO $pdo;

        public function __construct(PDO $pdo) {
            $this -> pdo = $pdo;
        }

        public function saveUser() : void {
            $method = $_SERVER['REQUEST_METHOD'] ?? '';

            if ($method !== 'POST') {
                echo "Ошибка: запрос не POST (REQUEST_METHOD: {$method})";
                return;
            }

            // Проверяем наличие полей
            if (!isset($_POST['username'], $_POST['userpass'], $_POST['usermoney'])) {
                echo "Ошибка: все поля пустые.";
                return;
            }

            $this -> userName = trim((string)$_POST['username']);
            $this -> userPassword = trim((string)$_POST['userpass']);
            $this -> userMoney = floatval(str_replace(',', '.', trim((string)$_POST['usermoney']))); // Пометка для себя, floatval 
                                                                                                                                              // - переделывает значение на значение с плавующей
                                                                                                                                              // точкой str_replace - Заменяет "," на "."
            
            try {
                $sql = 'SELECT * FROM `User_data` WHERE `Name` = :name LIMIT 1';
                $stmt = $this -> pdo -> prepare($sql);
                $stmt->execute([':name' => $this->userName]);
                $exists = $stmt -> fetch(PDO::FETCH_ASSOC);

                if ($exists !== false && $exists !== null) {
                    echo "Ошибка: имя уже занято.";
                    return;
                }
            }
            catch (PDOException $e) {
                echo '';
            }


            if ($this -> userName === '' || $this -> userPassword === '') {
                echo "Ошибка: имя или пароль пустые.";
                return;
            }

            // Сохраняем в БД
            $res = $this -> saveSQL( $this -> userName, $this -> userPassword, $this -> userMoney);

            if ($res === false) {
                echo "Ошибка: при сохранении в БД";
            } 
            else {
                echo "Успех: пользователь сохранён";
            }
        }

        public function loadUser() : void {
            if (!isset($_POST['logusername'], $_POST['loguserpass'])) {
                echo "Ошибка: все поля пустые.";
                return;
            }

            $this -> userName = trim((string)$_POST['logusername']);
            $this -> userPassword = trim((string)$_POST['loguserpass']);

            if ($this -> userName === '' || $this -> userPassword === '') {
                echo "Ошибка: имя или пароль пустые.";
                return;
            }
            try {
                $sql = 'SELECT * FROM `User_data` WHERE `Name` = :name LIMIT 1';
                $stmt = $this -> pdo -> prepare($sql);
                $stmt -> execute([':name' => $this->userName]);
                $user = $stmt -> fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    echo "Пользователь не найден";
                    return;
                }

                if (password_verify($this -> userPassword, $user['Password'])) {
                    session_regenerate_id(true);

                    $_SESSION['user'] = [
                        'ID' => (int)$user['ID'],
                        'name' => $user['Name'] ?? $user['name'] ?? '',
                        'money' => isset($user['Money']) ? (float)$user['Money'] : (float)($user['money'] ?? 0.0)    
                    ];

                    // Для совместимости (чтобы это не значило)
                    $_SESSION['User_ID'] = (int)$user['ID'];

                    header('Location: PA.php');
                    exit;
                }
                else{
                    echo 'неверный логин или пароль'; exit;
                }
            } 
            catch (PDOException $e) {
                echo "Ошибка БД: " . $e->getMessage();
            }
        }

        public function getUserData() : array {
            $id = $_GET["ID"] ?? null;

            $sql = 'SELECT `ID`,`Name`,`Money` FROM `User_data` WHERE `ID` = :id LIMIT 1';
            $stmt = $this -> pdo -> prepare($sql);
            $stmt -> execute([':id' => $id]);
            $user = $stmt -> fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return ['ID' => 0, 'Name' => '', 'Money' => 0.0];
            }

            return [
                'ID' => (int)$user['ID'],
                'Name' => $user['Name'],
                'Password' => '',
                'Money' => (float)$user['Money'],
            ];
        }

        public function saveSQL($name, $password, $money) : void {
            try {
                $oldErrMode = $this -> pdo -> getAttribute(PDO::ATTR_ERRMODE);

                if ($oldErrMode !== PDO::ERRMODE_EXCEPTION) {
                    $this -> pdo -> setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                }

                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $sqlInsert = "INSERT INTO User_data (Name, Password, Money) VALUES (:name, :password, :money)";
                $stmt = $this -> pdo -> prepare($sqlInsert);
                $stmt -> execute([':name' => $name, ':password' => $passwordHash, ':money' => $money]);

                $newId = (int)$this -> pdo->lastInsertId();

                if ($oldErrMode !== PDO::ERRMODE_EXCEPTION) {
                    $this -> pdo -> setAttribute(PDO::ATTR_ERRMODE, $oldErrMode);
                }
            }
            catch (Exception $e) {
                error_log("SQL error: " . $e->getMessage());
            }
        }

        public function loadSQL($name, $password) : void {
            try {
                $sql = 'SELECT `Password` FROM `User_data` WHERE `Name` = :name LIMIT 1';
                $stmt = $this -> pdo -> prepare($sql);
                $stmt->execute([':name' => $name]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (empty($user)) {
                    echo "Польщователь не найден";
                    return;
                }
                $hashFromDb = $user['Password'];

                if (password_verify($password, $hashFromDb)) {
                    header('Location: http://localhost/Shop/Shop.php');
                } 
                else {
                    echo 'Неверное имя или пароль.';
                }
            }
            catch (Exception $e) {
                error_log("SQL error: ". $e->getMessage());
            }
        }

        public function removSQL() : void {
            try {
                $stmt = $this -> pdo -> prepare('UPDATE Goods1 SET category = :new WHERE category = :old');
                $stmt -> execute([':new' => "0", ':old' => "BREAD"]);
                $stmt -> execute([':new' => "1", ':old' => "DRINKS"]);
                $stmt -> execute([':new' => "2", ':old' => "GROCERY"]);
                $stmt -> execute([':new' => "3", ':old' => "MEAT"]);
                $stmt -> execute([':new' => "4", ':old' => "MILK"]);
                $stmt -> execute([':new' => "5", ':old' => "VEGETABLES"]);
                echo $stmt->rowCount() . " строк(и) изменено.";
            }
            catch (Exception $e) {
                error_log("". $e->getMessage());
            }
        }

        public function logout() : void {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params['path'], $params['domain'],
                    $params['secure'], $params['httponly']
                );
            }
            session_destroy();
            header('Location: Entry.php');
            exit;
        }

        public function addGoodsForBasket(String $SKU) : array {
            // Надёжно получить userID из сессии (поддерживает оба варианта)
            $userID = 0;
            if (session_status() === PHP_SESSION_NONE) session_start();

            if (isset($_SESSION['User']) && is_array($_SESSION['User']) && !empty($_SESSION['User']['ID'])) {
                $userID = (int)$_SESSION['User']['ID'];
            } 
            elseif (isset($_SESSION['User_ID'])) {
                $userID = (int)$_SESSION['User_ID'];
            }

            // для отладки: если всё ещё 0 — вывести содержимое сессии и прервать
            if ($userID <= 0) {
                // 1) Покажем содержимое сессии прямо в ответе (временная отладка).
                echo '<pre>DEBUG: $_SESSION = ' . htmlspecialchars(print_r($_SESSION, true)) . "</pre>\n";
                // 2) Покажем cookie (важно проверить, есть ли PHPSESSID)
                echo '<pre>DEBUG: $_COOKIE = ' . htmlspecialchars(print_r($_COOKIE, true)) . "</pre>\n";
                // 3) Подсказка о том, что проверить в браузере
                echo "<p>Проверь в DevTools → Application → Cookies → localhost, наличие PHPSESSID и совпадает ли id с session_id()</p>";
                // 4) Остановим выполнение, чтобы не получить исключение и не ломать страницу
                exit;
            }

            // Получаем user id
            // $userID = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

            $stmt = $this -> pdo -> prepare("SELECT productName, price, quantity, category FROM Goods1 WHERE SKU = :sku LIMIT 1");
            $stmt->execute([':sku' => $SKU]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new RuntimeException("Product with SKU {$SKU} not found");
            }

            return [
                'userID' => $userID,
                'product' => [
                    'SKU' => $SKU,
                    'productName' => $row['productName'],
                    'price' => (float)$row['price'],
                    'quantity' => (int)$row['quantity'],
                    'category' => isset($row['category']) ? $row['category'] : ''
                ]
            ];
        }

        public function buyBasketUser(int $userID) : void {
            try {
                $this -> pdo -> beginTransaction();

                $stmt = $this -> pdo -> prepare("SELECT ID, Goods, `Status` FROM Basket_User WHERE User_ID = :uid AND Status = 'open' ORDER BY ID DESC LIMIT 1");
                $stmt -> execute([':uid' => $userID]);
                $row = $stmt -> fetch(PDO::FETCH_ASSOC);

                $stmt1 = $this -> pdo -> prepare("SELECT Money FROM User_data WHERE ID = :uid");
                $stmt1 -> execute([":uid"=> $userID]);
                $row1 = $stmt1 -> fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $this -> pdo -> rollBack();
                    return;
                }

                $grandTotal = 0;

                if ($row["Status"] === 'open') {
                    $items_json = json_decode($row['Goods'], true);
                    $keySKU = array_keys($items_json);

                    $placeholders = rtrim(str_repeat('?,', count($keySKU)), ',');
                    $stmtG = $this -> pdo -> prepare("SELECT `SKU`, `price` FROM Goods1 WHERE `SKU` IN ($placeholders)");
                    $stmtG -> execute($keySKU);
                    $rowG = $stmtG -> fetchAll(PDO::FETCH_ASSOC);

                    foreach ($rowG as $item) {
                        $grandTotal += $item['price'] * $items_json[$item['SKU']];
                    }
                }

                if ($row1['Money'] < $grandTotal) { 
                    echo 'Не удалось оформить заказ, недостаточно средств';
                    $this -> pdo -> rollBack();
                    exit;
                }
                else {
                    $newRow1 = $row1['Money'] - $grandTotal;

                    $upd = $this -> pdo -> prepare("UPDATE Basket_User SET Status = 'orders' WHERE ID = :id");
                    $upd -> execute([':id' => $row['ID']]);

                    $udp = $this -> pdo -> prepare("UPDATE User_data SET Money = :money WHERE ID = :id");
                    $udp -> execute([':money' => (float)$newRow1, ':id' => $userID]);

                    $this -> pdo -> commit();
                    $_SESSION['flash_success'] = 'Заказ успешно оформлен';
                }
            }
            catch (PDOException $e) {
                error_log('buyBasketUser error: ' . $e -> getMessage());
                $_SESSION['flash_error'] = 'Ошибка при оформлении заказа. Попробуйте позже.';
                return;
            }
        }

        public function clearBasket(int $userID) {
            try {
                $this -> pdo -> beginTransaction();

                $stmt = $this -> pdo -> prepare("SELECT ID FROM Basket_User WHERE User_ID = :uid AND Status = 'open' ORDER BY ID DESC LIMIT 1");
                $stmt -> execute([':uid' => $userID]);
                $row = $stmt -> fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $this -> pdo -> rollBack();
                    return;
                }

                $upd = $this -> pdo -> prepare("UPDATE Basket_User SET Status = 'cleared' WHERE ID = :id");
                $upd -> execute([':id' => $row['ID']]);
                $this -> pdo -> commit();
                $_SESSION['flash_success'] = 'Корзина успешно очищена';
            }
            catch (PDOException $e) {
                error_log('buyBasketUser error: ' . $e -> getMessage());
                $_SESSION['flash_error'] = 'Ошибка при очищении корзины. Попробуйте позже.';
                return;
            }
        }
    }

    global $pdo;
    $util = new Util($pdo);

    $action = $_REQUEST['action'] ?? '';

    $goodsProductName = $_REQUEST['productName'] ?? '';
    $goodsSKU = $_REQUEST['SKU'] ?? '';

    $userID = 0;

    if (isset($_SESSION['User']) && is_array($_SESSION['User']) && !empty($_SESSION['User']['ID'])) {
        $userID = (int)$_SESSION['User']['ID'];
    } 
    elseif (isset($_SESSION['User_ID'])) {
        $userID = (int)$_SESSION['User_ID'];
    }

    switch ($action) {
        case 'save_user':
            $util -> saveUser();
            break;

        case 'load_user':
            $util -> loadUser();
            break;

        case 'logout':
            $util -> logout();
            break;

        case 'minusproduct':
            $data = $util -> addGoodsForBasket($goodsSKU);

            $step = isset($_GET['step']) ? (int)$_GET['step'] : 1;

            $p = $data['product'];

            $basket = new Basket($pdo);
            $basket -> decreaseGoodsForUser($goodsSKU, (int)$data['userID'], $step);

            $category = trim((string)($p['category'] ?? ''));

            if ($category === '') {
                header('Location: Shop.php');
            } 
            else {
                header('Location: Shop.php?category=' . rawurlencode($category));
            }
            exit;
            break;

        case 'addproduct':
            $data = $util -> addGoodsForBasket($goodsSKU);

            $step = isset($_GET['step']) ? (int)$_GET['step'] : 1;

            // Создаём объект Goods
            $p = $data['product'];
            $goods = new Goods($p['SKU'], $p['productName'], $p['price'], $p['quantity']);

            // Передаём в Basket
            $basket = new Basket($pdo);
            $basket -> addGoodsForUser($goods, (int)$data['userID'], $step);

            // Редирект на страницу, где были товары (чтобы избежать повторной отправки при F5)
            $category = trim((string)($p['category'] ?? ''));

            if ($category === '') {
                header('Location: Shop.php');
            } 
            else {
                header('Location: Shop.php?category=' . rawurlencode($category) . '&success=' . $goodsSKU);
            }
            exit;
            break;

        case 'buy':
            $gt = isset($_GET['grandTotal']) ? (string)$_GET['grandTotal'] : '';
            $gt = str_replace(',', '.', $gt); // на всякий случай, если откуда-то придёт запятая
            $grandTotalFromGet = is_numeric($gt) ? (float)$gt : 0.0;

            // var_dump($gt, $grandTotalFromGet); exit;

            $data = $util -> buyBasketUser($userID, $grandTotalFromGet);

            header('Location: Basket.php');
            exit;
            break;

        case 'clear':
            $data = $util -> clearBasket($userID);

            header('Location: Basket.php');
            exit;
            break;

        default:
            // echo "Неизвестное действие: $action";
            break;
    }
?>